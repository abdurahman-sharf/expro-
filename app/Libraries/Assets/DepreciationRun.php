<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PeriodGuard;
use App\Libraries\Accounting\PeriodLocks;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureLog;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;

/**
 * The depreciation of a month. A run writes one journal for each branch, with a debit to the expense
 * account and a credit to the accumulated depreciation account of every asset of the branch that is due
 * depreciation in the month, and the line each asset got in it, which a key of the database holds to one
 * for an asset and a month that stands. It is made in two steps: the preview reads the books and writes
 * nothing, and the approval writes only what the preview showed, held to it by its fingerprint. A run
 * that stands is taken back whole, by reversing its journal on the day it has, so a period that is
 * closed is refused by name, and the asset may be run again. A run is never edited.
 *
 * A branch has one run for a month unless an asset is entered after the month was run: then a second run
 * of the month depreciates only the assets the first left out.
 */
final class DepreciationRun
{
    use RunsInTransaction;

    public const ENTITY      = 'asset_depreciation_run';
    private const SCALE      = 4;
    private const REASON_MAX = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PeriodLocks $locks;
    private PeriodGuard $periods;
    private PostingService $posting;
    private AssetPostingBuilder $builder;
    private AssetFigures $figures;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->locks    = new PeriodLocks($this->db);
        $this->periods  = new PeriodGuard($this->db);
        $this->posting  = new PostingService($this->db);
        $this->builder  = new AssetPostingBuilder(new RoutingResolver($this->db));
        $this->figures  = new AssetFigures($this->db, new DepreciationSchedule((new Currencies($this->db))->baseDecimals()));
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * What the run of a month would write for the branches of the run, and what stops it. Nothing is
     * written. A null branch is the whole company.
     *
     * @throws AccountingException|BranchException
     */
    public function preview(?int $branchId, string $month, ?int $employeeId): DepreciationPreview
    {
        $this->requireRun($branchId, $employeeId);

        return $this->plan($branchId, $this->period($month), false);
    }

    /**
     * Writes the journal of every branch of the run that has something due, the figures being those of
     * the preview whose fingerprint is given. Either all of them are written or none.
     *
     * @return list<int> The runs made, in the order of their branches.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function approve(?int $branchId, string $month, string $fingerprint, ?int $employeeId): array
    {
        $this->requireRun($branchId, $employeeId);

        $period = $this->period($month);

        try {
            return $this->transactional(function () use ($branchId, $period, $fingerprint, $employeeId): array {
                foreach ($this->scopeBranchIds($branchId) as $lockedBranchId) {
                    $this->locks->holdBranch($lockedBranchId);
                }

                $preview = $this->plan($branchId, $period, true);

                if ($preview->blockers !== []) {
                    throw $preview->blockers[0];
                }

                $ready = $preview->ready();

                if ($ready === []) {
                    throw new AccountingException(AccountingError::AssetNothingDue, 'No asset of these branches is due depreciation for the month.', ['name' => $period->label()]);
                }

                if (! hash_equals($preview->fingerprint, $fingerprint)) {
                    throw new AccountingException(AccountingError::AssetRunChanged, 'The books changed since the preview was made.');
                }

                return array_map(fn (DepreciationBranchPlan $plan): int => $this->write($plan, $period, $employeeId), $ready);
            });
        } catch (PostingException $failure) {
            (new PostingFailureLog($this->db))->recordUnbuilt(AssetSources::DEPRECIATION, null, 1, JournalEntryType::General, $failure, $employeeId, $branchId);

            throw $failure;
        }
    }

    /**
     * Takes a run back: its journal is mirrored on the day it was written and its lines are released,
     * so the assets may be run for the month again. A run whose assets were sold or written off since is
     * not taken back until the disposal is.
     *
     * @return int The journal of the reversal.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function reverse(int $runId, string $reason, ?int $employeeId): int
    {
        $this->guard->require(AccountingGuard::ASSETS_DEPRECIATE, $employeeId);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        $known = $this->db->table('asset_depreciation_runs')->where('company_id', CompanyContext::id())->where('run_id', $runId)->get()->getRow();

        if ($known === null) {
            throw new AccountingException(AccountingError::AssetRunNotFound, "Run {$runId} does not exist.", ['name' => (string) $runId]);
        }

        $this->branches->requireBranch((int) $known->branch_id, $employeeId);

        return $this->transactional(function () use ($runId, $reason, $employeeId, $known): int {
            $this->locks->holdBranch((int) $known->branch_id);

            $table = $this->db->prefixTable('asset_depreciation_runs');
            $run   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE run_id = ? FOR UPDATE", [$runId]);

            if ($run === null || $run->status !== 'active') {
                throw new AccountingException(AccountingError::AssetRunReversed, 'The run is taken back already.', ['name' => (string) $runId]);
            }

            $lines     = $this->db->table('asset_depreciations')->where(['run_id' => $runId, 'active_key' => 1])->get()->getResult();
            $disposals = $this->db->table('asset_operations o')->select('a.code')->join('assets a', 'a.asset_id = o.asset_id')->where('o.active_key', 1)->whereIn('o.asset_id', array_map(static fn (object $line): int => (int) $line->asset_id, $lines) ?: [0])->get()->getRow();

            if ($disposals !== null) {
                throw new AccountingException(AccountingError::AssetRunHasDisposal, 'An asset of the run was sold or written off since, so the disposal is taken back first.', ['name' => (string) $disposals->code]);
            }

            $reversal = (new JournalService($this->db, $this->guard, $this->posting, $this->branches))->reverseAutomatic((int) $run->journal_id, (string) $run->period_end, $reason, $employeeId);

            $this->db->table('asset_depreciations')->where('run_id', $runId)->update(['active_key' => null]);
            $this->db->table('asset_depreciation_runs')->where('run_id', $runId)->update([
                'status'              => 'reversed',
                'reversal_journal_id' => $reversal,
                'reversed_by'         => $employeeId,
                'reversed_at'         => new RawSql('CURRENT_TIMESTAMP'),
                'reverse_reason'      => $reason,
            ]);

            foreach ($lines as $line) {
                $this->restoreStatus((int) $line->asset_id);
            }

            $this->audit->write(self::ENTITY, $runId, 'asset_depreciation_reversed', $employeeId, $this->origin($employeeId), [
                'period'      => substr((string) $run->period_start, 0, 7),
                'journal_id'  => (int) $run->journal_id,
                'reversal_id' => $reversal,
                'total'       => $run->total,
                'reason'      => $reason,
            ], (int) $run->branch_id);

            return $reversal;
        });
    }

    /**
     * The runs of the branches the employee may see, the latest month first.
     *
     * @return list<object>
     */
    public function runs(?int $employeeId, ?string $month = null): array
    {
        $allowed = $this->branches->allowedBranchIds($employeeId);

        if ($allowed === []) {
            return [];
        }

        $builder = $this->db->table('asset_depreciation_runs r')
            ->select("r.*, b.code AS branch_code, b.name AS branch_name, TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS created_by_name, TRIM(CONCAT(rp.first_name, ' ', rp.last_name)) AS reversed_by_name", false)
            ->join('branches b', 'b.branch_id = r.branch_id')
            ->join('people cp', 'cp.person_id = r.created_by', 'left')
            ->join('people rp', 'rp.person_id = r.reversed_by', 'left')
            ->where('r.company_id', CompanyContext::id());

        if ($allowed !== null) {
            $builder->whereIn('r.branch_id', $allowed);
        }

        if ($month !== null && ($period = DepreciationPeriod::fromMonth($month)) !== null) {
            $builder->where('r.period_start', $period->start);
        }

        return $builder->orderBy('r.period_start', 'DESC')->orderBy('b.code', 'ASC')->orderBy('r.run_id', 'DESC')->get()->getResult();
    }

    /**
     * @throws AccountingException
     */
    public function find(int $runId, ?int $employeeId): object
    {
        foreach ($this->runs($employeeId) as $run) {
            if ((int) $run->run_id === $runId) {
                $run->lines = $this->db->table('asset_depreciations d')
                    ->select('d.*, a.code AS asset_code, a.name AS asset_name')
                    ->join('assets a', 'a.asset_id = d.asset_id')
                    ->where('d.run_id', $runId)
                    ->orderBy('a.code', 'ASC')
                    ->get()
                    ->getResult();

                return $run;
            }
        }

        throw new AccountingException(AccountingError::AssetRunNotFound, "Run {$runId} does not exist.", ['name' => (string) $runId]);
    }

    private function write(DepreciationBranchPlan $plan, DepreciationPeriod $period, ?int $employeeId): int
    {
        $table  = $this->db->prefixTable('asset_depreciation_runs');
        $latest = LockedRead::row($this->db, "SELECT COALESCE(MAX(run_number), 0) AS latest FROM {$table} WHERE company_id = ? AND branch_id = ? AND period_start = ? FOR UPDATE", [CompanyContext::id(), $plan->branchId, $period->start]);

        $inserted = $this->db->table('asset_depreciation_runs')->insert([
            'company_id'   => CompanyContext::id(),
            'branch_id'    => $plan->branchId,
            'period_start' => $period->start,
            'period_end'   => $period->end,
            'run_number'   => (int) $latest->latest + 1,
            'total'        => $plan->total,
            'line_count'   => count($plan->lines),
            'fingerprint'  => $this->branchFingerprint($plan),
            'status'       => 'active',
            'created_by'   => $employeeId,
        ]);

        if ($inserted === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The run could not be saved.');
        }

        $runId = (int) $this->db->insertID();

        foreach ($plan->lines as $line) {
            $this->db->table('asset_depreciations')->insert([
                'company_id'   => CompanyContext::id(),
                'branch_id'    => $plan->branchId,
                'asset_id'     => (int) $line->asset->asset_id,
                'run_id'       => $runId,
                'period_start' => $period->start,
                'amount'       => $line->amount,
                'units'        => $line->units,
                'active_key'   => 1,
            ]);
        }

        $result = $this->posting->post($this->builder->depreciation(
            $plan->branchId,
            $period,
            array_map(static fn (DepreciationLine $line): array => ['asset' => $line->asset, 'amount' => $line->amount], $plan->lines),
            $runId,
            $employeeId,
        ));

        if (! $result->created) {
            throw new AccountingException(AccountingError::WriteFailed, "A journal of the run {$runId} exists already, so nothing was depreciated.");
        }

        $this->db->table('asset_depreciation_runs')->where('run_id', $runId)->update(['journal_id' => $result->journalId]);

        foreach ($plan->lines as $line) {
            $this->markConsumed($line->asset);
        }

        $this->audit->write(self::ENTITY, $runId, 'asset_depreciation_run', $employeeId, $this->origin($employeeId), [
            'period'     => $period->label(),
            'journal_id' => $result->journalId,
            'total'      => $plan->total,
            'assets'     => count($plan->lines),
        ], $plan->branchId);

        return $runId;
    }

    /**
     * An asset that has depreciated by all it is to be is consumed.
     */
    private function markConsumed(object $asset): void
    {
        $terms = AssetFigures::termsOf($asset);
        $fresh = $this->db->table('assets')->where('asset_id', (int) $asset->asset_id)->get()->getRow();

        if ($fresh !== null && $fresh->status === AssetStatus::Active->value && bccomp($terms->base(), '0', self::SCALE) > 0 && bccomp($this->figures->accumulated($fresh), $terms->base(), self::SCALE) >= 0) {
            $this->db->table('assets')->where('asset_id', (int) $asset->asset_id)->update(['status' => AssetStatus::Consumed->value]);
        }
    }

    private function restoreStatus(int $assetId): void
    {
        $asset = $this->db->table('assets')->where('asset_id', $assetId)->get()->getRow();

        if ($asset === null || $asset->status !== AssetStatus::Consumed->value) {
            return;
        }

        $terms = AssetFigures::termsOf($asset);

        if (bccomp($this->figures->accumulated($asset), $terms->base(), self::SCALE) < 0) {
            $this->db->table('assets')->where('asset_id', $assetId)->update(['status' => AssetStatus::Active->value]);
        }
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function plan(?int $branchId, DepreciationPeriod $period, bool $locked): DepreciationPreview
    {
        $blockers = [];

        if (! $period->hasEnded(date('Y-m-d'))) {
            $blockers[] = new AccountingException(AccountingError::AssetPeriodNotEnded, 'A month is depreciated once it has ended.', ['name' => $period->label()]);
        }

        $plans = [];

        foreach ($this->scopeBranches($branchId) as $branch) {
            $plan = $this->branchPlan($branch, $period, $locked);

            if ($plan->blocker !== null && ! in_array($plan->blocker->getMessage(), array_map(static fn (AccountingException $blocker): string => $blocker->getMessage(), $blockers), true)) {
                $blockers[] = $plan->blocker;
            }

            $plans[] = $plan;
        }

        return new DepreciationPreview($period, $branchId === null, $plans, $blockers, $this->fingerprint($period, $plans));
    }

    private function branchPlan(object $branch, DepreciationPeriod $period, bool $locked): DepreciationBranchPlan
    {
        $blocker = $this->closedPeriod((int) $branch->branch_id, $period, $locked);
        $lines   = [];
        $total   = bcadd('0', '0', self::SCALE);

        foreach ($this->assets((int) $branch->branch_id, $period, $locked) as $asset) {
            $posted = $this->figures->posted((int) $asset->asset_id, $locked);

            if (isset($posted[$period->start])) {
                continue;
            }

            $amount = $this->figures->due($asset, $period, $posted);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                continue;
            }

            $lines[] = new DepreciationLine($asset, $amount, $this->figures->accumulated($asset, null, $locked), $asset->method === AssetMethod::Units->value ? $this->usage((int) $asset->asset_id, $period) : null, $this->figures->missed($asset, $period, $posted));
            $total   = bcadd($total, $amount, self::SCALE);
        }

        $status = $blocker !== null ? DepreciationBranchStatus::Blocked : ($lines === [] ? DepreciationBranchStatus::NothingDue : DepreciationBranchStatus::Ready);

        return new DepreciationBranchPlan((int) $branch->branch_id, (string) $branch->code, (string) $branch->name, (int) $branch->active === 1, $status, $lines, $total, $blocker);
    }

    /**
     * The assets of the branch that are in service and could be due depreciation in the month.
     *
     * @return list<object>
     */
    private function assets(int $branchId, DepreciationPeriod $period, bool $locked): array
    {
        $table = $this->db->prefixTable('assets');
        $sql   = "SELECT * FROM {$table} WHERE company_id = ? AND branch_id = ? AND deleted = 0 AND status = ? AND method <> ? AND in_service_date <= ? ORDER BY asset_id ASC" . ($locked ? ' LOCK IN SHARE MODE' : '');
        $args  = [CompanyContext::id(), $branchId, AssetStatus::Active->value, AssetMethod::None->value, $period->end];

        return $locked ? LockedRead::rows($this->db, $sql, $args) : $this->db->query($sql, $args)->getResult();
    }

    private function usage(int $assetId, DepreciationPeriod $period): ?string
    {
        $row = $this->db->table('asset_usage')->where(['asset_id' => $assetId, 'period_start' => $period->start])->get()->getRow();

        return $row === null ? null : bcadd((string) $row->units, '0', 2);
    }

    private function closedPeriod(int $branchId, DepreciationPeriod $period, bool $locked): ?AccountingException
    {
        $cutoff = $this->periods->effectiveCutoff($branchId, $locked);

        if ($cutoff === null || $period->end > $cutoff['date']) {
            return null;
        }

        $company = $this->db->table('companies')->select('name')->where('company_id', CompanyContext::id())->get()->getRow();

        return new AccountingException(AccountingError::AssetPeriodClosed, "The month {$period->label()} is in a period closed until {$cutoff['date']}.", [
            'name'       => $period->label(),
            'date'       => $period->end,
            'until'      => $cutoff['date'],
            'scope_name' => $cutoff['branch_code'] ?? (string) ($company->name ?? ''),
        ]);
    }

    /**
     * @param list<DepreciationBranchPlan> $plans
     */
    private function fingerprint(DepreciationPeriod $period, array $plans): string
    {
        return hash('sha256', (string) json_encode([
            $period->start,
            array_map(fn (DepreciationBranchPlan $plan): array => [$plan->branchId, $plan->status->value, $this->branchFingerprint($plan)], $plans),
        ]));
    }

    private function branchFingerprint(DepreciationBranchPlan $plan): string
    {
        return hash('sha256', (string) json_encode(array_map(static fn (DepreciationLine $line): array => [
            (int) $line->asset->asset_id,
            $line->amount,
            (int) $line->asset->expense_account_id,
            (int) $line->asset->accumulated_account_id,
            $line->asset->cost_center_id === null ? null : (int) $line->asset->cost_center_id,
        ], $plan->lines)));
    }

    /**
     * @throws AccountingException
     */
    private function period(string $month): DepreciationPeriod
    {
        return DepreciationPeriod::fromMonth($month) ?? throw new AccountingException(AccountingError::AssetPeriodInvalid, 'The month is written YYYY-MM.', ['name' => $month]);
    }

    /**
     * A run for the company needs every branch, a run for a branch needs that branch.
     *
     * @throws AccountingException|BranchException
     */
    private function requireRun(?int $branchId, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::ASSETS_DEPRECIATE, $employeeId);

        if ($branchId === null) {
            $this->branches->requireCompanyScope($employeeId);

            return;
        }

        if ($this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->countAllResults() === 0) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);
    }

    /**
     * @return list<object> The branches of the run in the order of their numbers.
     */
    private function scopeBranches(?int $branchId): array
    {
        $builder = $this->db->table('branches')->select('branch_id, code, name, active')->where('company_id', CompanyContext::id());

        if ($branchId !== null) {
            $builder->where('branch_id', $branchId);
        }

        return $builder->orderBy('branch_id', 'ASC')->get()->getResult();
    }

    /**
     * @return list<int>
     */
    private function scopeBranchIds(?int $branchId): array
    {
        return array_map(static fn (object $branch): int => (int) $branch->branch_id, $this->scopeBranches($branchId));
    }

    private function origin(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
