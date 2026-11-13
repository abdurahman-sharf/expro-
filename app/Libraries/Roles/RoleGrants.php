<?php

namespace App\Libraries\Roles;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Documents\CustomFields;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The permission ids an employee holds through the roles they have: the second source of the
 * effective permissions, next to their own grants, read once for each employee and request. A role
 * never gives a sensitive permission, an accounting permission that posts to the books, moves money
 * or changes the settings, or a branch scope, even if a row that says so were written past the role
 * service, and an inactive role, a deleted employee or a missing table give nothing. The accounting
 * permissions a role may carry are the ones that only read or prepare.
 */
final class RoleGrants
{
    public const REFUSED = [
        AccountingGuard::CHART_MANAGE,
        AccountingGuard::COST_CENTERS_MANAGE,
        AccountingGuard::JOURNALS_POST,
        AccountingGuard::JOURNALS_REVERSE,
        AccountingGuard::PERIODS_MANAGE,
        AccountingGuard::CHEQUES_MANAGE,
        AccountingGuard::DEBIT_NOTES_MANAGE,
        AccountingGuard::ASSETS_MANAGE,
        IntegrationAccess::MANAGE,
        CustomFields::PERMISSION,
    ];
    public const ALLOWED_ACCOUNTING = [
        AccountingGuard::JOURNALS_ALL,
        AccountingGuard::JOURNALS_AUDIT,
        AccountingGuard::JOURNALS_DRAFT,
        AccountingGuard::BANK_RECONCILE,
    ];

    private static int $generation = 0;
    private BaseConnection $db;
    private ?bool $available = null;
    private int $seen        = 0;

    /**
     * @var array<int, list<string>>
     */
    private array $memo = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Makes every reader read again: a role, its permissions or who holds it has changed.
     */
    public static function flush(): void
    {
        self::$generation++;
    }

    public static function generation(): int
    {
        return self::$generation;
    }

    /**
     * The permissions a role can never hold: the sensitive ones, which only an administrator hands
     * to an employee, the accounting ones that post, move money or change the settings, and the
     * branch scope, which stays on the employee.
     *
     * @return list<string>
     */
    public static function forbidden(): array
    {
        return [...AccountingGuard::SENSITIVE, ...self::REFUSED, BranchGuard::ALL_BRANCHES, BranchGuard::SCOPE_MANAGE];
    }

    public function has(int $personId, string $permissionId): bool
    {
        return in_array($permissionId, $this->idsFor($personId), true);
    }

    /**
     * @return list<string>
     */
    public function idsFor(int $personId): array
    {
        if ($this->seen !== self::$generation) {
            $this->memo      = [];
            $this->available = null;
            $this->seen      = self::$generation;
        }

        return $this->memo[$personId] ??= $this->read($personId);
    }

    /**
     * @return list<string>
     */
    private function read(int $personId): array
    {
        if (! $this->tablesExist()) {
            return [];
        }

        $rows = $this->db->table('employee_roles er')
            ->distinct()
            ->select('rp.permission_id')
            ->join('roles r', 'r.role_id = er.role_id AND r.active = 1')
            ->join('role_permissions rp', 'rp.role_id = er.role_id')
            ->join('employees e', 'e.person_id = er.person_id AND e.deleted = 0')
            ->where('er.person_id', $personId)
            ->whereNotIn('rp.permission_id', self::forbidden())
            ->orderBy('rp.permission_id', 'ASC')
            ->get()
            ->getResultArray();

        return array_column($rows, 'permission_id');
    }

    private function tablesExist(): bool
    {
        return $this->available ??= RoleTables::exist($this->db, 'employee_roles', 'role_permissions', 'roles');
    }
}
