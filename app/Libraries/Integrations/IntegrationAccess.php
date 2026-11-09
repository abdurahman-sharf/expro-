<?php

namespace App\Libraries\Integrations;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Models\Employee;

/**
 * Who may change the integration settings. The screens need the grant of the configuration screens
 * and, beside it, the permission of what they change: the keys of the API need a sensitive permission
 * and every branch, because a key reads the books of the whole company or of the branch it names. A null
 * employee is the system itself, a console command, and is always allowed.
 */
final class IntegrationAccess
{
    public const MODULE               = 'integrations';
    public const NOTIFICATIONS_MODULE = 'notifications';
    public const CONFIG               = 'config';
    public const MANAGE               = 'acc_integrations_manage';
    public const API_KEYS             = 'api_keys_manage';

    private Employee $employee;
    private BranchGuard $branches;

    public function __construct(?Employee $employee = null, ?BranchGuard $branches = null)
    {
        $this->employee = $employee ?? model(Employee::class);
        $this->branches = $branches ?? new BranchGuard(null, $this->employee);
    }

    public function holdsConfig(?int $employeeId): bool
    {
        return $employeeId === null || $this->employee->has_module_grant(self::CONFIG, $employeeId);
    }

    public function mayManage(?int $employeeId): bool
    {
        return $employeeId === null || $this->employee->has_grant(self::MANAGE, $employeeId);
    }

    public function mayManageKeys(?int $employeeId): bool
    {
        return $employeeId === null || ($this->employee->has_grant(self::API_KEYS, $employeeId) && $this->branches->isUnrestricted($employeeId));
    }

    /**
     * @throws IntegrationException
     */
    public function requireManage(?int $employeeId): void
    {
        if (! $this->mayManage($employeeId)) {
            throw new IntegrationException(IntegrationError::PermissionDenied, 'Missing permission ' . self::MANAGE . '.', ['permission' => self::MANAGE]);
        }
    }

    /**
     * @throws BranchException|IntegrationException
     */
    public function requireKeys(?int $employeeId): void
    {
        if ($employeeId !== null && ! $this->employee->has_grant(self::API_KEYS, $employeeId)) {
            throw new IntegrationException(IntegrationError::PermissionDenied, 'Missing permission ' . self::API_KEYS . '.', ['permission' => self::API_KEYS]);
        }

        $this->branches->requireCompanyScope($employeeId);
    }
}
