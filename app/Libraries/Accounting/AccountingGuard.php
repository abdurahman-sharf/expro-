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
    public const JOURNALS_EDIT       = 'acc_journals_edit_details';
    public const SETTINGS_MANAGE     = 'acc_settings_manage';
    public const PERIODS_MANAGE      = 'acc_periods_manage';
    public const PERIODS_CLOSE       = 'acc_periods_close';
    public const PERIODS_REOPEN      = 'acc_periods_reopen';
    public const OPENING_MANAGE      = 'acc_opening_manage';
    public const ROUTING_MANAGE      = 'acc_routing_manage';
    public const TREASURIES_MANAGE   = 'acc_treasuries_manage';
    public const PAYABLES_MANAGE     = 'acc_payables_manage';

    /**
     * Permissions that change what ordinary users cannot undo. Nothing grants them
     * automatically, and only an administrator grants or removes them.
     */
    public const SENSITIVE = [
        self::JOURNALS_EDIT,
        self::SETTINGS_MANAGE,
        self::PERIODS_CLOSE,
        self::PERIODS_REOPEN,
        self::OPENING_MANAGE,
        self::ROUTING_MANAGE,
        self::TREASURIES_MANAGE,
        self::PAYABLES_MANAGE,
    ];

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
