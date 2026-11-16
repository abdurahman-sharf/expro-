<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Posts what a cash-up does to the books: the difference between the cash that was counted and the
 * cash that was expected, and nothing else, because the sales and the expenses have already put
 * everything else in the treasury. It runs inside the transaction of the cash-up, so the cash-up
 * and its journal stand or fall together, and a journal that must change is reversed and written
 * again as the next revision, never edited.
 *
 * A cash-up counts only once cash has been counted: while all four closed amounts are zero the
 * cash-up is still open, as its screen already treats it. A cash-up saved before the books followed
 * it has no branch and no journal, and is not given one by changing it: only the retroactive posting
 * does that. The cash-ups that are not in the books are listed by unposted().
 */
final class CashupPosting
{
    private const CLOSED_AMOUNTS = ['closed_amount_cash', 'closed_amount_due', 'closed_amount_card', 'closed_amount_check'];

    private BaseConnection $db;
    private AccountingSettings $settings;
    private OperationsHold $hold;
    private DocumentJournals $journals;
    private CashupPostingBuilder $builder;
    private CashupExpectation $expectation;
    private TreasuryService $treasuries;
    private BranchGuard $branches;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db          = $db ?? Database::connect();
        $this->branches    = $branches ?? new BranchGuard($this->db);
        $this->settings    = new AccountingSettings($this->db);
        $this->hold        = new OperationsHold($this->db);
        $this->journals    = new DocumentJournals($this->db, $this->branches);
        $this->builder     = new CashupPostingBuilder(new RoutingResolver($this->db));
        $this->expectation = new CashupExpectation($this->db);
        $this->treasuries  = new TreasuryService($this->db, null, $this->branches);
        $this->audit       = new AuditLog($this->db);
    }

    /**
     * Whether the books follow a cash-up closed on that day: accounting is on and the day is on or
     * after the day accounting starts.
     */
    public function follows(string $date): bool
    {
        if (! $this->settings->isEnabled()) {
            return false;
        }

        $start = $this->settings->startDate();

        return $start === null || substr($date, 0, 10) >= $start;
    }

    /**
     * The cash a drawer is expected to hold, for the branch an employee works in by default, which
     * is the branch of a new cash-up. The cash-up screen shows it before the cash is counted.
     *
     * @param numeric-string $openAmount
     * @param numeric-string $transferAmount
     *
     * @return numeric-string
     */
    public function expectedFor(?int $employeeId, string $openDate, string $closeDate, string $openAmount, string $transferAmount): string
    {
        return $this->expectation->expected($this->branches->defaultBranchId($employeeId), $openDate, $closeDate, $openAmount, $transferAmount);
    }

    /**
     * What a cash-up that is about to be saved needs before it is written: the branch and the
     * treasury it is stamped with, and, once cash has been counted, the cash that was expected and
     * the difference. A new cash-up the books follow, or one that has been stamped with a branch by
     * the books before, is stamped; any other is saved as it always was.
     *
     * @param array<string, mixed> $data   The columns of the cash-up.
     * @param object|null          $stored The row as it is now, locked, or null for a new cash-up.
     *
     * @return array{data: array<string, mixed>, post: bool, branch_id: ?int}
     *
     * @throws AccountingException|BranchException
     */
    public function plan(array $data, ?object $stored, ?int $actorId): array
    {
        unset($data['branch_id'], $data['treasury_id'], $data['expected_amount_cash'], $data['cash_difference']);

        $isNew = $stored === null;

        if (! $isNew && $stored->branch_id === null) {
            return ['data' => $data, 'post' => false, 'branch_id' => null];
        }

        $closeDate = substr((string) ($data['close_date'] ?? $stored->close_date ?? date('Y-m-d')), 0, 10);
        $deleted   = (bool) ($data['deleted'] ?? ($stored->deleted ?? 0));

        if ($isNew && ($deleted || ! $this->follows($closeDate))) {
            return ['data' => $data, 'post' => false, 'branch_id' => null];
        }

        if (! $isNew && ($deleted || ! $this->follows($closeDate))) {
            $this->branches->requireBranch((int) $stored->branch_id, $actorId);

            return ['data' => $data, 'post' => true, 'branch_id' => (int) $stored->branch_id];
        }

        $branchId = $isNew ? $this->branches->defaultBranchId($actorId) : (int) $stored->branch_id;

        $this->branches->requireBranch($branchId, $actorId);

        $treasuryId              = $isNew ? $this->treasuries->stampFor($actorId, $branchId) : ($stored->treasury_id === null ? null : (int) $stored->treasury_id);
        [$expected, $difference] = $this->isClosed($data, $stored) ? $this->counted($data, $stored, $branchId) : [null, null];

        return [
            'data'      => array_merge($data, ['branch_id' => $branchId, 'treasury_id' => $treasuryId, 'expected_amount_cash' => $expected, 'cash_difference' => $difference]),
            'post'      => true,
            'branch_id' => $branchId,
        ];
    }

    /**
     * The cash a cash-up was expected to hold in a branch and the difference the count made, from the
     * columns given and, for what they leave out, the row as stored.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: numeric-string, 1: numeric-string}
     */
    public function counted(array $data, ?object $stored, int $branchId): array
    {
        $counted  = $this->money($data['closed_amount_cash'] ?? $stored->closed_amount_cash ?? '0');
        $expected = $this->expectation->expected(
            $branchId,
            (string) ($data['open_date'] ?? $stored->open_date),
            (string) ($data['close_date'] ?? $stored->close_date),
            $this->money($data['open_amount_cash'] ?? $stored->open_amount_cash ?? '0'),
            $this->money($data['transfer_amount_cash'] ?? $stored->transfer_amount_cash ?? '0'),
        );

        return [$expected, bcsub($counted, $expected, SaleAmounts::SCALE)];
    }

    /**
     * Whether cash has been counted on a stored cash-up: at least one of its closed amounts is not zero.
     */
    public function isCounted(object $stored): bool
    {
        return $this->isClosed([], $stored);
    }

    /**
     * Brings the journal of a saved cash-up in line with the cash-up. A cash-up the books follow
     * that has a difference is posted, or has its journal reversed and posted again if the
     * difference changed; one that was deleted, has no difference now, or was moved out of the
     * period the books follow has its journal only reversed. A cash-up the books never followed has
     * no branch, and stays out.
     *
     * @throws AccountingException|PostingException
     */
    public function sync(int $cashupId, ?int $actorId): JournalChange
    {
        $cashup = $this->document($cashupId);

        if ($cashup === null) {
            return JournalChange::None;
        }

        $wanted = ! $cashup->deleted && $this->follows($cashup->date);

        if ($wanted) {
            $this->hold->assertOpen();
        }

        $revision = $this->journals->nextRevision(CashupPostingBuilder::SOURCE, $cashupId);
        $draft    = $wanted ? $this->builder->difference($cashup, $revision, $actorId) : null;
        $change   = $this->journals->sync(CashupPostingBuilder::SOURCE, $cashupId, $draft, $actorId, 'Cash-up #' . $cashupId . ($cashup->deleted ? ' deleted' : ' changed'));

        $this->record($cashup, $change, $draft?->revision, $actorId);

        return $change;
    }

    /**
     * Takes back the journal of a cash-up that is being deleted, after checking that the employee
     * works in its branch, whether or not it has a journal. A cash-up the books never followed has
     * no branch and no journal, and nothing happens.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $cashupId, ?int $actorId): JournalChange
    {
        $cashup = $this->document($cashupId);

        if ($cashup === null) {
            return JournalChange::None;
        }

        $this->branches->requireBranch($cashup->branchId, $actorId);

        $active = $this->journals->active(CashupPostingBuilder::SOURCE, $cashupId);

        if ($active === null) {
            return JournalChange::None;
        }

        $change = $this->journals->sync(CashupPostingBuilder::SOURCE, $cashupId, null, $actorId, 'Cash-up #' . $cashupId . ' deleted');

        $this->audit->write('cashup', $cashupId, 'cancelled', $actorId, JournalOrigin::User, ['revision' => (int) $active->revision], (int) $active->branch_id);

        return $change;
    }

    public function document(int $cashupId): ?CashupDocument
    {
        $row = $this->db->table('cash_up')
            ->select('cashup_id, close_date, branch_id, treasury_id, cash_difference, deleted')
            ->where('cashup_id', $cashupId)
            ->get()
            ->getRow();

        if ($row === null || $row->branch_id === null) {
            return null;
        }

        return new CashupDocument(
            cashupId: (int) $row->cashup_id,
            date: substr((string) $row->close_date, 0, 10),
            difference: $row->cash_difference === null ? null : bcadd((string) $row->cash_difference, '0', SaleAmounts::SCALE),
            treasuryId: $row->treasury_id === null ? null : (int) $row->treasury_id,
            branchId: (int) $row->branch_id,
            deleted: (int) $row->deleted === 1,
        );
    }

    /**
     * The cash-ups the books never followed: the ones saved before the books followed cash-ups,
     * dated before accounting starts, or saved while accounting was off. A cash-up without a branch
     * is one of the default branch.
     *
     * @param list<int>|null $branchIds The branches the reader may see, null for every branch.
     *
     * @return list<object>
     */
    public function unposted(?array $branchIds = null): array
    {
        return $this->unpostedBuilder($branchIds)
            ->select('c.cashup_id, c.open_date, c.close_date, c.closed_amount_cash, c.closed_amount_total')
            ->orderBy('c.close_date', 'DESC')
            ->orderBy('c.cashup_id', 'DESC')
            ->get()
            ->getResult();
    }

    /**
     * @param list<int>|null $branchIds
     */
    public function unpostedCount(?array $branchIds = null): int
    {
        return $this->unpostedBuilder($branchIds)->countAllResults();
    }

    /**
     * What the books said about the cash-up that was just refused. It must run after the
     * transaction of the cash-up has rolled back, or the row would go with it.
     */
    public function recordFailure(?int $cashupId, PostingException $failure, ?int $actorId = null, ?int $branchId = null): void
    {
        (new PostingFailureLog($this->db))->recordUnbuilt(CashupPostingBuilder::SOURCE, $cashupId, 1, JournalEntryType::General, $failure, $actorId, $branchId);
    }

    /**
     * The journals of a cash-up, oldest first.
     *
     * @return list<object>
     */
    public function journalsOf(int $cashupId): array
    {
        return $this->db->table('accounting_journals')
            ->select('journal_id, number, journal_date, status, revision, total_debit, reverses_journal_id')
            ->where('source_type', CashupPostingBuilder::SOURCE)
            ->where('source_id', $cashupId)
            ->where('status !=', JournalStatus::Preview->value)
            ->orderBy('journal_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function unpostedBuilder(?array $branchIds): BaseBuilder
    {
        $default = $this->branches->companyDefaultBranchId();
        $builder = $this->db->table('cash_up c')->where('c.deleted', 0)->where('c.branch_id IS NULL', null, false);

        if ($branchIds === null) {
            return $builder;
        }

        return in_array($default, $branchIds, true) ? $builder : $builder->where('1 = 0', null, false);
    }

    /**
     * Whether cash has been counted: at least one of the closed amounts is not zero.
     *
     * @param array<string, mixed> $data
     */
    private function isClosed(array $data, ?object $stored): bool
    {
        foreach (self::CLOSED_AMOUNTS as $column) {
            if (bccomp($this->money($data[$column] ?? $stored->{$column} ?? '0'), '0', SaleAmounts::SCALE) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return numeric-string
     */
    private function money(mixed $value): string
    {
        return is_numeric($value) ? number_format((float) $value, SaleAmounts::SCALE, '.', '') : '0.0000';
    }

    private function record(CashupDocument $cashup, JournalChange $change, ?int $revision, ?int $actorId): void
    {
        $action = match ($change) {
            JournalChange::Posted   => $revision !== null && $revision > 1 ? 'reposted' : 'posted',
            JournalChange::Replaced => 'reposted',
            JournalChange::Reversed => 'cancelled',
            default                 => null,
        };

        if ($action === null) {
            return;
        }

        $this->audit->write('cashup', $cashup->cashupId, $action, $actorId, JournalOrigin::User, [
            'difference'  => $cashup->difference,
            'treasury_id' => $cashup->treasuryId,
            'revision'    => $revision,
        ], $cashup->branchId);
    }
}
