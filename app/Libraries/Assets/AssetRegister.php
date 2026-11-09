<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\DocumentJournals;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PeriodLocks;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureLog;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The register of fixed assets. Entering an asset can post its journal (bought from a treasury or owed,
 * or brought in as an opening balance with the depreciation it already had), and an asset that the
 * books hold already is entered without one. A journal that no longer matches the asset is reversed and
 * posted again as its next revision, never edited, and once a depreciation or a disposal stands the terms
 * of the asset are locked: the runs are taken back first. What does not touch a balance (the name, the
 * category, the units, the custody) changes whenever it is wanted. Nothing that has a journal is deleted
 * while a depreciation or a disposal stands, and an asset that was sold or written off is never deleted.
 */
final class AssetRegister
{
    use RunsInTransaction;

    public const ENTITY = 'asset';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private DocumentJournals $journals;
    private AssetPostingBuilder $builder;
    private AssetFigures $figures;
    private AssetInput $input;
    private AccountingSettings $settings;
    private AuditLog $audit;
    private DocumentNumbering $numbering;
    private PeriodLocks $locks;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->guard     = $guard ?? new AccountingGuard();
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->journals  = new DocumentJournals($this->db, $this->branches);
        $routing         = new RoutingResolver($this->db);
        $schedule        = new DepreciationSchedule((new Currencies($this->db))->baseDecimals());
        $this->builder   = new AssetPostingBuilder($routing);
        $this->figures   = new AssetFigures($this->db, $schedule);
        $this->settings  = new AccountingSettings($this->db);
        $this->input     = new AssetInput($this->db, $schedule, $this->settings, $routing);
        $this->audit     = new AuditLog($this->db);
        $this->numbering = new DocumentNumbering($this->db);
        $this->locks     = new PeriodLocks($this->db);
    }

    /**
     * The code a new asset is offered: the next of its numbering when that is on, else the next
     * whole number, six digits as the form of the reference system shows it.
     */
    public function suggestCode(): string
    {
        $next = $this->numbering->peek(DocumentType::FixedAsset);

        if ($next !== null) {
            return $next;
        }

        $table = $this->db->prefixTable('assets');
        $top   = $this->db->query("SELECT MAX(CAST(code AS UNSIGNED)) AS top FROM {$table} WHERE company_id = ? AND deleted = 0 AND code REGEXP '^[0-9]{1,9}\$'", [CompanyContext::id()])->getRow();

        return str_pad((string) ((int) ($top->top ?? 0) + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * The asset with its units, what it has depreciated by and what is left of it.
     *
     * @throws AccountingException
     */
    public function find(int $assetId, ?int $employeeId = null): object
    {
        $asset = $this->db->table('assets a')
            ->select('a.*, b.code AS branch_code, b.name AS branch_name, c.name AS category_name, ca.code AS cost_account_code, ca.name AS cost_account_name, aa.code AS accumulated_account_code, aa.name AS accumulated_account_name, ea.code AS expense_account_code, ea.name AS expense_account_name', false)
            ->join('branches b', 'b.branch_id = a.branch_id')
            ->join('asset_categories c', 'c.category_id = a.category_id', 'left')
            ->join('accounting_accounts ca', 'ca.account_id = a.cost_account_id', 'left')
            ->join('accounting_accounts aa', 'aa.account_id = a.accumulated_account_id', 'left')
            ->join('accounting_accounts ea', 'ea.account_id = a.expense_account_id', 'left')
            ->where('a.company_id', CompanyContext::id())
            ->where('a.asset_id', $assetId)
            ->where('a.deleted', 0)
            ->get()
            ->getRow();

        if ($asset === null || ! $this->branches->canAccess((int) $asset->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::AssetNotFound, "Asset {$assetId} does not exist.", ['name' => (string) $assetId]);
        }

        $asset->units       = $this->db->table('asset_units')->where('asset_id', $assetId)->orderBy('position', 'ASC')->get()->getResult();
        $asset->accumulated = $this->figures->accumulated($asset);
        $asset->book_value  = bcsub((string) $asset->cost, $asset->accumulated, 4);

        return $asset;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return int The asset.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        $branchId = $this->branchOf($input, null, $employeeId);
        $data     = $this->input->prepare($input, null);
        $row      = $data['row'];

        if ($row['funding'] === 'opening') {
            $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);
        }

        $this->assertTreasuryBranch($row, $branchId);

        return $this->attempt(function () use ($row, $data, $branchId, $employeeId): int {
            return $this->transactional(function () use ($row, $data, $branchId, $employeeId): int {
                $this->holdBranches([$branchId]);
                $this->assertCodeFree((string) $row['code'], null);

                $assetId = $this->insert($row, $branchId, $employeeId);

                $this->replaceUnits($assetId, $data['units']);
                $this->giveCode($assetId, (string) $row['code'], $branchId);

                $asset = $this->raw($assetId);

                $this->postAcquisition($asset, $employeeId, 'Asset entered');

                $status = $this->statusFor($asset, AssetStatus::Active);

                if ($status !== AssetStatus::Active) {
                    $this->db->table('assets')->where('asset_id', $assetId)->update(['status' => $status->value]);
                }

                $this->audit->write(self::ENTITY, $assetId, 'asset_created', $employeeId, $this->origin($employeeId), ['code' => $this->raw($assetId)->code, 'cost' => $row['cost'], 'method' => $row['method'], 'funding' => $row['funding']], $branchId);

                return $assetId;
            });
        }, null, $branchId, $employeeId);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function update(int $assetId, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        $known = $this->find($assetId, $employeeId);
        $hold  = $this->existingBranches([(int) $known->branch_id, (int) ($input['branch_id'] ?? 0)]);

        $this->attempt(function () use ($assetId, $input, $employeeId, $hold): void {
            $this->transactional(function () use ($assetId, $input, $employeeId, $hold): void {
                $this->holdBranches($hold);

                $asset = $this->lock($assetId, $employeeId);

                if (AssetStatus::from((string) $asset->status)->isDisposed()) {
                    throw new AccountingException(AccountingError::AssetStatusInvalid, 'An asset that was sold or written off is not changed.', ['name' => (string) $asset->status]);
                }

                $branchId = $this->branchOf($input, $asset, $employeeId);
                $data     = $this->input->prepare($input, $asset);
                $row      = $data['row'];

                $row['code'] = $row['code'] === '' ? (string) $asset->code : $row['code'];

                if ($row['code'] !== (string) $asset->code) {
                    $this->assertCodeFree($row['code'], $assetId);
                }

                $this->assertTreasuryBranch($row, $branchId);

                $changed = $this->changedTerms($asset, $row, $branchId);

                if ($changed !== [] && $this->hasHistory($assetId)) {
                    throw new AccountingException(AccountingError::AssetTermsLocked, 'A depreciation or a disposal stands, so the terms of the asset are changed only after it is taken back.', ['name' => implode(', ', $changed)]);
                }

                if ($changed !== [] && ($row['funding'] === 'opening' || $asset->funding === 'opening')) {
                    $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);
                }

                $this->db->table('assets')->where('asset_id', $assetId)->update([...$row, 'branch_id' => $branchId, 'quantity' => $row['quantity']]);
                $this->replaceUnits($assetId, $data['units']);

                $updated = $this->raw($assetId);

                if ($changed !== []) {
                    $this->postAcquisition($updated, $employeeId, 'Asset changed');
                }

                $current = AssetStatus::from((string) $updated->status);

                if ($current === AssetStatus::Active || $current === AssetStatus::Consumed) {
                    $this->db->table('assets')->where('asset_id', $assetId)->update(['status' => $this->statusFor($updated, $current)->value]);
                }

                $this->audit->write(self::ENTITY, $assetId, 'asset_updated', $employeeId, $this->origin($employeeId), ['terms' => $changed], $branchId);
            });
        }, $assetId, (int) $known->branch_id, $employeeId);
    }

    /**
     * An asset that is in service is taken out of service, and one that is out is put back. An
     * inactive asset is left out of the depreciation runs and nothing else changes.
     *
     * @throws AccountingException|BranchException
     */
    public function setActive(int $assetId, bool $active, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        $hold = $this->existingBranches([$this->branchOfAsset($assetId)]);

        $this->transactional(function () use ($assetId, $active, $employeeId, $hold): void {
            $this->holdBranches($hold);

            $asset   = $this->lock($assetId, $employeeId);
            $current = AssetStatus::from((string) $asset->status);

            if ($active ? $current !== AssetStatus::Inactive : ! in_array($current, [AssetStatus::Active, AssetStatus::Consumed], true)) {
                throw new AccountingException(AccountingError::AssetStatusInvalid, 'An asset is switched on when it is off and off when it is in service.', ['name' => $current->value]);
            }

            $status = $active ? $this->statusFor($asset, AssetStatus::Active) : AssetStatus::Inactive;

            $this->db->table('assets')->where('asset_id', $assetId)->update(['status' => $status->value]);
            $this->audit->write(self::ENTITY, $assetId, $active ? 'asset_activated' : 'asset_deactivated', $employeeId, $this->origin($employeeId), ['status' => $status->value], (int) $asset->branch_id);
        });
    }

    /**
     * Takes an asset that was entered by mistake out of the register, together with the journal that
     * brought it in. An asset a depreciation or a disposal stands on is not deleted, and an asset
     * that was sold or written off never is.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function delete(int $assetId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        $known = $this->find($assetId, $employeeId);

        $this->attempt(function () use ($assetId, $employeeId, $known): void {
            $this->transactional(function () use ($assetId, $employeeId, $known): void {
                $this->holdBranches([(int) $known->branch_id]);

                $asset = $this->lock($assetId, $employeeId);

                if (AssetStatus::from((string) $asset->status)->isDisposed() || $this->hasHistory($assetId)) {
                    throw new AccountingException(AccountingError::AssetHasHistory, 'A depreciation or a disposal stands on the asset, so it is not deleted.', ['name' => (string) $asset->code]);
                }

                if ($asset->funding === 'opening') {
                    $this->guard->require(AccountingGuard::OPENING_MANAGE, $employeeId);
                }

                $this->journals->sync(AssetSources::ACQUISITION, $assetId, null, $employeeId, 'Asset ' . $asset->code . ' deleted');

                $this->db->table('asset_units')->where('asset_id', $assetId)->delete();
                $this->db->table('assets')->where('asset_id', $assetId)->update(['deleted' => 1]);
                $this->audit->write(self::ENTITY, $assetId, 'asset_deleted', $employeeId, $this->origin($employeeId), ['code' => $asset->code, 'cost' => $asset->cost], (int) $asset->branch_id);
            });
        }, $assetId, (int) $known->branch_id, $employeeId);
    }

    /**
     * What an asset of the units of production method was used for in a month. It is entered before
     * the month is run, and changed until then.
     *
     * @throws AccountingException|BranchException
     */
    public function recordUsage(int $assetId, string $month, string $units, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);

        $period = DepreciationPeriod::fromMonth($month);

        if ($period === null || preg_match('/^\d{1,13}(\.\d{1,2})?$/', trim($units)) !== 1) {
            throw new AccountingException(AccountingError::AssetUsageInvalid, 'The usage is a month and a number of units.', ['name' => $month]);
        }

        $units = bcadd(trim($units), '0', 2);
        $hold  = $this->existingBranches([$this->branchOfAsset($assetId)]);

        $this->transactional(function () use ($assetId, $period, $units, $employeeId, $hold): void {
            $this->holdBranches($hold);

            $asset = $this->lock($assetId, $employeeId);

            if ($asset->method !== AssetMethod::Units->value || AssetStatus::from((string) $asset->status)->isDisposed()) {
                throw new AccountingException(AccountingError::AssetUsageInvalid, 'Only an asset of the units of production method takes usage, and not once it is disposed of.', ['name' => (string) $asset->code]);
            }

            if ($this->db->table('asset_depreciations')->where(['asset_id' => $assetId, 'period_start' => $period->start, 'active_key' => 1])->countAllResults() > 0) {
                throw new AccountingException(AccountingError::AssetUsageLocked, 'The month is depreciated already, so its usage stands.', ['name' => $period->label()]);
            }

            if ($asset->total_units !== null) {
                $other = $this->db->table('asset_usage')->selectSum('units', 'used')->where('asset_id', $assetId)->where('period_start !=', $period->start)->get()->getRow();

                if (bccomp(bcadd((string) ($other->used ?? '0'), $units, 2), (string) $asset->total_units, 2) > 0) {
                    throw new AccountingException(AccountingError::AssetUsageOverTotal, 'The usage passes the units the asset has in all.', ['name' => (string) $asset->total_units]);
                }
            }

            $table = $this->db->prefixTable('asset_usage');

            $this->db->query(
                "INSERT INTO {$table} (company_id, asset_id, period_start, units, created_by) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE units = VALUES(units)",
                [CompanyContext::id(), $assetId, $period->start, $units, $employeeId],
            );

            $this->audit->write(self::ENTITY, $assetId, 'asset_usage_recorded', $employeeId, $this->origin($employeeId), ['month' => $period->label(), 'units' => $units], (int) $asset->branch_id);
        });
    }

    /**
     * @param array<string, mixed> $row
     */
    private function insert(array $row, int $branchId, ?int $employeeId): int
    {
        $code = $row['code'] === '' ? 'NEW-' . bin2hex(random_bytes(8)) : $row['code'];

        $inserted = $this->db->table('assets')->insert([...$row, 'code' => $code, 'company_id' => CompanyContext::id(), 'branch_id' => $branchId, 'status' => AssetStatus::Active->value, 'created_by' => $employeeId]);

        if ($inserted === false) {
            throw new AccountingException(AccountingError::AssetCodeTaken, 'The asset could not be saved.', ['name' => (string) $row['code']]);
        }

        return (int) $this->db->insertID();
    }

    /**
     * An asset entered without a code gets the next of the numbering, or its own number when the
     * numbering is off.
     */
    private function giveCode(int $assetId, string $typed, int $branchId): void
    {
        if ($typed !== '') {
            return;
        }

        if ($this->numbering->assign(DocumentType::FixedAsset, 'assets', $assetId, $branchId) !== null) {
            return;
        }

        $code = str_pad((string) $assetId, 6, '0', STR_PAD_LEFT);

        if ($this->db->table('assets')->where('company_id', CompanyContext::id())->where('code', $code)->where('deleted', 0)->countAllResults() > 0) {
            $code = 'A-' . $assetId;
        }

        $this->db->table('assets')->where('asset_id', $assetId)->update(['code' => $code]);
    }

    /**
     * @param list<array<string, mixed>> $units
     */
    private function replaceUnits(int $assetId, array $units): void
    {
        $this->db->table('asset_units')->where('asset_id', $assetId)->delete();

        foreach ($units as $unit) {
            $this->db->table('asset_units')->insert([...$unit, 'asset_id' => $assetId, 'company_id' => CompanyContext::id()]);
        }
    }

    /**
     * The journal of the asset as the asset is now: posted when there is none, left alone when it still
     * matches, and reversed and posted again as the next revision when it does not.
     *
     * @throws AccountingException|PostingException
     */
    private function postAcquisition(object $asset, ?int $employeeId, string $reason): void
    {
        $date  = $asset->funding === 'opening' ? (string) $this->settings->startDate() : (string) $asset->purchase_date;
        $draft = $this->builder->acquisition($asset, $date, $this->journals->nextRevision(AssetSources::ACQUISITION, (int) $asset->asset_id), $employeeId);

        $this->journals->sync(AssetSources::ACQUISITION, (int) $asset->asset_id, $draft, $employeeId, $reason . ': ' . $asset->code);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string> The terms that changed.
     */
    private function changedTerms(object $asset, array $row, int $branchId): array
    {
        $row['branch_id'] = $branchId;
        $changed          = [];

        foreach (AssetInput::TERM_FIELDS as $field) {
            if (! $this->same($asset->{$field} ?? null, $row[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function same(mixed $stored, mixed $new): bool
    {
        if ($stored === null || $new === null) {
            return $stored === $new;
        }

        if (is_numeric($stored) && is_numeric($new)) {
            return bccomp((string) $stored, (string) $new, 6) === 0;
        }

        return (string) $stored === (string) $new;
    }

    private function hasHistory(int $assetId): bool
    {
        return $this->db->table('asset_depreciations')->where(['asset_id' => $assetId, 'active_key' => 1])->countAllResults() > 0
            || $this->db->table('asset_operations')->where(['asset_id' => $assetId, 'active_key' => 1])->countAllResults() > 0;
    }

    /**
     * An asset that has depreciated by all it is to be is consumed, any other is in service.
     */
    private function statusFor(object $asset, AssetStatus $fallback): AssetStatus
    {
        $terms = AssetFigures::termsOf($asset);

        if (! $terms->method->depreciates() || bccomp($terms->base(), '0', 4) <= 0) {
            return $fallback === AssetStatus::Consumed ? AssetStatus::Active : $fallback;
        }

        return bccomp($this->figures->accumulated($asset), $terms->base(), 4) >= 0 ? AssetStatus::Consumed : AssetStatus::Active;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertTreasuryBranch(array $row, int $branchId): void
    {
        if ($row['funding'] !== 'treasury') {
            return;
        }

        $treasury = $this->db->table('treasuries')->where('company_id', CompanyContext::id())->where('treasury_id', (int) $row['treasury_id'])->get()->getRow();

        if ($treasury === null || (int) $treasury->branch_id !== $branchId) {
            throw new AccountingException(AccountingError::AssetTreasuryBranch, 'An asset is paid from a treasury of its own branch.', ['name' => (string) ($treasury->name ?? $row['treasury_id'])]);
        }
    }

    /**
     * @throws AccountingException
     */
    private function assertCodeFree(string $code, ?int $exceptAssetId): void
    {
        if ($code === '') {
            return;
        }

        $builder = $this->db->table('assets')->where('company_id', CompanyContext::id())->where('code', $code)->where('deleted', 0);

        if ($exceptAssetId !== null) {
            $builder->where('asset_id !=', $exceptAssetId);
        }

        if ($builder->countAllResults() > 0) {
            throw new AccountingException(AccountingError::AssetCodeTaken, "The code {$code} is used by another asset.", ['name' => $code]);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws BranchException
     */
    private function branchOf(array $input, ?object $asset, ?int $employeeId): int
    {
        $branchId = (int) ($input['branch_id'] ?? 0);

        if ($branchId === 0) {
            $branchId = $asset === null ? $this->branches->defaultBranchId($employeeId) : (int) $asset->branch_id;
        }

        $branch = $this->db->table('branches')->where('company_id', CompanyContext::id())->where('branch_id', $branchId)->get()->getRow();

        if ($branch === null) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);

        if ((int) $branch->active === 0 && ($asset === null || (int) $asset->branch_id !== $branchId)) {
            throw new BranchException(BranchError::Inactive, "Branch {$branchId} is inactive.", ['branch_id' => $branchId]);
        }

        return $branchId;
    }

    /**
     * The branches among those given that exist, in the order of their numbers, which is the order the
     * runs of depreciation take them in. Read before the transaction, so that the first thing the
     * transaction does is take the locks and its snapshot is not older than they are.
     *
     * @param list<int> $branchIds
     *
     * @return list<int>
     */
    private function existingBranches(array $branchIds): array
    {
        $wanted = array_values(array_filter(array_unique($branchIds), static fn (int $branchId): bool => $branchId > 0));

        if ($wanted === []) {
            return [];
        }

        $found = $this->db->table('branches')->select('branch_id')->where('company_id', CompanyContext::id())->whereIn('branch_id', $wanted)->orderBy('branch_id', 'ASC')->get()->getResult();

        return array_map(static fn (object $row): int => (int) $row->branch_id, $found);
    }

    private function branchOfAsset(int $assetId): int
    {
        $row = $this->db->table('assets')->select('branch_id')->where('company_id', CompanyContext::id())->where('asset_id', $assetId)->get()->getRow();

        return $row === null ? 0 : (int) $row->branch_id;
    }

    /**
     * A depreciation run holds the lock of a branch before it reads the assets of the branch, so a change
     * of an asset holds the same lock, shared, before it locks the asset: both take the two in one order.
     *
     * @param list<int> $branchIds In the order of their numbers.
     */
    private function holdBranches(array $branchIds): void
    {
        foreach ($branchIds as $branchId) {
            $this->locks->shareForPosting($branchId);
        }
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function lock(int $assetId, ?int $employeeId): object
    {
        $table = $this->db->prefixTable('assets');
        $asset = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND asset_id = ? AND deleted = 0 FOR UPDATE", [CompanyContext::id(), $assetId]);

        if ($asset === null || ! $this->branches->canAccess((int) $asset->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::AssetNotFound, "Asset {$assetId} does not exist.", ['name' => (string) $assetId]);
        }

        return $asset;
    }

    private function raw(int $assetId): object
    {
        return $this->db->table('assets')->where('asset_id', $assetId)->get()->getRow();
    }

    /**
     * Runs a change and, when the books refuse it, records the refusal after the transaction has
     * rolled back.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function attempt(callable $work, ?int $assetId, int $branchId, ?int $employeeId): mixed
    {
        try {
            return $work();
        } catch (PostingException $failure) {
            (new PostingFailureLog($this->db))->recordUnbuilt(AssetSources::ACQUISITION, $assetId, 1, JournalEntryType::General, $failure, $employeeId, $branchId);

            throw $failure;
        }
    }

    private function origin(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
