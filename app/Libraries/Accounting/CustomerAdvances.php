<?php

namespace App\Libraries\Accounting;

use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceRules;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Tax\AdvanceVat;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Money a customer pays with no invoice behind it. It is one journal of its own, a debit to the
 * treasury it was paid into and a credit to the account of the customer, so the customer holds it
 * as credit and the credit is spent on their invoices by the order of every credit, the oldest
 * invoice first, at once and without a journal. The reference system posts it to a liability of its
 * own and leaves the account of the customer untouched until somebody distributes it by hand; this
 * keeps the account, the invoices and the aging of the customer in agreement at every moment
 * instead (an assumption the owner can veto). An advance is never edited and never deleted: one that
 * is wrong is cancelled, which reverses its journal, and what had been spent from it goes back to the
 * customer's credit. Under the Saudi rules an advance carries VAT on what no invoice takes of it at once (AdvanceVat).
 */
final class CustomerAdvances
{
    use RunsInTransaction;

    public const SOURCE     = 'customer_advance';
    private const NOTE_MAX  = 500;
    private const REFERENCE = 64;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PostingService $posting;
    private CashMovement $cash;
    private CustomerLock $lock;
    private CreditSettlement $settlement;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->guard      = $guard ?? new AccountingGuard();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->posting    = new PostingService($this->db);
        $this->cash       = new CashMovement($this->db, $this->branches);
        $this->lock       = new CustomerLock($this->db);
        $this->settlement = new CreditSettlement($this->db);
        $this->audit      = new AuditLog($this->db);
    }

    /**
     * Money paid in by a customer with no invoice: debits the treasury it went into, or the account
     * of the way it was paid, and credits the account of the customer. It is posted on the date
     * entered, which is never after today. A form that carries a key and is sent twice saves one
     * advance and answers the second time with the first.
     *
     * @return int The advance.
     *
     * @throws AccountingException|BranchException|EInvoiceException|PostingException
     */
    public function receive(int $customerId, string $amount, string $date, string $paymentType, ?int $treasuryId = null, string $reference = '', string $note = '', ?int $employeeId = null, ?string $requestKey = null): int
    {
        $this->guard->require(AccountingGuard::RECEIVABLES_MANAGE, $employeeId);
        (new OperationsHold($this->db))->assertOpen();

        $amount     = $this->cash->amount($amount);
        $date       = SupplierPaymentDate::entered($date, null, (new AccountingSettings($this->db))->startDate());
        $type       = $this->cash->paymentType($paymentType);
        $requestKey = $this->cash->requestKey($requestKey);

        $this->assertCustomer($customerId);

        $place = $this->cash->place($type, $treasuryId, TreasuryScopes::DEPOSIT, $employeeId);

        return $this->lock->run($customerId, fn (): int => $this->transactional(function () use ($customerId, $amount, $date, $paymentType, $place, $reference, $note, $employeeId, $requestKey): int {
            $earlier = $requestKey === null ? null : $this->byRequestKey($requestKey);

            if ($earlier !== null) {
                if ((int) $earlier->customer_id !== $customerId || bccomp((string) $earlier->amount, $amount, SaleAmounts::SCALE) !== 0) {
                    throw new AccountingException(AccountingError::WriteFailed, 'The key of this form was already used for another advance.', ['advance_id' => (int) $earlier->advance_id]);
                }

                return (int) $earlier->advance_id;
            }

            $advanceId = $this->insert($customerId, $place['branch'], $amount, $date, $paymentType, $place['treasury'], $reference, $note, $requestKey, $employeeId);

            $this->posting->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($place['account'], debit: $amount), new JournalLineDraft($this->customerAccount($customerId), credit: $amount)],
                sourceType: self::SOURCE,
                sourceId: $advanceId,
                origin: JournalOrigin::System,
                employeeId: $employeeId,
                description: 'Advance payment from customer #' . $customerId,
                branchId: $place['branch'],
                entryType: JournalEntryType::General,
            ));

            $this->settlement->settle($customerId, $employeeId);
            if ((new AdvanceVat($this->db))->received($advanceId, $employeeId)) {
                (new EInvoiceService($this->db))->issueForReceivedAdvance($advanceId, $employeeId);
            }
            $this->audit->write('customer_advance', $advanceId, 'created', $employeeId, JournalOrigin::User, ['customer_id' => $customerId, 'amount' => $amount], $place['branch']);

            return $advanceId;
        }));
    }

    /**
     * Cancels an advance: its journal is reversed, what had been spent from it on invoices goes back
     * to the credit of the customer, and the invoices it paid are open again. An advance whose credit
     * was paid back in cash cannot be cancelled until that refund is.
     *
     * @throws AccountingException|BranchException|EInvoiceException|PostingException
     */
    public function cancel(int $advanceId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::RECEIVABLES_MANAGE, $employeeId);

        $reason = $this->cash->reason($reason);
        $row    = $this->db->table('customer_advances')->select('customer_id, created_by')->where(['company_id' => CompanyContext::id(), 'advance_id' => $advanceId])->get()->getRow();

        if ($row === null || ! (new RecordScope($this->db))->allows($employeeId, $row->created_by)) {
            throw new AccountingException(AccountingError::AdvanceNotFound, "Advance {$advanceId} does not exist.", ['advance_id' => $advanceId]);
        }

        $customerId = (int) $row->customer_id;

        $this->lock->run($customerId, fn () => $this->transactional(function () use ($advanceId, $customerId, $reason, $employeeId): void {
            $table   = $this->db->prefixTable('customer_advances');
            $advance = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE advance_id = ? FOR UPDATE", [$advanceId]);

            if ($advance === null) {
                throw new AccountingException(AccountingError::AdvanceNotFound, "Advance {$advanceId} does not exist.", ['advance_id' => $advanceId]);
            }

            $branchId = (int) $advance->branch_id;

            $this->branches->requireBranch($branchId, $employeeId);

            if ($advance->status === 'cancelled') {
                throw new AccountingException(AccountingError::AdvanceAlreadyCancelled, 'The advance is already cancelled.', ['advance_id' => $advanceId]);
            }

            $vat = new AdvanceVat($this->db);

            if ($vat->takenByIssuedInvoice($advanceId)) {
                throw new AccountingException(AccountingError::AdvanceTakenByIssuedInvoice, 'An invoice whose electronic document was issued takes the advance off what it asks; the advance stands.', ['advance_id' => $advanceId]);
            }

            $this->cash->reverse(self::SOURCE, $advanceId, $branchId, $reason, $employeeId);

            $this->db->table('customer_advances')->where('advance_id', $advanceId)->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->settlement->settle($customerId, $employeeId);

            $documents = new EInvoiceService($this->db);
            $returnId  = $vat->cancelled($advanceId, $reason, $employeeId, $documents->documentOf(EInvoiceService::SOURCE_ADVANCE, $advanceId) !== null);

            if ($returnId !== null) {
                $documents->issueForAdvanceReturn($returnId, EInvoiceRules::NOTE_REASONS[0], $employeeId);
            }

            $this->audit->write('customer_advance', $advanceId, 'cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason], $branchId);
        }));
    }

    /**
     * The advances of a customer, the newest first, with the treasury each went into and its number.
     *
     * @return list<object>
     */
    public function forCustomer(int $customerId): array
    {
        $advances = $this->db->table('customer_advances a')
            ->select('a.*, t.name AS treasury_name, b.code AS branch_code')
            ->join('treasuries t', 't.treasury_id = a.treasury_id', 'left')
            ->join('branches b', 'b.branch_id = a.branch_id')
            ->where('a.company_id', CompanyContext::id())
            ->where('a.customer_id', $customerId)
            ->orderBy('a.advance_id', 'DESC')
            ->get()
            ->getResult();

        $numbers = $this->numbersOf(array_map(static fn (object $advance): int => (int) $advance->advance_id, $advances));

        foreach ($advances as $advance) {
            $advance->number = $numbers[(int) $advance->advance_id];
        }

        return $advances;
    }

    /**
     * The number each advance goes by: the one of the sequence of customer payments, or its id while
     * that sequence is off.
     *
     * @param list<int> $advanceIds
     *
     * @return array<int, string>
     */
    public function numbersOf(array $advanceIds): array
    {
        $given   = (new DocumentNumbering($this->db))->numbers(DocumentType::CustomerPayment, 'customer_advances', $advanceIds);
        $numbers = [];

        foreach ($advanceIds as $advanceId) {
            $numbers[$advanceId] = $given[$advanceId] ?? (string) $advanceId;
        }

        return $numbers;
    }

    /**
     * The branch an advance belongs to, or null when there is no such one.
     */
    public function branchOf(int $advanceId, ?int $viewerId = null): ?int
    {
        $row = $this->db->table('customer_advances')->select('branch_id, created_by')->where(['company_id' => CompanyContext::id(), 'advance_id' => $advanceId])->get()->getRow();

        return $row === null || ! (new RecordScope($this->db))->allows($viewerId ?? RecordScope::sessionEmployee(), $row->created_by) ? null : (int) $row->branch_id;
    }

    private function byRequestKey(string $key): ?object
    {
        return $this->db->table('customer_advances')->where(['company_id' => CompanyContext::id(), 'request_key' => $key])->get()->getRow();
    }

    /**
     * @param numeric-string $amount
     */
    private function insert(int $customerId, int $branchId, string $amount, string $date, string $paymentType, ?int $treasuryId, string $reference, string $note, ?string $requestKey, ?int $employeeId): int
    {
        $this->db->table('customer_advances')->insert([
            'company_id'     => CompanyContext::id(),
            'branch_id'      => $branchId,
            'customer_id'    => $customerId,
            'amount'         => $amount,
            'advance_date'   => $date,
            'payment_type'   => $paymentType,
            'treasury_id'    => $treasuryId,
            'reference_code' => mb_substr(trim($reference), 0, self::REFERENCE),
            'note'           => mb_substr(trim($note), 0, self::NOTE_MAX),
            'request_key'    => $requestKey,
            'created_by'     => $employeeId,
        ]);

        $advanceId = (int) $this->db->insertID();

        (new DocumentNumbering($this->db))->assign(DocumentType::CustomerPayment, 'customer_advances', $advanceId, $branchId);

        return $advanceId;
    }

    /**
     * @throws AccountingException
     */
    private function assertCustomer(int $customerId): void
    {
        if ($this->db->table('customers')->where(['person_id' => $customerId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::AdvanceCustomerMissing, "Customer {$customerId} does not exist.", ['customer_id' => $customerId]);
        }
    }

    /**
     * @throws PostingException
     */
    private function customerAccount(int $customerId): int
    {
        $account = (new RoutingResolver($this->db))->accountFor(RoutingKey::Customers, $customerId);

        if ($account === null) {
            throw new PostingException(PostingError::EntityAccountMissing, 'The customer has no account of their own yet.', ['entity_id' => $customerId]);
        }

        return $account;
    }
}
