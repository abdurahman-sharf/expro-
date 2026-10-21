<?php

namespace App\Libraries\Accounting;

use App\Models\Employee;

/**
 * Permission checks for the accounting services. A null employee is the system
 * itself (a console command or an automatic posting) and is always allowed.
 */
final class AccountingGuard
{
    public const CHART_MANAGE        = 'acc_chart_manage';
    public const COST_CENTERS_MANAGE = 'acc_cost_centers_manage';
    public const JOURNALS_ALL        = 'acc_journals_all';
    public const JOURNALS_DRAFT      = 'acc_journals_draft';
    public const JOURNALS_POST       = 'acc_journals_post';
    public const JOURNALS_REVERSE    = 'acc_journals_reverse';
    public const JOURNALS_AUDIT      = 'acc_journals_audit';

    private Employee $employee;

    public function __construct(?Employee $employee = null)
    {
        $this->employee = $employee ?? model(Employee::class);
    }

    public function has(string $permissionId, ?int $employeeId): bool
    {
        return $employeeId === null || $this->employee->has_grant($permissionId, $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function require(string $permissionId, ?int $employeeId): void
    {
        if (! $this->has($permissionId, $employeeId)) {
            throw new AccountingException(AccountingError::PermissionDenied, "Missing permission {$permissionId}.", ['permission' => $permissionId]);
        }
    }
}
