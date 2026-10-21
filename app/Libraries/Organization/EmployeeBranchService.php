<?php

namespace App\Libraries\Organization;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Which branches an employee belongs to. Changing it is a sensitive act: it needs its own
 * permission, nobody changes their own, nobody hands out or touches more than they can see, and the
 * last employee with every branch is never taken off it.
 */
final class EmployeeBranchService
{
    use RunsInTransaction;

    private BaseConnection $db;
    private BranchGuard $guard;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $guard = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->guard = $guard ?? new BranchGuard($this->db);
        $this->audit = new AuditLog($this->db);
    }

    /**
     * @return array{all: bool, branch_ids: list<int>, default_branch_id: ?int}
     */
    public function scopeOf(int $personId): array
    {
        $rows = $this->db->table('employee_branches')->where('person_id', $personId)->orderBy('branch_id', 'ASC')->get()->getResult();

        $default = null;

        foreach ($rows as $row) {
            if ((int) $row->is_default === 1) {
                $default = (int) $row->branch_id;
            }
        }

        return [
            'all'               => $this->guard->isUnrestricted($personId),
            'branch_ids'        => array_map(static fn (object $row): int => (int) $row->branch_id, $rows),
            'default_branch_id' => $default,
        ];
    }

    public function canManageScope(?int $actorId, ?int $targetId = null): bool
    {
        return $this->guard->has(BranchGuard::SCOPE_MANAGE, $actorId) && ($targetId === null || $actorId === null || $actorId !== $targetId);
    }

    /**
     * Sets the branches of an employee. The employee's branches the actor cannot see stay as they
     * are.
     *
     * @param list<int> $branchIds
     *
     * @throws BranchException
     */
    public function setScope(?int $actorId, int $targetId, bool $allBranches, array $branchIds, ?int $defaultBranchId = null): void
    {
        $plan = $this->plan($actorId, $targetId, $allBranches, $branchIds, $defaultBranchId);

        $this->transactional(function () use ($targetId, $allBranches, $plan, $actorId): void {
            $this->db->table('employee_branches')->where('person_id', $targetId)->delete();

            foreach ($plan['branch_ids'] as $branchId) {
                $this->db->table('employee_branches')->insert(['person_id' => $targetId, 'branch_id' => $branchId, 'is_default' => $branchId === $plan['default'] ? 1 : 0]);
            }

            $this->setAllBranchesGrant($targetId, $allBranches);

            $this->audit->write('employee_scope', $targetId, 'scope_changed', $actorId, JournalOrigin::User, [
                'before' => $plan['current'],
                'after'  => ['all' => $allBranches, 'branch_ids' => $plan['branch_ids'], 'default_branch_id' => $plan['default']],
            ]);
        });
    }

    /**
     * Applies every rule of setScope without writing, so a screen can refuse a request before it
     * saves anything else. A null target is an employee that does not exist yet.
     *
     * @param list<int> $branchIds
     *
     * @throws BranchException
     */
    public function validateScope(?int $actorId, ?int $targetId, bool $allBranches, array $branchIds, ?int $defaultBranchId = null): void
    {
        $this->plan($actorId, $targetId, $allBranches, $branchIds, $defaultBranchId);
    }

    /**
     * Run after the employee screen saved an employee: the screen rewrites every grant, so the
     * all-branches grant is put back, and a new employee gets the starting branch.
     *
     * @param array{all: bool, branch_ids: list<int>, default_branch_id: ?int}|null $previous  The scope before the save, null for a new employee.
     * @param array{all: bool, branch_ids: list<int>, default_branch_id: ?int}|null $requested The scope the screen sent, null when it sent none.
     *
     * @throws BranchException
     */
    public function afterEmployeeSave(?int $actorId, int $personId, ?array $previous, ?array $requested): void
    {
        if ($previous !== null && $previous['all']) {
            $this->setAllBranchesGrant($personId, true);
        }

        if ($requested !== null && $this->canManageScope($actorId, $personId)) {
            $this->setScope($actorId, $personId, $requested['all'], $requested['branch_ids'], $requested['default_branch_id']);

            return;
        }

        if ($previous === null && $this->db->table('employee_branches')->where('person_id', $personId)->countAllResults() === 0) {
            $this->db->table('employee_branches')->insert([
                'person_id'  => $personId,
                'branch_id'  => $this->guard->defaultBranchId($actorId),
                'is_default' => 1,
            ]);
        }
    }

    /**
     * @param list<int> $branchIds
     *
     * @return array{branch_ids: list<int>, default: ?int, current: array{all: bool, branch_ids: list<int>, default_branch_id: ?int}}
     *
     * @throws BranchException
     */
    private function plan(?int $actorId, ?int $targetId, bool $allBranches, array $branchIds, ?int $defaultBranchId): array
    {
        if (! $this->guard->has(BranchGuard::SCOPE_MANAGE, $actorId)) {
            throw new BranchException(BranchError::PermissionDenied, 'Missing permission ' . BranchGuard::SCOPE_MANAGE . '.');
        }

        if ($targetId !== null && $actorId !== null && $actorId === $targetId) {
            throw new BranchException(BranchError::OwnScope, 'Nobody changes their own branches.');
        }

        if ($targetId !== null && $this->db->table('employees')->where('person_id', $targetId)->where('deleted', 0)->countAllResults() === 0) {
            throw new BranchException(BranchError::EmployeeNotFound, "Employee {$targetId} does not exist.");
        }

        $current      = $targetId === null ? ['all' => false, 'branch_ids' => [], 'default_branch_id' => null] : $this->scopeOf($targetId);
        $branchIds    = array_values(array_unique(array_map('intval', $branchIds)));
        $actorAllowed = $this->guard->allowedBranchIds($actorId);

        if ($actorAllowed !== null && ($allBranches || $current['all'])) {
            throw new BranchException(BranchError::ScopeTooWide, 'Only someone with every branch can give or change every branch.');
        }

        $this->assertBranchesExist($branchIds);

        if ($actorAllowed !== null) {
            foreach ($branchIds as $branchId) {
                if (! in_array($branchId, $actorAllowed, true)) {
                    throw new BranchException(BranchError::ScopeTooWide, "The branch {$branchId} is outside your own branches.", ['branch_id' => $branchId]);
                }
            }

            $preserved = array_values(array_filter($current['branch_ids'], static fn (int $id): bool => ! in_array($id, $actorAllowed, true)));
            $branchIds = array_values(array_unique([...$branchIds, ...$preserved]));
        }

        if (! $allBranches && $branchIds === []) {
            throw new BranchException(BranchError::NoBranch, 'An employee needs at least one branch or every branch.');
        }

        $default = $defaultBranchId ?? ($branchIds[0] ?? null);

        if ($default !== null && $branchIds !== [] && ! in_array($default, $branchIds, true)) {
            throw new BranchException(BranchError::InvalidDefault, 'The default branch must be one of the employee branches.');
        }

        if ($targetId !== null && $current['all'] && ! $allBranches && $this->otherUnrestrictedEmployees($targetId) === 0) {
            throw new BranchException(BranchError::LastUnrestricted, 'The last employee with every branch keeps it.');
        }

        return ['branch_ids' => $branchIds, 'default' => $default, 'current' => $current];
    }

    private function setAllBranchesGrant(int $personId, bool $granted): void
    {
        $this->db->table('grants')->where('person_id', $personId)->where('permission_id', BranchGuard::ALL_BRANCHES)->delete();

        if ($granted) {
            $this->db->table('grants')->insert(['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => $personId, 'menu_group' => '--']);
        }
    }

    /**
     * @param list<int> $branchIds
     */
    private function assertBranchesExist(array $branchIds): void
    {
        if ($branchIds === []) {
            return;
        }

        $found = $this->db->table('branches')->where('company_id', CompanyContext::id())->whereIn('branch_id', $branchIds)->countAllResults();

        if ($found !== count($branchIds)) {
            throw new BranchException(BranchError::NotFound, 'A branch does not exist.');
        }
    }

    private function otherUnrestrictedEmployees(int $exceptPersonId): int
    {
        return $this->db->table('grants g')
            ->join('employees e', 'e.person_id = g.person_id')
            ->where('g.permission_id', BranchGuard::ALL_BRANCHES)
            ->where('e.deleted', 0)
            ->where('g.person_id !=', $exceptPersonId)
            ->countAllResults();
    }
}
