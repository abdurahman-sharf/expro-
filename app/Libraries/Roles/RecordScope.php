<?php

namespace App\Libraries\Roles;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Whether an employee sees every record of the document families (sales, purchases, expenses and
 * the payments of customers and suppliers) or only the ones they created, and what that cuts. A
 * record is the employee's own when the employee column of the document names them. The scope is
 * "own" when the employee is set to see their own records only or when any role they hold is, active
 * or not, so the narrowest wins: a role can never widen what an employee was narrowed to, and
 * deactivating a role never widens its holders. Whoever holds no scope, and the books acting
 * without an employee, see everything, as it always was.
 */
final class RecordScope
{
    public const ALL    = 'all';
    public const OWN    = 'own';
    public const SCOPES = [self::ALL, self::OWN];

    private BaseConnection $db;
    private ?bool $available = null;
    private int $seen        = 0;

    /**
     * @var array<int, bool>
     */
    private array $memo = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * The employee on whose behalf the request runs, null for the system itself.
     */
    public static function sessionEmployee(): ?int
    {
        $personId = session()->get('person_id');

        return is_numeric($personId) && (int) $personId > 0 ? (int) $personId : null;
    }

    public function ownOnly(?int $employeeId): bool
    {
        if ($employeeId === null) {
            return false;
        }

        if ($this->seen !== RoleGrants::generation()) {
            $this->memo = [];
            $this->seen = RoleGrants::generation();
        }

        return $this->memo[$employeeId] ??= $this->read($employeeId);
    }

    /**
     * The employee a list of records has to be cut down to, or null when nothing is cut.
     */
    public function limit(?int $viewerId): ?int
    {
        return $this->ownOnly($viewerId) ? $viewerId : null;
    }

    /**
     * Whether the viewer may see, open or change a record made by the owner. A record that names
     * no employee belongs to nobody, so someone who sees their own records only does not see it.
     */
    public function allows(?int $viewerId, mixed $ownerId): bool
    {
        if (! $this->ownOnly($viewerId)) {
            return true;
        }

        return is_numeric($ownerId) && (int) $ownerId === $viewerId;
    }

    public function cut(BaseBuilder $builder, string $column, ?int $viewerId): void
    {
        $limit = $this->limit($viewerId);

        if ($limit !== null) {
            $builder->where($column, $limit);
        }
    }

    /**
     * The condition that keeps the records of the viewer in a hand-written query, with its binding,
     * or an empty condition when nothing is cut.
     *
     * @return array{string, list<int>}
     */
    public function condition(string $column, ?int $viewerId): array
    {
        $limit = $this->limit($viewerId);

        return $limit === null ? ['', []] : ["{$column} = ?", [$limit]];
    }

    private function read(int $employeeId): bool
    {
        if (! $this->tablesExist()) {
            return false;
        }

        $own = $this->db->table('employee_record_scopes')->where(['person_id' => $employeeId, 'record_scope' => self::OWN])->countAllResults() > 0;

        if ($own) {
            return true;
        }

        return $this->db->table('employee_roles er')
            ->join('roles r', 'r.role_id = er.role_id')
            ->where(['er.person_id' => $employeeId, 'r.record_scope' => self::OWN])
            ->countAllResults() > 0;
    }

    private function tablesExist(): bool
    {
        return $this->available ??= RoleTables::exist($this->db, 'employee_record_scopes', 'employee_roles', 'roles');
    }
}
