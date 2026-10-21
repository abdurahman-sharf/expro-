<?php

namespace App\Libraries\Organization;

use CodeIgniter\Session\Session;

/**
 * The branch an employee has chosen to work in. It is a preference that sets the default branch
 * and the list filters. It is never what grants access: the guard checks the scope on every
 * request.
 */
final class BranchContext
{
    private const SESSION_KEY = 'current_branch_id';
    private const ALL         = 'all';

    private Session $session;
    private BranchGuard $guard;

    public function __construct(?BranchGuard $guard = null, ?Session $session = null)
    {
        $this->guard   = $guard ?? new BranchGuard();
        $this->session = $session ?? session();
    }

    /**
     * The chosen branch, null for "every branch I can see".
     */
    public function current(?int $employeeId): ?int
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if ($stored === null || $stored === self::ALL) {
            return null;
        }

        $branchId = (int) $stored;

        return $this->isSelectable($branchId, $employeeId) ? $branchId : null;
    }

    /**
     * The branch a new document starts in.
     */
    public function formDefault(?int $employeeId): int
    {
        return $this->current($employeeId) ?? $this->guard->defaultBranchId($employeeId);
    }

    /**
     * @throws BranchException
     */
    public function switchTo(?int $employeeId, ?int $branchId): void
    {
        if ($branchId === null) {
            $this->session->set(self::SESSION_KEY, self::ALL);

            return;
        }

        if (! $this->isSelectable($branchId, $employeeId)) {
            throw new BranchException(BranchError::NotAllowed, "The branch {$branchId} cannot be chosen.", ['branch_id' => $branchId]);
        }

        $this->session->set(self::SESSION_KEY, (string) $branchId);
    }

    private function isSelectable(int $branchId, ?int $employeeId): bool
    {
        foreach ($this->guard->selectableBranches($employeeId) as $branch) {
            if ((int) $branch->branch_id === $branchId) {
                return true;
            }
        }

        return false;
    }
}
