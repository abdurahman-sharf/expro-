<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CostCenterService
{
    use RunsInTransaction;

    public const MAX_ROWS         = 20;
    public const TOTAL_CAP        = '200';
    private const CODE_PATTERN    = '/^[0-9A-Za-z][0-9A-Za-z\-]{0,31}$/';
    private const NAME_MAX        = 255;
    private const PERCENT_PATTERN = '/^\d{1,3}(\.\d{1,4})?$/';
    private const WORK_SCALE      = 8;

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
    }

    /**
     * @param array{parent_id?: int|string|null, code?: string, name?: string} $input
     *
     * @throws AccountingException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::COST_CENTERS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $parentId = $this->normalizeParent($input['parent_id'] ?? null, null);
        $code     = $this->normalizeCode((string) ($input['code'] ?? ''));
        $name     = $this->normalizeName((string) ($input['name'] ?? ''));

        $this->assertCodeFree($code, null);

        return $this->transactional(function () use ($parentId, $code, $name, $employeeId): int {
            $this->db->table('accounting_cost_centers')->insert([
                'parent_id' => $parentId,
                'code'      => $code,
                'name'      => $name,
                'active'    => 1,
            ]);

            $costCenterId = (int) $this->db->insertID();

            $this->audit->write('cost_center', $costCenterId, 'created', $employeeId, JournalOrigin::User, [
                'code'      => $code,
                'name'      => $name,
                'parent_id' => $parentId,
            ]);

            return $costCenterId;
        });
    }

    /**
     * @param array{parent_id?: int|string|null, code?: string, name?: string} $input
     *
     * @throws AccountingException
     */
    public function update(int $costCenterId, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::COST_CENTERS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $costCenter = $this->requireCostCenter($costCenterId);
        $changes    = [];

        if (array_key_exists('name', $input)) {
            $name = $this->normalizeName((string) $input['name']);

            if ($name !== $costCenter->name) {
                $changes['name'] = $name;
            }
        }

        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);

            if ($code !== $costCenter->code) {
                $this->assertCodeFree($code, $costCenterId);
                $changes['code'] = $code;
            }
        }

        if (array_key_exists('parent_id', $input)) {
            $parentId = $this->normalizeParent($input['parent_id'], $costCenterId);

            if ($parentId !== ($costCenter->parent_id === null ? null : (int) $costCenter->parent_id)) {
                $changes['parent_id'] = $parentId;
            }
        }

        if ($changes === []) {
            return;
        }

        $this->transactional(function () use ($costCenterId, $changes, $costCenter, $employeeId): void {
            $this->db->table('accounting_cost_centers')->where('cost_center_id', $costCenterId)->update($changes);

            $this->audit->write('cost_center', $costCenterId, 'updated', $employeeId, JournalOrigin::User, [
                'before' => array_intersect_key((array) $costCenter, $changes),
                'after'  => $changes,
            ]);
        });
    }

    /**
     * @throws AccountingException
     */
    public function deactivate(int $costCenterId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::COST_CENTERS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $costCenter = $this->requireCostCenter($costCenterId);

        if ((int) $costCenter->active === 0) {
            throw new AccountingException(AccountingError::AlreadyInactive, 'The cost center is already inactive.');
        }

        if ($this->db->table('accounting_cost_centers')->where('parent_id', $costCenterId)->where('active', 1)->countAllResults() > 0) {
            throw new AccountingException(AccountingError::HasActiveChildren, 'The cost center has active children.');
        }

        $this->setActive($costCenterId, 0, 'deactivated', $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function activate(int $costCenterId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::COST_CENTERS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $costCenter = $this->requireCostCenter($costCenterId);

        if ((int) $costCenter->active === 1) {
            throw new AccountingException(AccountingError::AlreadyActive, 'The cost center is already active.');
        }

        if ($costCenter->parent_id !== null && (int) $this->requireCostCenter((int) $costCenter->parent_id)->active === 0) {
            throw new AccountingException(AccountingError::ParentInactive, 'The parent cost center is inactive.');
        }

        $this->setActive($costCenterId, 1, 'activated', $employeeId);
    }

    /**
     * Only a cost center with no children and no journal lines can be deleted.
     *
     * @throws AccountingException
     */
    public function delete(int $costCenterId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::COST_CENTERS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $costCenter = $this->requireCostCenter($costCenterId);

        if ($this->db->table('accounting_cost_centers')->where('parent_id', $costCenterId)->countAllResults() > 0) {
            throw new AccountingException(AccountingError::HasChildren, 'The cost center has children.');
        }

        if ($this->isUsed($costCenterId)) {
            throw new AccountingException(AccountingError::CostCenterInUse, 'The cost center has journal lines.');
        }

        $this->transactional(function () use ($costCenterId, $costCenter, $employeeId): void {
            $this->db->table('accounting_cost_centers')->where('cost_center_id', $costCenterId)->delete();

            $this->audit->write('cost_center', $costCenterId, 'deleted', $employeeId, JournalOrigin::User, [
                'code' => $costCenter->code,
                'name' => $costCenter->name,
            ]);
        });
    }

    public function find(int $costCenterId): ?object
    {
        return $this->db->table('accounting_cost_centers')->where('cost_center_id', $costCenterId)->get()->getRow();
    }

    public function isUsed(int $costCenterId): bool
    {
        return $this->db->table('accounting_journal_lines')->where('cost_center_id', $costCenterId)->countAllResults() > 0
            || $this->db->table('accounting_journal_line_distributions')->where('cost_center_id', $costCenterId)->countAllResults() > 0
            || $this->db->table('accounting_account_cost_centers')->where('cost_center_id', $costCenterId)->countAllResults() > 0;
    }

    /**
     * Every cost center in code order, with whether it has children and journal lines.
     *
     * @return list<object>
     */
    public function all(): array
    {
        $centers       = $this->db->prefixTable('accounting_cost_centers');
        $lines         = $this->db->prefixTable('accounting_journal_lines');
        $distributions = $this->db->prefixTable('accounting_journal_line_distributions');
        $defaults      = $this->db->prefixTable('accounting_account_cost_centers');

        return $this->db->query(
            "SELECT c.cost_center_id, c.parent_id, c.code, c.name, c.active,
                EXISTS (SELECT 1 FROM {$centers} k WHERE k.parent_id = c.cost_center_id) AS has_children,
                (EXISTS (SELECT 1 FROM {$lines} l WHERE l.cost_center_id = c.cost_center_id)
                    OR EXISTS (SELECT 1 FROM {$distributions} d WHERE d.cost_center_id = c.cost_center_id)
                    OR EXISTS (SELECT 1 FROM {$defaults} a WHERE a.cost_center_id = c.cost_center_id)) AS used
             FROM {$centers} c
             ORDER BY c.code ASC",
        )->getResult();
    }

    /**
     * Active cost centers for the journal line selector.
     *
     * @return list<object>
     */
    public function selectable(): array
    {
        return $this->db->table('accounting_cost_centers')
            ->select('cost_center_id, code, name')
            ->where('active', 1)
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Cuts the amount of a line over cost centers by percentage and returns each share with its amount.
     * A share is the amount times its percent, rounded to the decimals of the base currency; what the
     * rounding leaves over, so that the shares add up to the amount of the line exactly (or to its
     * share of the total percent when that is over 100), falls on the largest share, the first one
     * when two are equal. When the rounding went up so far that the largest share cannot take it off
     * without going below nothing, what it cannot take falls on the next largest share, and so on, so
     * that no share is ever negative. A complete distribution adds up to 100 percent; one over 100 is
     * accepted only while the setting allows it, up to the cap. An incomplete one, which a draft may
     * keep, may add up to less. A row may give an amount instead of a percent, and then the percent
     * follows from it.
     *
     * @param list<mixed>    $rows       Each row names a cost center and gives a percent or an amount.
     * @param numeric-string $lineAmount The debit or the credit of the line.
     * @param int            $lineNumber The line the rows belong to, which a refusal names.
     * @param bool|null      $allowOver  What the setting says when null.
     *
     * @return list<array{cost_center_id: int, percent: numeric-string, amount: numeric-string}>
     *
     * @throws AccountingException
     */
    public function distribute(array $rows, string $lineAmount, int $lineNumber = 0, bool $complete = true, ?bool $allowOver = null): array
    {
        if (count($rows) > self::MAX_ROWS) {
            throw new AccountingException(AccountingError::DistributionTooManyRows, 'The distribution has too many rows.', ['line' => $lineNumber, 'max' => self::MAX_ROWS]);
        }

        $allowOver ??= (new JournalSettings($this->db))->allowOver100();
        $limit  = $allowOver ? self::TOTAL_CAP : '100';
        $parsed = [];
        $total  = '0.0000';

        foreach ($rows as $row) {
            $centerId = is_array($row) && isset($row['cost_center_id']) && is_scalar($row['cost_center_id']) && ctype_digit((string) $row['cost_center_id']) ? (int) $row['cost_center_id'] : 0;

            if ($centerId === 0) {
                throw new AccountingException(AccountingError::DistributionRowInvalid, 'A row of the distribution needs a cost center.', ['line' => $lineNumber]);
            }

            if (isset($parsed[$centerId])) {
                throw new AccountingException(AccountingError::DistributionDuplicate, "Cost center {$centerId} is in the distribution twice.", ['line' => $lineNumber]);
            }

            $percent = $this->percentOf($row, $lineAmount);

            if ($percent === null || bccomp($percent, '0', 4) <= 0 || bccomp($percent, $limit, 4) > 0) {
                throw new AccountingException(AccountingError::DistributionPercentInvalid, 'A percent is a number above 0 with up to four decimals.', ['line' => $lineNumber, 'max' => $limit]);
            }

            $parsed[$centerId] = $percent;
            $total             = bcadd($total, $percent, 4);
        }

        $this->requireActive(array_keys($parsed), $lineNumber);

        if (bccomp($total, $limit, 4) > 0) {
            throw $allowOver
                ? new AccountingException(AccountingError::DistributionOverLimit, "The percentages add up to more than {$limit}.", ['line' => $lineNumber, 'max' => $limit])
                : new AccountingException(AccountingError::DistributionTotalInvalid, "The percentages add up to {$total}, not 100.", ['line' => $lineNumber, 'percent' => $total]);
        }

        if ($complete && $parsed !== [] && bccomp($total, '100', 4) < 0) {
            throw new AccountingException(AccountingError::DistributionTotalInvalid, "The percentages add up to {$total}, not 100.", ['line' => $lineNumber, 'percent' => $total]);
        }

        return $this->shares($parsed, $total, $lineAmount);
    }

    /**
     * @param array<int, numeric-string> $percents   The percent of each cost center, in order.
     * @param numeric-string             $total
     * @param numeric-string             $lineAmount
     *
     * @return list<array{cost_center_id: int, percent: numeric-string, amount: numeric-string}>
     */
    private function shares(array $percents, string $total, string $lineAmount): array
    {
        if ($percents === []) {
            return [];
        }

        $decimals = (new Currencies($this->db))->baseDecimals();
        $amounts  = [];
        $sum      = '0';

        foreach ($percents as $centerId => $percent) {
            $amounts[$centerId] = Money::round(bcdiv(bcmul($lineAmount, $percent, self::WORK_SCALE), '100', self::WORK_SCALE), $decimals);
            $sum                = bcadd($sum, $amounts[$centerId], 4);
        }

        $target    = Money::round(bcdiv(bcmul($lineAmount, $total, self::WORK_SCALE), '100', self::WORK_SCALE), 4);
        $remainder = bcsub($target, $sum, 4);
        $bySize    = array_keys($percents);

        usort($bySize, static fn (int $first, int $second): int => bccomp($percents[$second], $percents[$first], 4));

        foreach ($bySize as $centerId) {
            if (bccomp($remainder, '0', 4) === 0) {
                break;
            }

            $moved              = bccomp(bcadd($amounts[$centerId], $remainder, 4), '0', 4) >= 0 ? $remainder : bcsub('0', $amounts[$centerId], 4);
            $amounts[$centerId] = bcadd($amounts[$centerId], $moved, 4);
            $remainder          = bcsub($remainder, $moved, 4);
        }

        $shares = [];

        foreach ($percents as $centerId => $percent) {
            $shares[] = ['cost_center_id' => $centerId, 'percent' => bcadd($percent, '0', 4), 'amount' => bcadd($amounts[$centerId], '0', 4)];
        }

        return $shares;
    }

    /**
     * @return numeric-string|null
     */
    private function percentOf(mixed $row, string $lineAmount): ?string
    {
        $percent = isset($row['percent']) && is_scalar($row['percent']) ? trim((string) $row['percent']) : '';
        $amount  = isset($row['amount']) && is_scalar($row['amount']) ? trim((string) $row['amount']) : '';

        if ($percent === '' && $amount !== '' && preg_match('/^\d{1,11}(\.\d{1,4})?$/', $amount) === 1 && bccomp($lineAmount, '0', 4) > 0) {
            return Money::round(bcmul(bcdiv($amount, $lineAmount, self::WORK_SCALE), '100', self::WORK_SCALE), 4);
        }

        return preg_match(self::PERCENT_PATTERN, $percent) === 1 ? bcadd($percent, '0', 4) : null;
    }

    /**
     * @param list<int> $centerIds
     *
     * @throws AccountingException When one of them is missing or switched off.
     */
    public function requireActive(array $centerIds, int $lineNumber): void
    {
        if ($centerIds === []) {
            return;
        }

        $active = array_map('intval', array_column(
            $this->db->table('accounting_cost_centers')->select('cost_center_id')->whereIn('cost_center_id', $centerIds)->where('active', 1)->get()->getResultArray(),
            'cost_center_id',
        ));

        foreach ($centerIds as $centerId) {
            if (! in_array($centerId, $active, true)) {
                throw new AccountingException(AccountingError::LineCostCenterInvalid, "Cost center {$centerId} is missing or inactive.", ['line' => $lineNumber]);
            }
        }
    }

    /**
     * The cost centers an account is distributed over when it is chosen on a manual journal line.
     *
     * @return list<object{cost_center_id: int|string, percent: string, code: string, name: string, active: int|string}>
     */
    public function defaultsOf(int $accountId, bool $activeOnly = false): array
    {
        $builder = $this->db->table('accounting_account_cost_centers d')
            ->select('d.cost_center_id, d.percent, c.code, c.name, c.active')
            ->join('accounting_cost_centers c', 'c.cost_center_id = d.cost_center_id')
            ->where('d.account_id', $accountId)
            ->orderBy('c.code', 'ASC');

        if ($activeOnly) {
            $builder->where('c.active', 1);
        }

        return $builder->get()->getResult();
    }

    /**
     * The default distributions of every account that has one, over the cost centers that are on, for a
     * journal form to fill a line with when its account is chosen.
     *
     * @return array<int, list<array{cost_center_id: int, percent: string}>>
     */
    public function defaultsByAccount(): array
    {
        $byAccount = [];

        foreach ($this->db->table('accounting_account_cost_centers d')
            ->select('d.account_id, d.cost_center_id, d.percent')
            ->join('accounting_cost_centers c', 'c.cost_center_id = d.cost_center_id')
            ->where('c.active', 1)
            ->orderBy('d.account_id', 'ASC')
            ->orderBy('c.code', 'ASC')
            ->get()
            ->getResult() as $row) {
            $byAccount[(int) $row->account_id][] = ['cost_center_id' => (int) $row->cost_center_id, 'percent' => bcadd($row->percent, '0', 4)];
        }

        return $byAccount;
    }

    /**
     * @return array<int, string> The words that name each cost center, switched off ones included.
     */
    public function labels(): array
    {
        $labels = [];

        foreach ($this->db->table('accounting_cost_centers')->select('cost_center_id, code, name')->get()->getResult() as $row) {
            $labels[(int) $row->cost_center_id] = $row->code . ' - ' . $row->name;
        }

        return $labels;
    }

    /**
     * Replaces the default distribution of an account, or clears it with no rows. A default is a plain
     * split: it adds up to 100 whatever the setting for more says, over active cost centers. It never
     * touches a journal that exists; it pre-fills a manual line, and lands on the lines of an automatic
     * journal only while the setting asks for that. Each change is written to the audit log.
     *
     * @param list<mixed> $rows
     *
     * @return bool Whether anything changed.
     *
     * @throws AccountingException
     */
    public function setDefaults(int $accountId, array $rows, ?int $employeeId = null): bool
    {
        $this->guard->require(AccountingGuard::COST_CENTERS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $account = $this->db->table('accounting_accounts')->select('account_id, code, name, is_group')->where('account_id', $accountId)->get()->getRow();

        if ($account === null) {
            throw new AccountingException(AccountingError::AccountNotFound, "Account {$accountId} does not exist.", ['account_id' => $accountId]);
        }

        $label = $account->code . ' ' . $account->name;

        if ((int) $account->is_group === 1) {
            throw new AccountingException(AccountingError::DefaultDistributionInvalid, 'A group of accounts carries no lines to distribute.', ['name' => $label]);
        }

        try {
            $resolved = $this->distribute($rows, '100', 0, true, false);
        } catch (AccountingException $refusal) {
            throw new AccountingException(AccountingError::DefaultDistributionInvalid, $refusal->getMessage(), ['name' => $label], $refusal);
        }

        $before = array_map(static fn (object $row): array => ['cost_center_id' => (int) $row->cost_center_id, 'percent' => bcadd($row->percent, '0', 4)], $this->defaultsOf($accountId));
        $after  = array_map(static fn (array $row): array => ['cost_center_id' => $row['cost_center_id'], 'percent' => $row['percent']], $resolved);

        if ($before === $after) {
            return false;
        }

        $this->transactional(function () use ($accountId, $after, $before, $employeeId): void {
            $this->db->table('accounting_account_cost_centers')->where('account_id', $accountId)->delete();

            if ($after !== []) {
                $this->db->table('accounting_account_cost_centers')->insertBatch(array_map(static fn (array $row): array => ['account_id' => $accountId] + $row, $after));
            }

            $this->audit->write('account', $accountId, 'account_distribution_changed', $employeeId, JournalOrigin::User, ['before' => $before, 'after' => $after]);
        });

        return true;
    }

    /**
     * @return list<object{account_id: int|string, code: string, name: string, row_count: int|string}> The accounts that have a default distribution.
     */
    public function accountsWithDefaults(): array
    {
        return $this->db->table('accounting_account_cost_centers d')
            ->select('a.account_id, a.code, a.name, COUNT(*) AS row_count', false)
            ->join('accounting_accounts a', 'a.account_id = d.account_id')
            ->groupBy('a.account_id, a.code, a.name')
            ->orderBy('a.code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The cost center and every center under it, for a report that reads a center with its sub centers.
     *
     * @return list<int>
     */
    public function descendantsOf(int $costCenterId): array
    {
        $children = [];

        foreach ($this->db->table('accounting_cost_centers')->select('cost_center_id, parent_id')->where('parent_id IS NOT NULL', null, false)->get()->getResult() as $row) {
            $children[(int) $row->parent_id][] = (int) $row->cost_center_id;
        }

        $found = [$costCenterId => $costCenterId];
        $queue = [$costCenterId];

        while ($queue !== []) {
            foreach ($children[array_pop($queue)] ?? [] as $child) {
                if (! isset($found[$child])) {
                    $found[$child] = $child;
                    $queue[]       = $child;
                }
            }
        }

        return array_values($found);
    }

    public function suggestCode(): string
    {
        $codes   = array_column($this->db->table('accounting_cost_centers')->select('code')->get()->getResultArray(), 'code');
        $numeric = array_filter($codes, static fn (string $code): bool => ctype_digit($code));

        $candidate = $numeric === [] ? '1' : (string) (max(array_map('intval', $numeric)) + 1);

        while ($this->db->table('accounting_cost_centers')->where('code', $candidate)->countAllResults() > 0) {
            $candidate = (string) ((int) $candidate + 1);
        }

        return $candidate;
    }

    private function setActive(int $costCenterId, int $active, string $action, ?int $employeeId): void
    {
        $this->transactional(function () use ($costCenterId, $active, $action, $employeeId): void {
            $this->db->table('accounting_cost_centers')->where('cost_center_id', $costCenterId)->update(['active' => $active]);
            $this->audit->write('cost_center', $costCenterId, $action, $employeeId, JournalOrigin::User);
        });
    }

    private function requireCostCenter(int $costCenterId): object
    {
        $costCenter = $this->find($costCenterId);

        if ($costCenter === null) {
            throw new AccountingException(AccountingError::CostCenterNotFound, "Cost center {$costCenterId} does not exist.", ['cost_center_id' => $costCenterId]);
        }

        return $costCenter;
    }

    /**
     * Returns the parent id or null for a root, refusing a missing, inactive or circular parent.
     */
    private function normalizeParent(mixed $parentId, ?int $childId): ?int
    {
        if ($parentId === null || $parentId === '' || (int) $parentId === 0) {
            return null;
        }

        $parentId = (int) $parentId;
        $parent   = $this->find($parentId);

        if ($parent === null) {
            throw new AccountingException(AccountingError::CostCenterNotFound, "Cost center {$parentId} does not exist.", ['cost_center_id' => $parentId]);
        }

        if ((int) $parent->active === 0) {
            throw new AccountingException(AccountingError::ParentInactive, 'The parent cost center is inactive.');
        }

        for ($current = $parent; $current !== null && $childId !== null; $current = $current->parent_id === null ? null : $this->find((int) $current->parent_id)) {
            if ((int) $current->cost_center_id === $childId) {
                throw new AccountingException(AccountingError::CostCenterCycle, 'A cost center cannot sit under itself.');
            }
        }

        return $parentId;
    }

    private function normalizeCode(string $code): string
    {
        $code = trim($code);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new AccountingException(AccountingError::InvalidCode, 'The code must be 1 to 32 letters, digits or dashes.', ['code' => $code]);
        }

        return $code;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::InvalidName, 'The name must be 1 to 255 characters.');
        }

        return $name;
    }

    private function assertCodeFree(string $code, ?int $exceptCostCenterId): void
    {
        $builder = $this->db->table('accounting_cost_centers')->where('code', $code);

        if ($exceptCostCenterId !== null) {
            $builder->where('cost_center_id !=', $exceptCostCenterId);
        }

        if ($builder->countAllResults() > 0) {
            throw new AccountingException(AccountingError::CodeTaken, 'The code is already used.', ['code' => $code]);
        }
    }
}
