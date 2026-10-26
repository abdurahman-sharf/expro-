<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Money moved from one treasury to another: a document of its own and a journal that debits the
 * treasury it goes into and credits the one it comes out of. A transfer stays within a branch,
 * because a journal belongs to one, and is taken back by reversing its journal, never by deleting.
 */
final class TreasuryTransfers
{
    use RunsInTransaction;

    public const SOURCE      = 'treasury_transfer';
    private const NOTE_MAX   = 500;
    private const REASON_MAX = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PostingService $posting;
    private JournalService $journals;
    private PeriodGuard $periods;
    private EntityAccountService $accounts;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->posting  = new PostingService($this->db);
        $this->journals = new JournalService($this->db);
        $this->periods  = new PeriodGuard($this->db);
        $this->accounts = new EntityAccountService($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function transfer(int $fromId, int $toId, string $amount, string $date, string $note = '', ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $amount = $this->amount($amount);
        $date   = $this->date($date);
        $note   = mb_substr(trim($note), 0, self::NOTE_MAX);

        if ($fromId === $toId) {
            throw new AccountingException(AccountingError::TransferSameTreasury, 'A transfer needs two different treasuries.');
        }

        return $this->transactional(function () use ($fromId, $toId, $amount, $date, $note, $employeeId): int {
            [$from, $to] = $this->lockBoth($fromId, $toId);

            foreach ([$from, $to] as $treasury) {
                if ((int) $treasury->active === 0) {
                    throw new AccountingException(AccountingError::TreasuryInactive, 'A transfer cannot touch a deactivated treasury.', ['treasury_id' => (int) $treasury->treasury_id]);
                }
            }

            if ((int) $from->branch_id !== (int) $to->branch_id) {
                throw new AccountingException(AccountingError::TransferCrossBranch, 'The two treasuries are in different branches.', ['from_branch' => (int) $from->branch_id, 'to_branch' => (int) $to->branch_id]);
            }

            $branchId = (int) $from->branch_id;

            $this->branches->requireBranch($branchId, $employeeId);

            $scopes = new TreasuryScopes($this->db, $this->branches);

            $scopes->assertAllows($fromId, TreasuryScopes::WITHDRAW, $employeeId);
            $scopes->assertAllows($toId, TreasuryScopes::DEPOSIT, $employeeId);

            $fromAccount = $this->accounts->accountFor(EntityAccountService::TREASURY, $fromId);
            $toAccount   = $this->accounts->accountFor(EntityAccountService::TREASURY, $toId);

            if ($fromAccount === null || $toAccount === null) {
                throw new PostingException(PostingError::EntityAccountMissing, 'A treasury has no account of its own yet.', ['entity_id' => $fromAccount === null ? $fromId : $toId]);
            }

            $this->db->table('treasury_transfers')->insert([
                'company_id'       => CompanyContext::id(),
                'branch_id'        => $branchId,
                'from_treasury_id' => $fromId,
                'to_treasury_id'   => $toId,
                'amount'           => $amount,
                'transfer_date'    => $date,
                'note'             => $note,
                'created_by'       => $employeeId,
            ]);

            $transferId = (int) $this->db->insertID();

            $this->posting->post(new JournalDraft(
                date: $date,
                lines: [new JournalLineDraft($toAccount, debit: $amount), new JournalLineDraft($fromAccount, credit: $amount)],
                sourceType: self::SOURCE,
                sourceId: $transferId,
                origin: JournalOrigin::System,
                employeeId: $employeeId,
                description: 'Transfer #' . $transferId . ($note === '' ? '' : ': ' . $note),
                branchId: $branchId,
                entryType: JournalEntryType::General,
            ));

            $this->audit->write('treasury_transfer', $transferId, 'created', $employeeId, JournalOrigin::User, ['from' => $fromId, 'to' => $toId, 'amount' => $amount], $branchId);

            return $transferId;
        });
    }

    /**
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $transferId, string $reason, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::TREASURIES_MANAGE, $employeeId);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        $this->transactional(function () use ($transferId, $reason, $employeeId): void {
            $table    = $this->db->prefixTable('treasury_transfers');
            $transfer = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND transfer_id = ? FOR UPDATE", [CompanyContext::id(), $transferId]);

            if ($transfer === null) {
                throw new AccountingException(AccountingError::TransferNotFound, "Transfer {$transferId} does not exist.", ['transfer_id' => $transferId]);
            }

            $branchId = (int) $transfer->branch_id;

            $this->branches->requireBranch($branchId, $employeeId);

            if ($transfer->status === 'cancelled') {
                throw new AccountingException(AccountingError::TransferAlreadyCancelled, 'The transfer is already cancelled.', ['transfer_id' => $transferId]);
            }

            $journal = $this->db->table('accounting_journals')
                ->select('journal_id, journal_date')
                ->where('source_type', self::SOURCE)
                ->where('source_id', $transferId)
                ->where('status', JournalStatus::Posted->value)
                ->orderBy('revision', 'DESC')
                ->limit(1)
                ->get()
                ->getRow();

            if ($journal !== null) {
                (new TreasuryScopes($this->db, $this->branches))->assertMayReverse((int) $journal->journal_id, $employeeId);

                $cutoff = $this->periods->effectiveCutoff($branchId, true);
                $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

                $this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);
            }

            $this->db->table('treasury_transfers')->where('transfer_id', $transferId)->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->audit->write('treasury_transfer', $transferId, 'cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason], $branchId);
        });
    }

    public function find(int $transferId): ?object
    {
        return $this->query()->where('tt.transfer_id', $transferId)->get()->getRow();
    }

    /**
     * The transfers a reader may see, the newest first.
     *
     * @param list<int>|null $branchIds Null for every branch.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(?array $branchIds = null, int $limit = 25, int $offset = 0): array
    {
        if ($branchIds === []) {
            return ['total' => 0, 'rows' => []];
        }

        $count = $this->db->table('treasury_transfers tt')->where('tt.company_id', CompanyContext::id());

        if ($branchIds !== null) {
            $count->whereIn('tt.branch_id', $branchIds);
        }

        $total = $count->countAllResults();
        $rows  = $this->query();

        if ($branchIds !== null) {
            $rows->whereIn('tt.branch_id', $branchIds);
        }

        return ['total' => $total, 'rows' => $rows->orderBy('tt.transfer_id', 'DESC')->limit($limit, $offset)->get()->getResult()];
    }

    private function query(): BaseBuilder
    {
        return $this->db->table('treasury_transfers tt')
            ->select('tt.*, f.name AS from_name, t.name AS to_name, b.code AS branch_code')
            ->join('treasuries f', 'f.treasury_id = tt.from_treasury_id')
            ->join('treasuries t', 't.treasury_id = tt.to_treasury_id')
            ->join('branches b', 'b.branch_id = tt.branch_id')
            ->where('tt.company_id', CompanyContext::id());
    }

    /**
     * Both treasuries, locked in the order of their ids so that two transfers between the same
     * pair, in opposite directions, never wait for each other.
     *
     * @return array{object, object}
     *
     * @throws AccountingException
     */
    private function lockBoth(int $fromId, int $toId): array
    {
        $table = $this->db->prefixTable('treasuries');
        $rows  = LockedRead::rows($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND treasury_id IN (?, ?) ORDER BY treasury_id ASC FOR UPDATE", [CompanyContext::id(), $fromId, $toId]);
        $byId  = [];

        foreach ($rows as $row) {
            $byId[(int) $row->treasury_id] = $row;
        }

        foreach ([$fromId, $toId] as $id) {
            if (! isset($byId[$id])) {
                throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$id} does not exist.", ['treasury_id' => $id]);
            }
        }

        return [$byId[$fromId], $byId[$toId]];
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
            throw new AccountingException(AccountingError::TransferAmountInvalid, 'The amount of a transfer must be more than zero.');
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
}
