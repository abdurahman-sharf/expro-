<?php

namespace App\Libraries\Roles;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Models\Employee;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The stock location each employee starts in: the register and the receiving screen preselect it.
 * An employee has at most one, and one without any starts where they always did, in the first
 * location they may use. The preselection is a convenience and grants nothing: the screens still
 * offer only the locations the employee holds the permission for. Changing it needs the permission
 * to change employees, the same as the employee form that holds it.
 */
final class DefaultLocations
{
    use RunsInTransaction;

    private BaseConnection $db;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
    }

    public function of(int $personId): ?int
    {
        if ($personId < 1 || ! RoleTables::exist($this->db, 'employee_default_locations')) {
            return null;
        }

        $row = $this->db->table('employee_default_locations d')
            ->select('d.location_id')
            ->join('stock_locations l', 'l.location_id = d.location_id AND l.deleted = 0')
            ->where('d.person_id', $personId)
            ->get()
            ->getRow();

        return $row === null ? null : (int) $row->location_id;
    }

    /**
     * @throws RoleException
     */
    public function check(?int $locationId): void
    {
        if ($locationId !== null && $this->db->table('stock_locations')->where(['location_id' => $locationId, 'deleted' => 0])->countAllResults() === 0) {
            throw new RoleException(RoleError::LocationNotFound, "The stock location {$locationId} does not exist.", ['location_id' => $locationId]);
        }
    }

    /**
     * @throws RoleException
     */
    public function set(int $personId, ?int $locationId, ?int $actorId, ?Employee $employee = null): void
    {
        $employee ??= model(Employee::class);

        if ($actorId !== null && ! $employee->has_grant('employees', $actorId)) {
            throw new RoleException(RoleError::PermissionDenied, 'Missing permission employees.');
        }

        if ($this->db->table('employees')->where(['person_id' => $personId, 'deleted' => 0])->countAllResults() === 0) {
            throw new RoleException(RoleError::EmployeeNotFound, "The employee {$personId} does not exist.");
        }

        $this->check($locationId);

        $before = $this->of($personId);

        if ($before === $locationId) {
            return;
        }

        $this->transactional(function () use ($personId, $locationId, $before, $actorId): void {
            $this->db->table('employee_default_locations')->where('person_id', $personId)->delete();

            if ($locationId !== null) {
                $this->db->table('employee_default_locations')->insert(['person_id' => $personId, 'location_id' => $locationId]);
            }

            $this->audit->write('employee', $personId, 'default_location_set', $actorId, JournalOrigin::User, ['before' => $before, 'after' => $locationId]);
        });
    }
}
