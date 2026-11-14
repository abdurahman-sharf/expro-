<?php

namespace App\Models;

use App\Libraries\Organization\EmployeeBranchService;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Session\Session;
use stdClass;

/**
 * Employee class
 *
 * @property Session session
 */
class Employee extends Person
{
    public Session $session;
    private ?RoleGrants $roleGrants = null;
    protected $table                = 'Employees';
    protected $primaryKey           = 'person_id';
    protected $useAutoIncrement     = false;
    protected $useSoftDeletes       = false;
    protected $allowedFields        = [
        'username',
        'password',
        'deleted',
        'hashversion',
        'language',
        'language_code',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->session = session();
    }

    /**
     * Determines if a given person_id is an employee
     */
    public function exists(int $person_id): bool
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->where('employees.person_id', $person_id);

        return $builder->get()->getNumRows() === 1;    // TODO: ===
    }

    public function username_exists(int $employee_id, string $username): bool
    {
        $builder = $this->db->table('employees');
        $builder->where('employees.username', $username);
        $builder->where('employees.person_id <>', $employee_id);

        return $builder->get()->getNumRows() === 1;    // TODO: ===
    }

    /**
     * Gets total of rows
     */
    public function get_total_rows(): int
    {
        $builder = $this->db->table('employees');
        $builder->where('deleted', 0);

        return $builder->countAllResults();
    }

    /**
     * Returns all the employees
     */
    public function get_all(int $limit = 10000, int $offset = 0): ResultInterface
    {
        $builder = $this->db->table('employees');
        $builder->where('deleted', 0);
        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->orderBy('last_name', 'asc');
        $builder->limit($limit);
        $builder->offset($offset);

        return $builder->get();
    }

    /**
     * Gets information about a particular employee
     */
    public function get_info(int $person_id): object
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->where('employees.person_id', $person_id);
        $query = $builder->get();

        if ($query->getNumRows() === 1) {    // TODO: ===
            return $query->getRow();
        }

        // Get empty base parent object, as $employee_id is NOT an employee
        $person_obj = parent::get_info(NEW_ITEM);

        // Get all the fields from employee table
        // Append those fields to base parent object, we have a complete empty object
        foreach ($this->db->getFieldNames('employees') as $field) {
            $person_obj->{$field} = null;
        }

        return $person_obj;
    }

    /**
     * Gets information about multiple employees
     */
    public function get_multiple_info(array $person_ids): ResultInterface
    {
        $builder = $this->db->table('employees');
        $builder->join('people', 'people.person_id = employees.person_id');
        $builder->whereIn('employees.person_id', $person_ids);
        $builder->orderBy('last_name', 'asc');

        return $builder->get();
    }

    /**
     * Inserts or updates an employee
     */
    public function save_employee(array &$person_data, array &$employee_data, array &$grants_data, int $employee_id = NEW_ENTRY): bool
    {
        $success               = true;
        $isNewEmployee         = ($employee_id === NEW_ENTRY || ! $this->exists($employee_id));
        $grantChangeDisallowed = filter_var(getenv('DISALLOW_GRANT_CHANGE'), FILTER_VALIDATE_BOOLEAN);

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        if ($grantChangeDisallowed && $isNewEmployee && ! empty($grants_data)) {
            $this->db->transComplete();

            return false;
        }

        $personSaved = parent::save_value($person_data, $employee_id);

        if ($isNewEmployee && ! $personSaved) {
            // A new employee must have a person record; abort if the insert failed
            $this->db->transComplete();

            return false;
        }

        if ($personSaved) {
            if ($isNewEmployee) {
                $employee_data['person_id'] = $employee_id = $person_data['person_id'];
                $success                    = $this->db->table('employees')->insert($employee_data);
            } else {
                $success = $success && $this->db->table('employees')->where('person_id', $employee_id)->update($employee_data);
            }
        }

        // Grants update is gated only by the DISALLOW_GRANT_CHANGE flag, not by
        // whether person/employee data was actually written (a 0-row affected
        // update on existing data is a no-op, not a failure).
        if (! $grantChangeDisallowed && ! empty($grants_data)) {
            $success = $success && $this->db->table('grants')->delete(['person_id' => $employee_id]);

            foreach ($grants_data as $grant) {
                $data = [
                    'permission_id' => $grant['permission_id'],
                    'person_id'     => $employee_id,
                    'menu_group'    => $grant['menu_group'],
                ];

                $success = $success && $this->db->table('grants')->insert($data);
            }
        }

        $this->db->transComplete();

        $success = $success && $this->db->transStatus();

        return $success;
    }

    /**
     * Deletes one employee
     *
     * @param mixed|null $employee_id
     */
    public function delete($employee_id = null, bool $purge = false): bool
    {
        $success = false;

        // Don't let employees delete themselves
        if ($employee_id === $this->get_logged_in_employee_info()->person_id) {
            return false;
        }

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        if (! (new EmployeeBranchService($this->db))->wouldLeaveNobodyWithEveryBranch([$employee_id])) {
            // Delete permissions
            $builder = $this->db->table('grants');

            if ($builder->delete(['person_id' => $employee_id])) {
                $builder = $this->db->table('employees');
                $builder->where('person_id', $employee_id);
                $success = $builder->update(['deleted' => 1]);

                (new RoleService($this->db, $this))->releaseEmployees([$employee_id], (int) $this->session->get('person_id') ?: null);
            }
        }

        $this->db->transComplete();

        return $success;
    }

    /**
     * Deletes a list of employees
     */
    public function delete_list(array $person_ids): bool
    {
        $success = false;

        // Don't let employees delete themselves
        if (in_array($this->get_logged_in_employee_info()->person_id, $person_ids, true)) {
            return false;
        }

        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();

        if (! (new EmployeeBranchService($this->db))->wouldLeaveNobodyWithEveryBranch($person_ids)) {
            $builder = $this->db->table('grants');
            $builder->whereIn('person_id', $person_ids);
            // Delete permissions
            if ($builder->delete()) {
                // Delete from employee table
                $builder = $this->db->table('employees');
                $builder->whereIn('person_id', $person_ids);
                $success = $builder->update(['deleted' => 1]);

                (new RoleService($this->db, $this))->releaseEmployees($person_ids, (int) $this->session->get('person_id') ?: null);
            }
        }

        $this->db->transComplete();
        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * Get search suggestions to find employees
     */
    public function get_search_suggestions(string $search, int $limit = 25, bool $unique = false): array
    {
        $suggestions = [];

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->groupStart();
        $builder->like('first_name', $search);
        $builder->orLike('last_name', $search);
        $builder->orLike('CONCAT(first_name, " ", last_name)', $search);
        $builder->groupEnd();

        if (! $unique) {
            $builder->where('deleted', 0);
        }

        $builder->orderBy('last_name', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->first_name . ' ' . $row->last_name];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (! $unique) {
            $builder->where('deleted', 0);
        }

        $builder->like('email', $search);
        $builder->orderBy('email', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->email];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (! $unique) {
            $builder->where('deleted', 0);
        }

        $builder->like('username', $search);
        $builder->orderBy('username', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->username];
        }

        $builder = $this->db->table('employees');
        $builder->join('people', 'employees.person_id = people.person_id');

        if (! $unique) {
            $builder->where('deleted', 0);
        }

        $builder->like('phone_number', $search);
        $builder->orderBy('phone_number', 'asc');

        foreach ($builder->get()->getResult() as $row) {
            $suggestions[] = ['value' => $row->person_id, 'label' => $row->phone_number];
        }

        // Only return $limit suggestions
        if (count($suggestions) > $limit) {
            $suggestions = array_slice($suggestions, 0, $limit);
        }

        return $suggestions;
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search): int
    {
        return $this->search($search, 0, 0, 'last_name', 'asc', true);
    }

    /**
     * Performs a search on employees
     */
    public function search(string $search, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'last_name', ?string $order = 'asc', ?bool $count_only = false)
    {
        // Set default values
        if ($rows === null) {
            $rows = 0;
        }
        if ($limit_from === null) {
            $limit_from = 0;
        }
        if ($sort === null) {
            $sort = 'last_name';
        }
        if ($order === null) {
            $order = 'asc';
        }
        if ($count_only === null) {
            $count_only = false;
        }

        $builder = $this->db->table('employees AS employees');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(employees.person_id) as count');
        }

        $builder->join('people', 'employees.person_id = people.person_id');
        $builder->groupStart();
        $builder->like('first_name', $search);
        $builder->orLike('last_name', $search);
        $builder->orLike('email', $search);
        $builder->orLike('phone_number', $search);
        $builder->orLike('username', $search);
        $builder->orLike('CONCAT(first_name, " ", last_name)', $search);
        $builder->groupEnd();
        $builder->where('deleted', 0);

        // get_found_rows case
        if ($count_only) {
            return $builder->get()->getRow()->count;
        }

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Attempts to log in employee and set session. Returns boolean based on outcome.
     */
    public function login(string $username, string $password): bool
    {
        $builder = $this->db->table('employees');
        $query   = $builder->getWhere(['username' => $username, 'deleted' => 0], 1);

        if ($query->getNumRows() === 1) {
            $row = $query->getRow();

            // Compare passwords depending on the hash version
            if ($row->hash_version === '1' && $row->password === md5($password)) {
                $builder->where('person_id', $row->person_id);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $updated      = $builder->update(['hash_version' => 2, 'password' => $passwordHash]);

                if ($updated) {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        $this->session->regenerate(true);
                    }
                    $this->session->set('person_id', $row->person_id);
                }

                return $updated;
            }
            if ($row->hash_version === '2' && password_verify($password, $row->password)) {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $this->session->regenerate(true);
                }
                $this->session->set('person_id', $row->person_id);

                return true;
            }
        }

        return false;
    }

    /**
     * Logs out a user by destroying all session data and redirect to log in
     */
    public function logout(): void
    {
        session()->destroy();
    }

    /**
     * Determines if an employee is logged in
     */
    public function is_logged_in(): bool
    {
        return $this->session->get('person_id') !== false;
    }

    /**
     * Gets information about the currently logged in employee.
     */
    public function get_logged_in_employee_info(): array|false|float|int|stdClass|string|null
    {
        if ($this->is_logged_in()) {
            return $this->get_info($this->session->get('person_id'));
        }

        return false;
    }

    /**
     * Determines whether the employee has access to a module, through their own grants or through their
     * roles. The module opens for the holder of its own grant, and, when the catalog files sub-permissions
     * under it with an id that begins with the module id and an underscore (the stock locations of items,
     * sales and receivings, the reports and the recurring documents), for the holder of its own grant and at
     * least one of them. Sub-permissions alone never open it: a stock location reaches every employee when
     * it is created, so two of them cannot stand for the module. The permission of another module never
     * counts, whatever its id begins with. The empty id asks for at least two grants of any kind.
     */
    public function has_module_grant(string $permissionId, int $personId): bool
    {
        $subpermissions = $this->subpermissionIds($permissionId);
        $countedLower   = array_map('strtolower', [$permissionId, ...$subpermissions]);

        $builder = $this->db->table('grants');
        $builder->select('permission_id');
        $builder->where('person_id', $personId);

        if ($permissionId !== '') {
            $builder->whereIn('permission_id', [$permissionId, ...$subpermissions]);
        }

        $held = array_map('strtolower', array_column($builder->get()->getResultArray(), 'permission_id'));

        foreach ($this->roleGrants()->idsFor($personId) as $roleGranted) {
            if ($permissionId === '' || in_array(strtolower($roleGranted), $countedLower, true)) {
                $held[] = strtolower($roleGranted);
            }
        }

        $held = array_unique($held);

        if ($permissionId === '') {
            return count($held) > 1;
        }

        if (! in_array(strtolower($permissionId), $held, true)) {
            return false;
        }

        return count($held) > 1 || $subpermissions === [];
    }

    /**
     * Whether the module has no sub-permission, so that its own grant alone opens it
     */
    public function has_subpermissions(string $permissionId): bool
    {
        return $this->subpermissionIds($permissionId) === [];
    }

    /**
     * @return list<string>
     */
    private function subpermissionIds(string $moduleId): array
    {
        $builder = $this->db->table('permissions');
        $builder->select('permission_id');
        $builder->where('module_id', $moduleId);

        $prefix = strtolower($moduleId) . '_';

        return array_values(array_filter(
            array_column($builder->get()->getResultArray(), 'permission_id'),
            static fn (string $permissionId): bool => str_starts_with(strtolower($permissionId), $prefix),
        ));
    }

    /**
     * Determines whether the employee specified employee has access the specific module. The
     * permissions of the employee are their own grants and those of their roles, which is read here
     * and nowhere else, so every permission check of the application sees both. With $directOnly
     * only the grants of the employee themselves count.
     */
    public function has_grant(?string $permission_id, ?int $person_id, bool $directOnly = false): bool
    {
        // If no module_id is null, allow access
        if ($permission_id === null) {
            return true;
        }
        if ($person_id === null) {
            return false;
        }

        return $this->hasDirectGrant($permission_id, $person_id) || (! $directOnly && $this->roleGrants()->has($person_id, $permission_id));
    }

    /**
     * Whether the grants of the employee themselves hold the permission, whatever their roles give.
     * What the employee form shows and saves, and who counts as an administrator, rests on this.
     */
    public function hasDirectGrant(?string $permissionId, ?int $personId): bool
    {
        if ($permissionId === null || $personId === null) {
            return false;
        }

        $query = $this->db->table('grants')->getWhere(['person_id' => $personId, 'permission_id' => $permissionId], 1);

        return $query->getNumRows() === 1;
    }

    /**
     * Returns the menu group designation that this module is to appear in
     */
    public function get_menu_group(string $permission_id, ?int $person_id): string
    {
        $builder = $this->db->table('grants');
        $builder->select('menu_group');
        $builder->where('permission_id', $permission_id);
        $builder->where('person_id', $person_id);

        $row = $builder->get()->getRow();

        // If no grants are assigned yet then set the default to 'home'
        if ($row === null) {
            return 'home';
        }

        return $row->menu_group;
    }

    /**
     * Gets employee permission grants: their own and, after them, the ones their roles give
     */
    public function get_employee_grants(int $person_id): array
    {
        $grants = $this->getDirectGrants($person_id);
        $direct = array_column($grants, 'permission_id');

        foreach ($this->roleGrants()->idsFor($person_id) as $permissionId) {
            if (! in_array($permissionId, $direct, true)) {
                $grants[] = ['permission_id' => $permissionId, 'person_id' => $person_id, 'menu_group' => '--'];
            }
        }

        return $grants;
    }

    /**
     * Gets the grants of the employee themselves, without those of their roles
     */
    public function getDirectGrants(int $personId): array
    {
        return $this->db->table('grants')->where('person_id', $personId)->get()->getResultArray();
    }

    /**
     * Attempts to log in employee and set session. Returns boolean based on outcome.
     */
    public function check_password(string $username, string $password): bool
    {
        $builder = $this->db->table('employees');
        $query   = $builder->getWhere(['username' => $username, 'deleted' => 0], 1);

        if ($query->getNumRows() === 1) {    // TODO: ===
            $row = $query->getRow();

            if (password_verify($password, $row->password)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Change password for the employee
     *
     * @param mixed $employee_id
     */
    public function change_password(array $employee_data, $employee_id = false): bool
    {
        $success = false;

        if (! filter_var(getenv('DISALLOW_PASSWORD_CHANGE'), FILTER_VALIDATE_BOOLEAN)) {
            $this->db->transStart();

            $builder = $this->db->table('employees');
            $builder->where('person_id', $employee_id);
            $success = $builder->update($employee_data);

            $this->db->transComplete();

            $success &= $this->db->transStatus();
        }

        return $success;
    }

    /**
     * Checks if the employee has admin privileges (all module permissions).
     * The first employee (person_id = 1) is considered admin by default. Only the grants of the
     * employee count: a role never makes anyone an administrator.
     */
    public function isAdmin(int $person_id): bool
    {
        if ($person_id === 1) {
            return true;
        }

        foreach (ADMIN_MODULES as $module) {
            if (! $this->has_grant($module, $person_id, true)) {
                return false;
            }
        }

        return true;
    }

    private function roleGrants(): RoleGrants
    {
        return $this->roleGrants ??= new RoleGrants($this->db);
    }

    /**
     * Checks if current user can modify target employee.
     * Only admins can modify other admin accounts.
     * Users cannot modify their own grants unless they are admin.
     */
    public function canModifyEmployee(int $target_person_id, int $current_person_id): bool
    {
        if ($target_person_id === $current_person_id) {
            return ! $this->isAdmin($target_person_id) || $this->isAdmin($current_person_id);
        }

        return ! ($this->isAdmin($target_person_id) && ! $this->isAdmin($current_person_id));
    }
}
