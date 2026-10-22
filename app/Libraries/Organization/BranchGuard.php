<?php

namespace App\Libraries\Organization;

use App\Models\Employee;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Which branches an employee may work in. An employee either holds the all-branches grant or is
 * listed in employee_branches. A null employee is the system itself (a console command or an
 * automatic posting) and is not restricted.
 */
final class BranchGuard
{
    public const ALL_BRANCHES    = 'org_all_branches';
    public const BRANCHES_MANAGE = 'org_branches_manage';
    public const SCOPE_MANAGE    = 'org_scope_manage';

    private BaseConnection $db;
    private Employee $employee;

    public function __construct(?BaseConnection $db = null, ?Employee $employee = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->employee = $employee ?? model(Employee::class);
    }

    public function has(string $permissionId, ?int $employeeId): bool
    {
        return $employeeId === null || $this->employee->has_grant($permissionId, $employeeId);
    }

    public function isUnrestricted(?int $employeeId): bool
    {
        return $this->has(self::ALL_BRANCHES, $employeeId);
    }

    /**
     * @return list<int>|null Null means every branch.
     */
    public function allowedBranchIds(?int $employeeId): ?array
    {
        if ($this->isUnrestricted($employeeId)) {
            return null;
        }

        $rows = $this->db->table('employee_branches e')
            ->select('e.branch_id')
            ->join('branches b', 'b.branch_id = e.branch_id')
            ->where('e.person_id', $employeeId)
            ->where('b.company_id', CompanyContext::id())
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($rows, 'branch_id'));
    }

    public function canAccess(int $branchId, ?int $employeeId): bool
    {
        $allowed = $this->allowedBranchIds($employeeId);

        return $allowed === null || in_array($branchId, $allowed, true);
    }

    /**
     * @throws BranchException
     */
    public function requireBranch(int $branchId, ?int $employeeId): void
    {
        if (! $this->canAccess($branchId, $employeeId)) {
            throw new BranchException(BranchError::NotAllowed, "The branch {$branchId} is outside the employee's scope.", ['branch_id' => $branchId]);
        }
    }

    /**
     * @throws BranchException
     */
    public function requireCompanyScope(?int $employeeId): void
    {
        if (! $this->isUnrestricted($employeeId)) {
            throw new BranchException(BranchError::CompanyScopeNeeded, 'This action concerns the whole company.');
        }
    }

    /**
     * The branch a new document starts in: the employee's own default, else the first branch they
     * may use, else the company default.
     */
    public function defaultBranchId(?int $employeeId): int
    {
        if ($employeeId !== null) {
            $row = $this->db->table('employee_branches e')
                ->select('e.branch_id')
                ->join('branches b', 'b.branch_id = e.branch_id')
                ->where('e.person_id', $employeeId)
                ->where('b.company_id', CompanyContext::id())
                ->where('b.active', 1)
                ->orderBy('e.is_default', 'DESC')
                ->orderBy('e.branch_id', 'ASC')
                ->limit(1)
                ->get()
                ->getRow();

            if ($row !== null) {
                return (int) $row->branch_id;
            }
        }

        $selectable = $this->selectableBranches($employeeId);

        return $selectable === [] ? $this->companyDefaultBranchId() : (int) $selectable[0]->branch_id;
    }

    public function companyDefaultBranchId(): int
    {
        $row = $this->db->table('branches')
            ->select('branch_id')
            ->where('company_id', CompanyContext::id())
            ->orderBy('is_default', 'DESC')
            ->orderBy('branch_id', 'ASC')
            ->limit(1)
            ->get()
            ->getRow();

        return $row === null ? 1 : (int) $row->branch_id;
    }

    /**
     * Active branches the employee may post in, the default branch first.
     *
     * @return list<object>
     */
    public function selectableBranches(?int $employeeId): array
    {
        $builder = $this->db->table('branches')
            ->select('branch_id, code, name, is_default')
            ->where('company_id', CompanyContext::id())
            ->where('active', 1)
            ->orderBy('is_default', 'DESC')
            ->orderBy('code', 'ASC');

        $allowed = $this->allowedBranchIds($employeeId);

        if ($allowed !== null) {
            if ($allowed === []) {
                return [];
            }

            $builder->whereIn('branch_id', $allowed);
        }

        return $builder->get()->getResult();
    }

    /**
     * The branch columns, filters and switcher appear only for someone who can work in more than
     * one branch.
     */
    public function seesBranches(?int $employeeId): bool
    {
        return count($this->selectableBranches($employeeId)) > 1;
    }
}
