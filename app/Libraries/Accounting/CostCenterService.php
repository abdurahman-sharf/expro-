<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CostCenterService
{
    use RunsInTransaction;

    private const CODE_PATTERN = '/^[0-9A-Za-z][0-9A-Za-z\-]{0,31}$/';
    private const NAME_MAX     = 255;

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
        return $this->db->table('accounting_journal_lines')->where('cost_center_id', $costCenterId)->countAllResults() > 0;
    }

    /**
     * Every cost center in code order, with whether it has children and journal lines.
     *
     * @return list<object>
     */
    public function all(): array
    {
        $centers = $this->db->prefixTable('accounting_cost_centers');
        $lines   = $this->db->prefixTable('accounting_journal_lines');

        return $this->db->query(
            "SELECT c.cost_center_id, c.parent_id, c.code, c.name, c.active,
                EXISTS (SELECT 1 FROM {$centers} k WHERE k.parent_id = c.cost_center_id) AS has_children,
                EXISTS (SELECT 1 FROM {$lines} l WHERE l.cost_center_id = c.cost_center_id) AS used
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
