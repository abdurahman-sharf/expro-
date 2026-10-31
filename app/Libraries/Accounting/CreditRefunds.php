<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Paying a customer back the credit they hold, in cash, and being paid back the credit held with a
 * supplier. The credit is not spent on an invoice or a purchase but taken out through a treasury: for
 * a customer the journal debits their account and credits the treasury, for a supplier it debits the
 * treasury and credits their account. The refund says which credits it took the money from, oldest
 * first, so that what is left to spend is what the account still holds. It is never edited and never
 * deleted: a refund that is wrong is cancelled, which reverses its journal and gives the credit back.
 * A credit that was paid back cannot shrink or disappear until the refund is cancelled.
 */
final class CreditRefunds
{
    use RunsInTransaction;

    public const CUSTOMER        = 'customer';
    public const SUPPLIER        = 'supplier';
    public const CUSTOMER_SOURCE = 'customer_refund';
    public const SUPPLIER_SOURCE = 'supplier_refund';
    private const NOTE_MAX       = 500;
    private const REFERENCE      = 64;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PostingService $posting;
    private CashMovement $cash;
    private AuditLog $audit;

    public function __construct(private readonly string $party, ?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->posting  = new PostingService($this->db);
        $this->cash     = new CashMovement($this->db, $this->branches);
        $this->audit    = new AuditLog($this->db);
    }

    public static function source(string $party): string
    {
        return $party === self::CUSTOMER ? self::CUSTOMER_SOURCE : self::SUPPLIER_SOURCE;
    }

    /**
     * What a customer or a supplier holds in credit that has not been spent or paid back.
     *
     * @return numeric-string
     */
    public function available(int $partyId): string
    {
        return $this->position($partyId)->credit();
    }

    /**
     * Pays the credit back: the amount may not be more than the credit that is free on the date of the
     * refund, so a refund dated in the past takes only credit that existed then. Whoever pays a
     * customer takes money out of a treasury and needs its withdrawal scope; whoever is paid by a
     * supplier puts money in and needs its deposit scope. A form that carries a key and is sent twice
     * saves one refund and answers the second time with the first.
     *
     * @return int The refund.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function refund(int $partyId, string $amount, string $date, string $paymentType, ?int $treasuryId = null, string $reference = '', string $note = '', ?int $employeeId = null, ?string $requestKey = null): int
    {
        $this->guard->require($this->permission(), $employeeId);

        $amount     = $this->cash->amount($amount);
        $date       = SupplierPaymentDate::entered($date, null, (new AccountingSettings($this->db))->startDate());
        $type       = $this->cash->paymentType($paymentType);
        $requestKey = $this->cash->requestKey($requestKey);

        $this->assertParty($partyId);

        $place = $this->cash->place($type, $treasuryId, $this->customers() ? TreasuryScopes::WITHDRAW : TreasuryScopes::DEPOSIT, $employeeId);

        return $this->lock($partyId, fn (): int => $this->transactional(function () use ($partyId, $amount, $date, $paymentType, $place, $reference, $note, $employeeId, $requestKey): int {
            $earlier = $requestKey === null ? null : $this->byRequestKey($requestKey);

            if ($earlier !== null) {
                if ((int) $earlier->{$this->party . '_id'} !== $partyId || bccomp((string) $earlier->amount, $amount, SaleAmounts::SCALE) !== 0) {
                    throw new AccountingException(AccountingError::WriteFailed, 'The key of this form was already used for another refund.', ['refund_id' => (int) $earlier->refund_id]);
                }

                return (int) $earlier->refund_id;
            }

            $credits = array_values(array_filter($this->position($partyId)->credits, static fn (CreditSource $source): bool => $source->date <= $date));
            $free    = array_reduce($credits, static fn (string $sum, CreditSource $source): string => bcadd($sum, $source->available(), SaleAmounts::SCALE), '0.0000');

            if (bccomp($amount, $free, SaleAmounts::SCALE) > 0) {
                throw new AccountingException(AccountingError::RefundExceedsCredit, 'A refund cannot be more than the credit that is free on its date.', ['max' => bcadd($free, '0', 2)]);
            }

            $refundId = $this->insert($partyId, $place['branch'], $amount, $date, $paymentType, $place['treasury'], $reference, $note, $requestKey, $employeeId);

            $this->takeFrom($refundId, $credits, $amount);

            $account = $this->partyAccount($partyId);
            $lines   = $this->customers()
                ? [new JournalLineDraft($account, debit: $amount), new JournalLineDraft($place['account'], credit: $amount)]
                : [new JournalLineDraft($place['account'], debit: $amount), new JournalLineDraft($account, credit: $amount)];

            $this->posting->post(new JournalDraft(
                date: $date,
                lines: $lines,
                sourceType: self::source($this->party),
                sourceId: $refundId,
                origin: JournalOrigin::System,
                employeeId: $employeeId,
                description: ($this->customers() ? 'Cash refund to customer #' : 'Cash refund from supplier #') . $partyId,
                branchId: $place['branch'],
                entryType: JournalEntryType::General,
            ));

            $this->settle($partyId, $employeeId);
            $this->audit->write($this->party . '_refund', $refundId, 'created', $employeeId, JournalOrigin::User, [$this->party . '_id' => $partyId, 'amount' => $amount], $place['branch']);

            return $refundId;
        }));
    }

    /**
     * Cancels a refund: its journal is reversed and the credit it took is free again.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $refundId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require($this->permission(), $employeeId);

        $reason = $this->cash->reason($reason);
        $column = $this->party . '_id';
        $row    = $this->db->table($this->table())->select($column)->where(['company_id' => CompanyContext::id(), 'refund_id' => $refundId])->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::RefundNotFound, "Refund {$refundId} does not exist.", ['refund_id' => $refundId]);
        }

        $partyId = (int) $row->{$column};

        $this->lock($partyId, fn () => $this->transactional(function () use ($refundId, $partyId, $reason, $employeeId): void {
            $table  = $this->db->prefixTable($this->table());
            $refund = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE refund_id = ? FOR UPDATE", [$refundId]);

            if ($refund === null) {
                throw new AccountingException(AccountingError::RefundNotFound, "Refund {$refundId} does not exist.", ['refund_id' => $refundId]);
            }

            $branchId = (int) $refund->branch_id;

            $this->branches->requireBranch($branchId, $employeeId);

            if ($refund->status === 'cancelled') {
                throw new AccountingException(AccountingError::RefundAlreadyCancelled, 'The refund is already cancelled.', ['refund_id' => $refundId]);
            }

            $this->cash->reverse(self::source($this->party), $refundId, $branchId, $reason, $employeeId);

            $this->db->table($this->table())->where('refund_id', $refundId)->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->settle($partyId, $employeeId);
            $this->audit->write($this->party . '_refund', $refundId, 'cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason], $branchId);
        }));
    }

    /**
     * The refunds of a customer or a supplier, the newest first, with the treasury each went through
     * and the credits it was taken from.
     *
     * @return list<object>
     */
    public function forParty(int $partyId): array
    {
        $refunds = $this->db->table($this->table() . ' r')
            ->select('r.*, t.name AS treasury_name, b.code AS branch_code')
            ->join('treasuries t', 't.treasury_id = r.treasury_id', 'left')
            ->join('branches b', 'b.branch_id = r.branch_id')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.' . $this->party . '_id', $partyId)
            ->orderBy('r.refund_id', 'DESC')
            ->get()
            ->getResult();

        foreach ($refunds as $refund) {
            $refund->sources = $this->db->table($this->sources())->where('refund_id', $refund->refund_id)->orderBy('source_type', 'ASC')->orderBy('source_id', 'ASC')->get()->getResult();
        }

        return $refunds;
    }

    /**
     * The branch a refund belongs to, or null when there is no such one.
     */
    public function branchOf(int $refundId): ?int
    {
        $row = $this->db->table($this->table())->select('branch_id')->where(['company_id' => CompanyContext::id(), 'refund_id' => $refundId])->get()->getRow();

        return $row === null ? null : (int) $row->branch_id;
    }

    private function customers(): bool
    {
        return $this->party === self::CUSTOMER;
    }

    private function permission(): string
    {
        return $this->customers() ? AccountingGuard::RECEIVABLES_REFUND : AccountingGuard::PAYABLES_REFUND;
    }

    private function table(): string
    {
        return $this->party . '_credit_refunds';
    }

    private function sources(): string
    {
        return $this->party . '_credit_refund_sources';
    }

    private function position(int $partyId): CustomerPosition|SupplierPosition
    {
        return $this->customers() ? (new CustomerLedger($this->db))->position($partyId) : (new SupplierLedger($this->db))->position($partyId);
    }

    private function settle(int $partyId, ?int $employeeId): void
    {
        $this->customers() ? (new CreditSettlement($this->db))->settle($partyId, $employeeId) : (new SupplierSettlement($this->db))->settle($partyId, $employeeId);
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function lock(int $partyId, callable $work): mixed
    {
        return $this->customers() ? (new CustomerLock($this->db))->run($partyId, $work) : (new SupplierLock($this->db))->run($partyId, $work);
    }

    private function byRequestKey(string $key): ?object
    {
        return $this->db->table($this->table())->where(['company_id' => CompanyContext::id(), 'request_key' => $key])->get()->getRow();
    }

    /**
     * @param numeric-string $amount
     */
    private function insert(int $partyId, int $branchId, string $amount, string $date, string $paymentType, ?int $treasuryId, string $reference, string $note, ?string $requestKey, ?int $employeeId): int
    {
        $this->db->table($this->table())->insert([
            'company_id'         => CompanyContext::id(),
            'branch_id'          => $branchId,
            $this->party . '_id' => $partyId,
            'amount'             => $amount,
            'refund_date'        => $date,
            'payment_type'       => $paymentType,
            'treasury_id'        => $treasuryId,
            'reference_code'     => mb_substr(trim($reference), 0, self::REFERENCE),
            'note'               => mb_substr(trim($note), 0, self::NOTE_MAX),
            'request_key'        => $requestKey,
            'created_by'         => $employeeId,
        ]);

        return (int) $this->db->insertID();
    }

    /**
     * Takes the amount from the credits that are free, the oldest first, and writes down how much of
     * each it took.
     *
     * @param list<CreditSource> $credits
     * @param numeric-string     $amount
     */
    private function takeFrom(int $refundId, array $credits, string $amount): void
    {
        $left = $amount;

        foreach ($credits as $source) {
            if (bccomp($left, '0', SaleAmounts::SCALE) <= 0) {
                break;
            }

            $free = $source->available();

            if (bccomp($free, '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            $take = bccomp($left, $free, SaleAmounts::SCALE) < 0 ? $left : $free;

            $this->db->table($this->sources())->insert(['refund_id' => $refundId, 'source_type' => $source->type, 'source_id' => $source->sourceId, 'amount' => $take]);

            $left = bcsub($left, $take, SaleAmounts::SCALE);
        }
    }

    /**
     * @throws AccountingException
     */
    private function assertParty(int $partyId): void
    {
        $table = $this->customers() ? 'customers' : 'suppliers';

        if ($this->db->table($table)->where(['person_id' => $partyId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::RefundPartyMissing, "The {$this->party} {$partyId} does not exist.", [$this->party . '_id' => $partyId]);
        }
    }

    /**
     * @throws PostingException
     */
    private function partyAccount(int $partyId): int
    {
        $account = (new RoutingResolver($this->db))->accountFor($this->customers() ? RoutingKey::Customers : RoutingKey::Suppliers, $partyId);

        if ($account === null) {
            throw new PostingException(PostingError::EntityAccountMissing, "The {$this->party} has no account of their own yet.", ['entity_id' => $partyId]);
        }

        return $account;
    }
}
