<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * What a customer owed us, or held with us, before the books began. It is a document of its own
 * with one journal against the account of the customer and the account of opening balances, dated
 * on the day entered. What the customer owes is an invoice without lines that credit is spent on,
 * the oldest first, and what they hold is a credit that is spent on their invoices. A customer has
 * one opening balance at a time, and it is never edited: a change reverses its journal and posts a
 * new revision, and every revision that was reversed stays in the books.
 *
 * It works only where the setting for it is on, which is off until someone turns it on. Taking one
 * back is always possible.
 */
final class CustomerOpening
{
    use RunsInTransaction;

    public const SOURCE      = 'customer_opening';
    public const OWES_US     = 'owes_us';
    public const WE_OWE      = 'we_owe';
    private const NOTE_MAX   = 500;
    private const REASON_MAX = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AccountingSettings $settings;
    private PostingService $posting;
    private JournalService $journals;
    private PeriodGuard $periods;
    private RoutingResolver $resolver;
    private CustomerLock $lock;
    private CreditSettlement $settlement;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->guard      = $guard ?? new AccountingGuard();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->settings   = new AccountingSettings($this->db);
        $this->posting    = new PostingService($this->db);
        $this->journals   = new JournalService($this->db);
        $this->periods    = new PeriodGuard($this->db);
        $this->resolver   = new RoutingResolver($this->db);
        $this->lock       = new CustomerLock($this->db);
        $this->settlement = new CreditSettlement($this->db);
        $this->audit      = new AuditLog($this->db);
    }

    /**
     * Gives a customer who has none an opening balance, dated up to today: what is owed on a day to
     * come would be in the balance of the customer and in none of the buckets of their aging.
     *
     * @param self::OWES_US|self::WE_OWE $side
     *
     * @return int The opening balance.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function create(int $customerId, string $side, string $amount, string $date, string $note = '', ?int $employeeId = null, ?int $branchId = null): int
    {
        $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);
        $this->assertOn();

        [$side, $amount, $date] = $this->entered($side, $amount, $date);

        $this->assertCustomer($customerId);

        $branchId = $this->branch($branchId, $employeeId);

        return $this->lock->run($customerId, fn (): int => $this->transactional(function () use ($customerId, $side, $amount, $date, $note, $branchId, $employeeId): int {
            if ($this->standingOf($customerId) !== null) {
                throw new AccountingException(AccountingError::CustomerOpeningExists, 'The customer already has an opening balance. Change it, or take it back first.', ['customer_id' => $customerId]);
            }

            return $this->write($customerId, $branchId, $side, $amount, $date, $note, null, $employeeId);
        }));
    }

    /**
     * Changes the opening balance that stands: its journal is reversed, with a reason, and a new
     * revision is posted in its place. The revision that was reversed stays.
     *
     * @param self::OWES_US|self::WE_OWE $side
     *
     * @return int The new revision.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function revise(int $openingId, string $side, string $amount, string $date, string $note, string $reason, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);
        $this->assertOn();

        $reason                 = $this->reason($reason);
        [$side, $amount, $date] = $this->entered($side, $amount, $date);
        $opening                = $this->found($openingId);

        $this->branches->requireBranch((int) $opening->branch_id, $employeeId);

        $customerId = (int) $opening->customer_id;

        return $this->lock->run($customerId, fn (): int => $this->transactional(function () use ($openingId, $customerId, $side, $amount, $date, $note, $reason, $employeeId): int {
            $current = $this->standing($openingId);

            $this->takeBack($current, $reason, $employeeId);

            return $this->write($customerId, (int) $current->branch_id, $side, $amount, $date, $note, $openingId, $employeeId);
        }));
    }

    /**
     * Takes the opening balance back, with a reason: its journal is reversed and what had been
     * spent on it is given back. Nothing is deleted.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $openingId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);

        $reason  = $this->reason($reason);
        $opening = $this->found($openingId);

        $this->branches->requireBranch((int) $opening->branch_id, $employeeId);

        $customerId = (int) $opening->customer_id;

        $this->lock->run($customerId, fn () => $this->transactional(function () use ($openingId, $customerId, $reason, $employeeId): void {
            $current = $this->standing($openingId);

            $this->takeBack($current, $reason, $employeeId);
            $this->settlement->settle($customerId, $employeeId);
            $this->audit->write('customer_opening', $openingId, 'customer_opening_cancelled', $employeeId, $this->origin($employeeId), ['customer_id' => $customerId, 'revision' => (int) $current->revision, 'reason' => $reason], (int) $current->branch_id);
        }));
    }

    /**
     * The opening balance that stands for a customer, or null when they have none.
     */
    public function standingFor(int $customerId): ?object
    {
        return $this->db->table('customer_openings')->where(['customer_id' => $customerId, 'status' => 'posted'])->get()->getRow();
    }

    /**
     * Every revision of the opening balance of a customer, the newest first, standing or reversed.
     *
     * @return list<object>
     */
    public function history(int $customerId): array
    {
        return $this->db->table('customer_openings o')
            ->select('o.*, b.code AS branch_code')
            ->join('branches b', 'b.branch_id = o.branch_id')
            ->where('o.company_id', CompanyContext::id())
            ->where('o.customer_id', $customerId)
            ->orderBy('o.revision', 'DESC')
            ->get()
            ->getResult();
    }

    public function find(int $openingId): ?object
    {
        return $this->db->table('customer_openings')->where(['company_id' => CompanyContext::id(), 'opening_id' => $openingId])->get()->getRow();
    }

    /**
     * The branch an opening balance belongs to, or null when there is no such one.
     */
    public function branchOf(int $openingId): ?int
    {
        $row = $this->db->table('customer_openings')->select('branch_id')->where('opening_id', $openingId)->get()->getRow();

        return $row === null ? null : (int) $row->branch_id;
    }

    /**
     * @param numeric-string $amount
     *
     * @throws AccountingException|PostingException
     */
    private function write(int $customerId, int $branchId, string $side, string $amount, string $date, string $note, ?int $supersedes, ?int $employeeId): int
    {
        $equity   = $this->resolver->accountFor(RoutingKey::OpeningBalance);
        $customer = $this->resolver->accountFor(RoutingKey::Customers, $customerId);

        if ($equity === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, 'An opening balance cannot be entered without the account of opening balances.', ['routing_key' => RoutingKey::OpeningBalance->value]);
        }

        if ($customer === null) {
            throw new PostingException(PostingError::EntityAccountMissing, 'The customer has no account of their own yet.', ['entity_id' => $customerId]);
        }

        $revision = $this->nextRevision($customerId);

        $this->db->table('customer_openings')->insert([
            'company_id'    => CompanyContext::id(),
            'branch_id'     => $branchId,
            'customer_id'   => $customerId,
            'revision'      => $revision,
            'side'          => $side,
            'amount'        => $amount,
            'opening_date'  => $date,
            'note'          => mb_substr(trim($note), 0, self::NOTE_MAX),
            'supersedes_id' => $supersedes,
            'created_by'    => $employeeId,
        ]);

        $openingId = (int) $this->db->insertID();
        $owed      = $side === self::OWES_US;
        $result    = $this->posting->post(new JournalDraft(
            date: $date,
            lines: $owed
                ? [new JournalLineDraft($customer, debit: $amount), new JournalLineDraft($equity, credit: $amount)]
                : [new JournalLineDraft($equity, debit: $amount), new JournalLineDraft($customer, credit: $amount)],
            sourceType: self::SOURCE,
            sourceId: $customerId,
            revision: $revision,
            origin: $this->origin($employeeId),
            employeeId: $employeeId,
            description: ($owed ? 'Opening balance owed by customer #' : 'Opening balance owed to customer #') . $customerId,
            branchId: $branchId,
            entryType: JournalEntryType::General,
        ));

        if (! $result->created) {
            throw new AccountingException(AccountingError::CustomerOpeningExists, 'The journal of this opening balance is already posted.', ['customer_id' => $customerId]);
        }

        $this->settlement->settle($customerId, $employeeId);
        $this->audit->write('customer_opening', $openingId, $supersedes === null ? 'customer_opening_created' : 'customer_opening_revised', $employeeId, $this->origin($employeeId), [
            'customer_id' => $customerId,
            'revision'    => $revision,
            'side'        => $side,
            'amount'      => $amount,
            'date'        => $date,
            ...($supersedes === null ? [] : ['supersedes' => $supersedes]),
        ], $branchId);

        return $openingId;
    }

    /**
     * Reverses the journal of the revision that stands and marks it reversed. The journal is
     * reversed on its own day, or on today when that day is in a closed period.
     *
     * @throws AccountingException|PostingException
     */
    private function takeBack(object $opening, string $reason, ?int $employeeId): void
    {
        $journal = $this->db->table('accounting_journals')
            ->select('journal_id, journal_date')
            ->where(['source_type' => self::SOURCE, 'source_id' => (int) $opening->customer_id, 'entry_type' => JournalEntryType::General->value, 'revision' => (int) $opening->revision, 'status' => JournalStatus::Posted->value])
            ->get()
            ->getRow();

        if ($journal === null) {
            throw new AccountingException(AccountingError::CustomerOpeningReversed, 'There is nothing posted to take back.', ['opening_id' => (int) $opening->opening_id]);
        }

        $cutoff = $this->periods->effectiveCutoff((int) $opening->branch_id, true);
        $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

        $this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);

        $this->db->table('customer_openings')->where('opening_id', (int) $opening->opening_id)->update([
            'status'         => 'reversed',
            'reversed_by'    => $employeeId,
            'reversed_at'    => date('Y-m-d H:i:s'),
            'reverse_reason' => $reason,
        ]);
    }

    /**
     * The opening balance as it stands now, read under a lock.
     *
     * @throws AccountingException
     */
    private function standing(int $openingId): object
    {
        $table   = $this->db->prefixTable('customer_openings');
        $opening = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE opening_id = ? FOR UPDATE", [$openingId]);

        if ($opening === null) {
            throw new AccountingException(AccountingError::CustomerOpeningNotFound, "Opening balance {$openingId} does not exist.", ['opening_id' => $openingId]);
        }

        if ($opening->status !== 'posted') {
            throw new AccountingException(AccountingError::CustomerOpeningReversed, 'This opening balance is already taken back.', ['opening_id' => $openingId]);
        }

        return $opening;
    }

    private function standingOf(int $customerId): ?object
    {
        $table = $this->db->prefixTable('customer_openings');

        return LockedRead::row($this->db, "SELECT * FROM {$table} WHERE customer_id = ? AND status = 'posted' FOR UPDATE", [$customerId]);
    }

    private function nextRevision(int $customerId): int
    {
        $row = $this->db->table('customer_openings')->selectMax('revision', 'latest')->where('customer_id', $customerId)->get()->getRow();

        return (int) ($row->latest ?? 0) + 1;
    }

    /**
     * @throws AccountingException
     */
    private function found(int $openingId): object
    {
        $row = $this->db->table('customer_openings')->where(['company_id' => CompanyContext::id(), 'opening_id' => $openingId])->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::CustomerOpeningNotFound, "Opening balance {$openingId} does not exist.", ['opening_id' => $openingId]);
        }

        return $row;
    }

    /**
     * @throws AccountingException
     */
    private function assertOn(): void
    {
        if (! $this->settings->customerOpeningEnabled()) {
            throw new AccountingException(AccountingError::CustomerOpeningOff, 'Opening balances of customers are not turned on.');
        }
    }

    /**
     * @throws AccountingException
     */
    private function assertCustomer(int $customerId): void
    {
        if ($this->db->table('customers')->where(['person_id' => $customerId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::CustomerOpeningCustomerMissing, "Customer {$customerId} does not exist.", ['customer_id' => $customerId]);
        }
    }

    /**
     * @throws BranchException
     */
    private function branch(?int $requested, ?int $employeeId): int
    {
        $branchId = $requested !== null && $requested > 0 ? $requested : $this->branches->defaultBranchId($employeeId);
        $branch   = $this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->get()->getRow();

        if ($branch === null) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);

        if ((int) $branch->active === 0) {
            throw new BranchException(BranchError::Inactive, "Branch {$branchId} is inactive.", ['branch_id' => $branchId]);
        }

        return $branchId;
    }

    /**
     * @return array{0: string, 1: numeric-string, 2: string}
     *
     * @throws AccountingException
     */
    private function entered(string $side, string $amount, string $date): array
    {
        if ($side !== self::OWES_US && $side !== self::WE_OWE) {
            throw new AccountingException(AccountingError::CustomerOpeningSideInvalid, 'An opening balance is owed either by the customer or to them.');
        }

        $amount = trim($amount);

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $amount) !== 1 || bccomp($amount, '0', SaleAmounts::SCALE) <= 0) {
            throw new AccountingException(AccountingError::AdvanceAmountInvalid, 'The amount must be more than zero.');
        }

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));

        if ($day === false || $day->format('Y-m-d') !== trim($date)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        $date = $day->format('Y-m-d');

        if ($date > date('Y-m-d')) {
            throw new AccountingException(AccountingError::InvalidDate, "An opening balance is dated up to today, not {$date}.", ['date' => $date]);
        }

        return [$side, bcadd($amount, '0', SaleAmounts::SCALE), $date];
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

    private function origin(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
