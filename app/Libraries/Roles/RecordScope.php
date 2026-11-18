<?php

namespace App\Libraries\Roles;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\ResultInterface;
use Config\Database;
use Throwable;

/**
 * Whether an employee sees every record of the document families (sales, purchases, expenses and
 * the payments of customers and suppliers) or only the ones they created, and what that cuts. A
 * record is the employee's own when the employee column of the document names them. The scope is
 * "own" when the employee is set to see their own records only or when any role they hold is, active
 * or not, so the narrowest wins: a role can never widen what an employee was narrowed to, and
 * deactivating a role never widens its holders. Whoever holds no scope, and the books acting
 * without an employee, see everything, as it always was. A scope that cannot be read is never taken
 * for no scope: a connection without debugging, or inside a transaction, answers a failed query
 * with false and a count of it with 0, so the read fails with RecordScopeUnavailableException.
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
            $this->memo      = [];
            $this->available = null;
            $this->seen      = RoleGrants::generation();
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
        if (! $this->migrated($employeeId)) {
            return false;
        }

        $scopes = $this->db->table('employee_record_scopes')->where(['person_id' => $employeeId, 'record_scope' => self::OWN]);

        if ($this->exists($employeeId, 'employee_record_scopes', $scopes)) {
            return true;
        }

        $roles = $this->db->table('employee_roles er')
            ->join('roles r', 'r.role_id = er.role_id')
            ->where(['er.person_id' => $employeeId, 'r.record_scope' => self::OWN]);

        return $this->exists($employeeId, 'employee_roles', $roles);
    }

    private function exists(int $employeeId, string $table, BaseBuilder $builder): bool
    {
        try {
            $result = $builder->select('1', false)->limit(1)->get();
        } catch (DatabaseException $exception) {
            throw $this->unavailable($employeeId, $table, $exception);
        }

        if (! $result instanceof ResultInterface) {
            throw $this->unavailable($employeeId, $table);
        }

        return $result->getRowArray() !== null;
    }

    private function migrated(int $employeeId): bool
    {
        try {
            return $this->available ??= RoleTables::migrated($this->db);
        } catch (Throwable $exception) {
            throw $this->unavailable($employeeId, 'the record of the roles migration', $exception);
        }
    }

    private function unavailable(int $employeeId, string $source, ?Throwable $previous = null): RecordScopeUnavailableException
    {
        return new RecordScopeUnavailableException(
            $employeeId,
            sprintf('The record scope of the employee %d could not be read from %s: %s', $employeeId, $source, $previous?->getMessage() ?? $this->driverError()),
            $previous,
        );
    }

    private function driverError(): string
    {
        try {
            $error = $this->db->error();

            if (is_array($error) && ! empty($error['message'])) {
                return (string) $error['message'];
            }
        } catch (Throwable) {
            // A disconnected connection may not answer error(), and the failure is reported all the same.
        }

        return 'unknown error';
    }
}
