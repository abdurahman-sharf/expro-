<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Money that is owed to or by a supplier without a purchase behind it: a payment made to the
 * supplier that belongs to no purchase, and what a supplier owed us, or we owed them, before the
 * books started. Each is a journal of its own against the account of the supplier. What the
 * supplier holds in credit is spent on their purchases, the oldest first, like any credit, and an
 * opening balance we owe the supplier is a purchase without lines that payments are made against.
 * None is edited: one that is wrong is cancelled, which reverses its journal.
 */
final class SupplierAdvances
{
    use RunsInTransaction;

    public const SOURCE       = 'supplier_advance';
    public const PAYMENT      = 'payment';
    public const OPENING      = 'opening';
    public const OPENING_TYPE = 'opening';
    public const WE_OWE       = 'we_owe';
    public const THEY_OWE     = 'they_owe';
    private const NOTE_MAX    = 500;
    private const REFERENCE   = 64;
    private const REASON_MAX  = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PostingService $posting;
    private JournalService $journals;
    private PeriodGuard $periods;
    private RoutingResolver $resolver;
    private SupplierLock $lock;
    private SupplierSettlement $settlement;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->guard      = $guard ?? new AccountingGuard();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->posting    = new PostingService($this->db);
        $this->journals   = new JournalService($this->db);
        $this->periods    = new PeriodGuard($this->db);
        $this->resolver   = new RoutingResolver($this->db);
        $this->lock       = new SupplierLock($this->db);
        $this->settlement = new SupplierSettlement($this->db);
        $this->audit      = new AuditLog($this->db);
    }

    /**
     * A payment to the supplier that belongs to no purchase: debits the account of the supplier
     * and credits the treasury it was paid from, or the account of the way it was paid.
     *
     * @return int The advance.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function pay(int $supplierId, string $amount, string $date, string $paymentType, ?int $treasuryId = null, string $reference = '', string $note = '', ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::PAYABLES_MANAGE, $employeeId);

        $amount = $this->amount($amount);
        $date   = $this->date($date);
        $type   = PaymentType::fromLabel($paymentType);

        if ($type === null || $type === PaymentType::Due || $type === PaymentType::Giftcard || $type === PaymentType::Rewards) {
            throw new AccountingException(AccountingError::AdvancePaymentUnknown, "The payment type {$paymentType} is not one a supplier is paid with.", ['payment_type' => $paymentType]);
        }

        $this->assertSupplier($supplierId);

        $treasury = $treasuryId !== null && $treasuryId > 0 && $type->usesTreasury() ? $this->treasury($treasuryId) : null;
        $scopes   = new TreasuryScopes($this->db, $this->branches);

        if ($treasury !== null) {
            $scopes->assertAllows((int) $treasury->treasury_id, TreasuryScopes::WITHDRAW, $employeeId);
        } else {
            $scopes->assertRoutedAllows($type, TreasuryScopes::WITHDRAW, $employeeId);
        }

        $branchId = $treasury === null ? $this->branches->defaultBranchId($employeeId) : (int) $treasury->branch_id;

        $this->branches->requireBranch($branchId, $employeeId);

        return $this->lock->run($supplierId, fn (): int => $this->transactional(function () use ($supplierId, $amount, $date, $paymentType, $type, $treasury, $branchId, $reference, $note, $employeeId): int {
            $advanceId = $this->insert($supplierId, $branchId, self::PAYMENT, $amount, $date, $paymentType, $treasury === null ? null : (int) $treasury->treasury_id, $reference, $note, $employeeId);
            $account   = $treasury === null ? $this->resolver->accountForPayment($type) : $this->resolver->accountFor(RoutingKey::Treasuries, (int) $treasury->treasury_id);

            if ($account === null) {
                throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$paymentType} has no account to be paid from.", ['payment_type' => $paymentType]);
            }

            $this->post($supplierId, $advanceId, $date, $branchId, $account, $amount, 'Payment to supplier #' . $supplierId . ' without a purchase', $employeeId);
            $this->settlement->settle($supplierId, $employeeId);
            $this->audit->write('supplier_advance', $advanceId, 'created', $employeeId, JournalOrigin::User, ['supplier_id' => $supplierId, 'kind' => self::PAYMENT, 'amount' => $amount], $branchId);

            return $advanceId;
        }));
    }

    /**
     * What the supplier owed us or we owed them before the books started. What we owe is a
     * purchase without lines (the id returned is the purchase); what they owe us is a credit held
     * with them (the id returned is the advance). Both are against the account of opening
     * balances, and are dated up to today: what is owed on a day to come would be in the balance
     * of the supplier and in none of the buckets of their aging.
     *
     * @param self::THEY_OWE|self::WE_OWE $side
     *
     * @return int The purchase or the advance.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function openingBalance(int $supplierId, string $side, string $amount, string $date, string $note = '', ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::PAYABLES_MANAGE, $employeeId);

        if ($side !== self::WE_OWE && $side !== self::THEY_OWE) {
            throw new AccountingException(AccountingError::AdvanceKindInvalid, 'An opening balance is either owed to the supplier or by them.');
        }

        $amount = $this->amount($amount);
        $date   = $this->date($date);

        if ($date > date('Y-m-d')) {
            throw new AccountingException(AccountingError::InvalidDate, "An opening balance is dated up to today, not {$date}.", ['date' => $date]);
        }

        $this->assertSupplier($supplierId);

        $branchId = $this->branches->defaultBranchId($employeeId);

        $this->branches->requireBranch($branchId, $employeeId);

        $equity = $this->resolver->accountFor(RoutingKey::OpeningBalance);

        if ($equity === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, 'An opening balance cannot be entered without the account of opening balances.', ['routing_key' => RoutingKey::OpeningBalance->value]);
        }

        return $this->lock->run($supplierId, fn (): int => $this->transactional(function () use ($supplierId, $side, $amount, $date, $note, $branchId, $equity, $employeeId): int {
            if ($side === self::THEY_OWE) {
                $advanceId = $this->insert($supplierId, $branchId, self::OPENING, $amount, $date, null, null, '', $note, $employeeId);

                $this->post($supplierId, $advanceId, $date, $branchId, $equity, $amount, 'Opening balance owed by supplier #' . $supplierId, $employeeId);
                $this->settlement->settle($supplierId, $employeeId);
                $this->audit->write('supplier_advance', $advanceId, 'created', $employeeId, JournalOrigin::User, ['supplier_id' => $supplierId, 'kind' => self::OPENING, 'amount' => $amount], $branchId);

                return $advanceId;
            }

            $this->db->table('receivings')->insert([
                'receiving_time' => $date . ' 00:00:00',
                'supplier_id'    => $supplierId,
                'employee_id'    => (int) $employeeId,
                'comment'        => mb_substr(trim($note), 0, self::NOTE_MAX),
                'receiving_type' => self::OPENING_TYPE,
                'branch_id'      => $branchId,
            ]);

            $receivingId = (int) $this->db->insertID();
            $supplier    = $this->supplierAccount($supplierId);

            $this->posting->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($equity, debit: $amount), new JournalLineDraft($supplier, credit: $amount)],
                sourceType: ReceivingPostingBuilder::SOURCE_PURCHASE,
                sourceId: $receivingId,
                origin: JournalOrigin::System,
                employeeId: $employeeId,
                description: 'Opening balance owed to supplier #' . $supplierId,
                branchId: $branchId,
                entryType: JournalEntryType::General,
            ));

            $this->settlement->settle($supplierId, $employeeId);
            $this->audit->write('receiving', $receivingId, 'opening_balance_created', $employeeId, JournalOrigin::User, ['supplier_id' => $supplierId, 'amount' => $amount], $branchId);

            return $receivingId;
        }));
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $advanceId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::PAYABLES_MANAGE, $employeeId);

        $reason = $this->reason($reason);
        $row    = $this->db->table('supplier_advances')->select('supplier_id')->where(['company_id' => CompanyContext::id(), 'advance_id' => $advanceId])->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::AdvanceNotFound, "Advance {$advanceId} does not exist.", ['advance_id' => $advanceId]);
        }

        $supplierId = (int) $row->supplier_id;

        $this->lock->run($supplierId, fn () => $this->transactional(function () use ($advanceId, $supplierId, $reason, $employeeId): void {
            $table   = $this->db->prefixTable('supplier_advances');
            $advance = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE advance_id = ? FOR UPDATE", [$advanceId]);

            if ($advance === null) {
                throw new AccountingException(AccountingError::AdvanceNotFound, "Advance {$advanceId} does not exist.", ['advance_id' => $advanceId]);
            }

            $branchId = (int) $advance->branch_id;

            $this->branches->requireBranch($branchId, $employeeId);

            if ($advance->status === 'cancelled') {
                throw new AccountingException(AccountingError::AdvanceAlreadyCancelled, 'The advance is already cancelled.', ['advance_id' => $advanceId]);
            }

            $this->reverse(self::SOURCE, $advanceId, $branchId, $reason, $employeeId);

            $this->db->table('supplier_advances')->where('advance_id', $advanceId)->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->settlement->settle($supplierId, $employeeId);
            $this->audit->write('supplier_advance', $advanceId, 'cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason], $branchId);
        }));
    }

    /**
     * Cancels an opening balance we owe a supplier: its journal is reversed, and what had been
     * spent on it from the credit of the supplier goes back to the credit.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancelOpening(int $receivingId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::PAYABLES_MANAGE, $employeeId);

        $reason  = $this->reason($reason);
        $opening = $this->db->table('receivings')->select('supplier_id, branch_id')->where(['receiving_id' => $receivingId, 'receiving_type' => self::OPENING_TYPE])->get()->getRow();

        if ($opening === null) {
            throw new AccountingException(AccountingError::OpeningNotFound, "Opening balance {$receivingId} does not exist.", ['receiving_id' => $receivingId]);
        }

        $supplierId = (int) $opening->supplier_id;
        $branchId   = (int) $opening->branch_id;

        $this->branches->requireBranch($branchId, $employeeId);

        $this->lock->run($supplierId, fn () => $this->transactional(function () use ($receivingId, $supplierId, $branchId, $reason, $employeeId): void {
            $this->reverse(ReceivingPostingBuilder::SOURCE_PURCHASE, $receivingId, $branchId, $reason, $employeeId);
            $this->settlement->settle($supplierId, $employeeId);
            $this->audit->write('receiving', $receivingId, 'opening_balance_cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason], $branchId);
        }));
    }

    /**
     * The advances of a supplier, the newest first, with the treasury each was paid from.
     *
     * @return list<object>
     */
    public function forSupplier(int $supplierId): array
    {
        return $this->db->table('supplier_advances a')
            ->select('a.*, t.name AS treasury_name, b.code AS branch_code')
            ->join('treasuries t', 't.treasury_id = a.treasury_id', 'left')
            ->join('branches b', 'b.branch_id = a.branch_id')
            ->where('a.company_id', CompanyContext::id())
            ->where('a.supplier_id', $supplierId)
            ->orderBy('a.advance_id', 'DESC')
            ->get()
            ->getResult();
    }

    /**
     * The branch an advance or an opening balance belongs to, or null when there is no such one.
     */
    public function branchOf(string $what, int $id): ?int
    {
        $row = $what === self::SOURCE
            ? $this->db->table('supplier_advances')->select('branch_id')->where('advance_id', $id)->get()->getRow()
            : $this->db->table('receivings')->select('branch_id')->where(['receiving_id' => $id, 'receiving_type' => self::OPENING_TYPE])->get()->getRow();

        return $row === null ? null : (int) $row->branch_id;
    }

    /**
     * The journal of an advance or of an opening balance the supplier owes: debits the account of
     * the supplier and credits the account the money came from, or the one of opening balances.
     *
     * @param numeric-string $amount
     */
    private function post(int $supplierId, int $advanceId, string $date, int $branchId, int $creditedAccount, string $amount, string $description, ?int $employeeId): void
    {
        $this->posting->post(new JournalDraft(
            date: $date,
            lines: [new JournalLineDraft($this->supplierAccount($supplierId), debit: $amount), new JournalLineDraft($creditedAccount, credit: $amount)],
            sourceType: self::SOURCE,
            sourceId: $advanceId,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: $description,
            branchId: $branchId,
            entryType: JournalEntryType::General,
        ));
    }

    /**
     * @throws AccountingException|PostingException
     */
    private function reverse(string $source, int $sourceId, int $branchId, string $reason, ?int $employeeId): void
    {
        $journal = $this->db->table('accounting_journals')
            ->select('journal_id, journal_date')
            ->where('source_type', $source)
            ->where('source_id', $sourceId)
            ->where('entry_type', JournalEntryType::General->value)
            ->where('status', JournalStatus::Posted->value)
            ->orderBy('revision', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();

        if ($journal === null) {
            throw new AccountingException(AccountingError::AdvanceAlreadyCancelled, 'There is nothing posted to cancel.', ['source_id' => $sourceId]);
        }

        (new TreasuryScopes($this->db, $this->branches))->assertMayReverse((int) $journal->journal_id, $employeeId);

        $cutoff = $this->periods->effectiveCutoff($branchId, true);
        $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

        $this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);
    }

    /**
     * @param numeric-string $amount
     */
    private function insert(int $supplierId, int $branchId, string $kind, string $amount, string $date, ?string $paymentType, ?int $treasuryId, string $reference, string $note, ?int $employeeId): int
    {
        $this->db->table('supplier_advances')->insert([
            'company_id'     => CompanyContext::id(),
            'branch_id'      => $branchId,
            'supplier_id'    => $supplierId,
            'kind'           => $kind,
            'amount'         => $amount,
            'advance_date'   => $date,
            'payment_type'   => $paymentType,
            'treasury_id'    => $treasuryId,
            'reference_code' => mb_substr(trim($reference), 0, self::REFERENCE),
            'note'           => mb_substr(trim($note), 0, self::NOTE_MAX),
            'created_by'     => $employeeId,
        ]);

        $advanceId = (int) $this->db->insertID();

        if ($kind === self::PAYMENT) {
            (new DocumentNumbering($this->db))->assign(DocumentType::SupplierPayment, 'supplier_advances', $advanceId, $branchId);
        }

        return $advanceId;
    }

    /**
     * @throws AccountingException
     */
    private function assertSupplier(int $supplierId): void
    {
        if ($this->db->table('suppliers')->where(['person_id' => $supplierId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::AdvanceSupplierMissing, "Supplier {$supplierId} does not exist.", ['supplier_id' => $supplierId]);
        }
    }

    /**
     * @throws PostingException
     */
    private function supplierAccount(int $supplierId): int
    {
        $account = $this->resolver->accountFor(RoutingKey::Suppliers, $supplierId);

        if ($account === null) {
            throw new PostingException(PostingError::EntityAccountMissing, 'The supplier has no account of their own yet.', ['entity_id' => $supplierId]);
        }

        return $account;
    }

    /**
     * An active treasury.
     *
     * @throws AccountingException
     */
    private function treasury(int $treasuryId): object
    {
        $treasury = (new TreasuryService($this->db))->find($treasuryId);

        if ($treasury === null) {
            throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$treasuryId} does not exist.", ['treasury_id' => $treasuryId]);
        }

        if ((int) $treasury->active === 0) {
            throw new AccountingException(AccountingError::TreasuryInactive, 'A payment cannot be made from a deactivated treasury.', ['treasury_id' => $treasuryId]);
        }

        return $treasury;
    }

    /**
     * @return numeric-string
     *
     * @throws AccountingException
     */
    private function amount(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $value) !== 1 || bccomp($value, '0', SaleAmounts::SCALE) <= 0) {
            throw new AccountingException(AccountingError::AdvanceAmountInvalid, 'The amount must be more than zero.');
        }

        return bcadd($value, '0', SaleAmounts::SCALE);
    }

    /**
     * @throws AccountingException
     */
    private function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        return $date->format('Y-m-d');
    }

    /**
     * @throws AccountingException
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        return $reason;
    }
}
