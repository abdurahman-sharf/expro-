<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\SensitivePermissions;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\BranchService;
use App\Libraries\Organization\EmployeeBranchService;
use App\Libraries\Roles\DefaultLocations;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Roles\RoleException;
use App\Libraries\Roles\RoleService;
use App\Models\Module;
use App\Models\Stock_location;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 *
 *
 * @property module module
 *
 */
class Employees extends Persons
{
    private EmployeeBranchService $branchScopes;
    private SensitivePermissions $sensitivePermissions;
    private RoleService $roles;

    public function __construct()
    {
        parent::__construct('employees');

        $this->module               = model('Module');
        $this->branchScopes         = new EmployeeBranchService(null, new BranchGuard(null, $this->employee));
        $this->sensitivePermissions = new SensitivePermissions();
        $this->roles                = new RoleService(null, $this->employee);
    }

    /**
     * Returns employee table data rows. This will be called with AJAX.
     *
     * @return void
     */
    public function getSearch(): ResponseInterface
    {
        $search = $this->request->getGet('search');
        $limit  = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort   = $this->sanitizeSortColumn(person_headers(), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'people.person_id');
        $order  = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $employees = $this->employee->search($search, $limit, $offset, $sort, $order);
        $total_rows = $this->employee->get_found_rows($search);

        $data_rows = [];
        foreach ($employees->getResult() as $person) {
            $data_rows[] = get_person_data_row($person);
        }

        return $this->response->setJSON(['total' => $total_rows, 'rows' => $data_rows]);
    }

    /**
     * AJAX called function gives search suggestions based on what is being searched for.
     *
     * @return ResponseInterface
     */
    public function getSuggest(): ResponseInterface
    {
        $search = $this->request->getGet('term');
        $suggestions = $this->employee->get_search_suggestions($search, 25, true);

        return $this->response->setJSON($suggestions);
    }

    /**
     * @return ResponseInterface
     */
    public function suggest_search(): ResponseInterface
    {
        $search = $this->request->getPost('term');
        $suggestions = $this->employee->get_search_suggestions($search);

        return $this->response->setJSON($suggestions);
    }

    /**
     * Loads the employee edit form
     * @return string
     */
    public function getView(int $employee_id = NEW_ENTRY): string
    {
        $person_info = $this->employee->get_info($employee_id);
        $current_user = $this->employee->get_logged_in_employee_info();

        if ($employee_id != NEW_ENTRY && !$this->employee->canModifyEmployee($person_info->person_id, $current_user->person_id)) {
            throw new RedirectException('no_access/employees/employees');
        }

        foreach (get_object_vars($person_info) as $property => $value) {
            $person_info->$property = $value;
        }
        $data['person_info'] = $person_info;
        $data['employee_id'] = $employee_id;

        $modules = [];
        foreach ($this->module->get_all_modules()->getResult() as $module) {
            $module->grant = $this->employee->hasDirectGrant($module->module_id, $person_info->person_id);
            $module->menu_group = $this->employee->get_menu_group($module->module_id, $person_info->person_id);

            $modules[] = $module;
        }
        $data['all_modules'] = $modules;

        $permissions = [];
        foreach ($this->module->get_all_subpermissions()->getResult() as $permission) {    // TODO: subpermissions does not follow naming standards.
            if ($permission->permission_id === BranchGuard::ALL_BRANCHES) {
                continue;
            }

            $permission->permission_id = str_replace(' ', '_', $permission->permission_id);
            $permission->grant = $this->employee->hasDirectGrant($permission->permission_id, $person_info->person_id);

            $permissions[] = $permission;
        }
        $data['all_subpermissions']    = $permissions;
        $data['sensitive_permissions'] = AccountingGuard::SENSITIVE;
        $data['is_admin']              = $this->employee->isAdmin($current_user->person_id);
        $data['branch_tab']            = $this->branchTab($employee_id, $current_user->person_id);
        $data['roles_tab']             = $this->rolesTab($employee_id == NEW_ENTRY ? 0 : (int) $employee_id, (int) $current_user->person_id);

        return view('employees/form', $data);
    }

    /**
     * Inserts/updates an employee
     * @return ResponseInterface
     */
    public function postSave(int $employeeId = NEW_ENTRY): ResponseInterface
    {
        $currentUser = $this->employee->get_logged_in_employee_info();

        if ($employeeId != NEW_ENTRY) {
            $targetEmployee = $this->employee->get_info($employeeId);
            if (!$this->employee->canModifyEmployee($targetEmployee->person_id, $currentUser->person_id)) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Employees.error_updating_admin'),
                    'id'      => NEW_ENTRY
                ]);
            }
        }

        $firstName = $this->request->getPost('first_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);    // TODO: duplicated code
        $lastName = $this->request->getPost('last_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $email = strtolower($this->request->getPost('email', FILTER_SANITIZE_EMAIL));

        // format first and last name properly
        $firstName = $this->nameize($firstName);
        $lastName = $this->nameize($lastName);

        $personData = [
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'gender'       => $this->request->getPost('gender', FILTER_SANITIZE_NUMBER_INT),
            'email'        => $email,
            'phone_number' => $this->request->getPost('phone_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'address_1'    => $this->request->getPost('address_1', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'address_2'    => $this->request->getPost('address_2', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'city'         => $this->request->getPost('city', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'state'        => $this->request->getPost('state', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'zip'          => $this->request->getPost('zip', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'country'      => $this->request->getPost('country', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'comments'     => $this->request->getPost('comments', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
        ];

        $grantsArray = [];
        $isAdmin = $this->employee->isAdmin($currentUser->person_id);
        $sensitiveHeld = $employeeId == NEW_ENTRY ? [] : $this->sensitivePermissions->heldBy((int) $employeeId);

        foreach ($this->module->get_all_permissions()->getResult() as $permission) {
            $grants = [];

            if (!$isAdmin && SensitivePermissions::isSensitive($permission->permission_id)) {
                if (in_array($permission->permission_id, $sensitiveHeld, true)) {
                    $grantsArray[] = ['permission_id' => $permission->permission_id, 'menu_group' => '--'];
                }

                continue;
            }

            $grant = $this->request->getPost('grant_' . $permission->permission_id) != null ? $this->request->getPost('grant_' . $permission->permission_id, FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';

            if ($grant == $permission->permission_id) {
                if (!$isAdmin && !$this->employee->has_grant($permission->permission_id, $currentUser->person_id)) {
                    continue;
                }
                $grants['permission_id'] = $permission->permission_id;
                $grants['menu_group'] = $this->request->getPost('menu_group_' . $permission->permission_id) != null ? $this->request->getPost('menu_group_' . $permission->permission_id, FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '--';
                $grantsArray[] = $grants;
            }
        }

        $targetId       = $employeeId == NEW_ENTRY ? null : (int) $employeeId;
        $previousScope  = $targetId === null ? null : $this->branchScopes->scopeOf($targetId);
        $requestedScope = $this->requestedBranchScope($currentUser->person_id, $targetId);
        $requestedRoles = $this->requestedRoles((int) $currentUser->person_id);

        if ($requestedScope !== null) {
            try {
                $this->branchScopes->validateScope($currentUser->person_id, $targetId, $requestedScope['all'], $requestedScope['branch_ids'], $requestedScope['default_branch_id']);
            } catch (BranchException $exception) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Branches.error_' . $exception->error->value),
                    'id'      => $employeeId
                ]);
            }
        }

        $minimumGrants = ['employees', 'home', 'office'];
        $missingMinimumGrant = array_diff($minimumGrants, array_column($grantsArray, 'permission_id'));

        if ($isAdmin && $employeeId == $currentUser->person_id && !empty($missingMinimumGrant)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Employees.error_cannot_remove_own_minimum_grant'),
                'id'      => $employeeId
            ]);
        }

        if (filter_var(getenv('DISALLOW_GRANT_CHANGE'), FILTER_VALIDATE_BOOLEAN)
            && $this->hasGrantsChanged($employeeId, $isAdmin, $currentUser, $grantsArray)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Employees.error_grant_change_disallowed'),
                'id'      => $employeeId
            ]);
        }

        if (!empty($this->request->getPost('password')) && ENVIRONMENT != 'testing' && filter_var(getenv('DISALLOW_PASSWORD_CHANGE'), FILTER_VALIDATE_BOOLEAN)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Employees.error_password_change_disallowed'),
                'id'      => $employeeId
            ]);
        }

        // Password has been changed OR first time password set
        if (!empty($this->request->getPost('password')) && ENVIRONMENT != 'testing') {
            $exploded = explode(":", $this->request->getPost('language', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
            $employeeData = [
                'username'      => $this->request->getPost('username', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'password'      => password_hash($this->request->getPost('password'), PASSWORD_DEFAULT),
                'hash_version'  => 2,
                'language_code' => $exploded[0],
                'language'      => $exploded[1]
            ];
        } else { // Password not changed
            $exploded = explode(":", $this->request->getPost('language', FILTER_SANITIZE_FULL_SPECIAL_CHARS));
            $employeeData = [
                'username'      => $this->request->getPost('username', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                'language_code' => $exploded[0],
                'language'      => $exploded[1]
            ];

            // In the testing environment the password above is never persisted
            // (see the condition on the first branch), yet ospos_employees.password
            // is NOT NULL. When creating a new employee, supply a placeholder hash
            // so the insert succeeds and the grant-handling logic under test is
            // not masked by a constraint failure. Production behavior is unchanged.
            if (ENVIRONMENT === 'testing' && $employeeId == NEW_ENTRY) {
                $employeeData['password']     = password_hash('test-placeholder', PASSWORD_DEFAULT);
                $employeeData['hash_version'] = 2;
            }
        }

        if ($this->employee->save_employee($personData, $employeeData, $grantsArray, $employeeId)) {
            $this->sensitivePermissions->recordChange(
                $currentUser->person_id,
                (int) ($employeeId == NEW_ENTRY ? $employeeData['person_id'] : $employeeId),
                $sensitiveHeld,
                array_values(array_filter(array_column($grantsArray, 'permission_id'), [SensitivePermissions::class, 'isSensitive'])),
            );

            try {
                $this->branchScopes->afterEmployeeSave($currentUser->person_id, (int) ($employeeId == NEW_ENTRY ? $employeeData['person_id'] : $employeeId), $previousScope, $requestedScope);
            } catch (BranchException $exception) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Branches.error_' . $exception->error->value),
                    'id'      => $employeeId
                ]);
            }

            try {
                $this->applyRolesAndDefaults((int) $currentUser->person_id, (int) ($employeeId == NEW_ENTRY ? $employeeData['person_id'] : $employeeId), $requestedRoles);
            } catch (RoleException $exception) {
                return $this->response->setJSON([
                    'success' => false,
                    'message' => lang('Roles.error_' . $exception->error->value, array_values(array_map('strval', array_intersect_key($exception->context, array_flip(['permission', 'name']))))),
                    'id'      => $employeeId
                ]);
            }

            // New employee
            if ($employeeId == NEW_ENTRY) {
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Employees.successful_adding') . ' ' . $firstName . ' ' . $lastName,
                    'id'      => $employeeData['person_id']
                ]);
            } else { // Existing employee
                $loggedInEmployeeId = session()->get('person_id');
                if ($employeeId == $loggedInEmployeeId) {
                    session()->set('language_code', $employeeData['language_code']);
                    session()->set('language', $employeeData['language']);
                }
                return $this->response->setJSON([
                    'success' => true,
                    'message' => lang('Employees.successful_updating') . ' ' . $firstName . ' ' . $lastName,
                    'id'      => $employeeId
                ]);
            }
        } else { // Failure
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Employees.error_adding_updating') . ' ' . $firstName . ' ' . $lastName,
                'id'      => NEW_ENTRY
            ]);
        }
    }

    /**
     * Determines whether the submitted grants differ from the employee's current grants,
     * limited to the permissions the current user has authority over when not an admin.
     */
    private function hasGrantsChanged(int $employeeId, bool $isAdmin, object $currentUser, array $grantsArray): bool
    {
        $currentGrantIds = [];

        if ($employeeId != NEW_ENTRY) {
            $currentGrantIds = array_values(array_diff(
                array_column($this->employee->getDirectGrants($employeeId), 'permission_id'),
                [BranchGuard::ALL_BRANCHES],
            ));

            if (!$isAdmin) {
                $currentGrantIds = array_values(array_filter(
                    $currentGrantIds,
                    fn ($permissionId) => $this->employee->has_grant($permissionId, $currentUser->person_id)
                ));
            }
        }

        $submittedGrantIds = array_column($grantsArray, 'permission_id');

        if (!$isAdmin) {
            $submittedGrantIds = array_values(array_filter(
                $submittedGrantIds,
                fn ($permissionId) => $this->employee->has_grant($permissionId, $currentUser->person_id)
            ));
        }

        sort($currentGrantIds);
        sort($submittedGrantIds);

        return $currentGrantIds !== $submittedGrantIds;
    }

    /**
     * What the roles and records tab of the form shows: the roles there are, the ones the employee
     * holds, the records they see, and the stock location they start in.
     *
     * @return array{roles: list<object>, held: list<int>, can_manage: bool, record_scope: string, locations: list<object>, default_location_id: ?int, role_permissions: array<string, list<string>>}
     */
    private function rolesTab(int $targetId, int $actorId): array
    {
        return [
            'roles'               => $this->roles->all(),
            'held'                => $targetId === 0 ? [] : $this->roles->roleIdsOf($targetId),
            'can_manage'          => $this->employee->has_grant(AccountingGuard::ROLES_MANAGE, $actorId),
            'record_scope'        => $targetId === 0 ? RecordScope::ALL : $this->roles->recordScopeOf($targetId),
            'locations'           => model(Stock_location::class)->get_all()->getResult(),
            'default_location_id' => $targetId === 0 ? null : (new DefaultLocations())->of($targetId),
            'role_permissions'    => $targetId === 0 ? [] : $this->roles->rolePermissionsOf($targetId),
        ];
    }

    /**
     * The roles and the records the form asked for, null when the form carried none, the actor may
     * not manage roles or grants may not be changed.
     *
     * @return array{roles: list<int|string>, scope: string}|null
     */
    private function requestedRoles(int $actorId): ?array
    {
        if ($this->request->getPost('roles_present') === null
            || ! $this->employee->has_grant(AccountingGuard::ROLES_MANAGE, $actorId)
            || filter_var(getenv('DISALLOW_GRANT_CHANGE'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $roleIds = $this->request->getPost('role_ids');

        return [
            'roles' => is_array($roleIds) ? array_values($roleIds) : [],
            'scope' => (string) ($this->request->getPost('record_scope') ?? RecordScope::ALL),
        ];
    }

    /**
     * Saves the roles, the records and the stock location of an employee, after the employee was
     * saved.
     *
     * @param array{roles: list<int|string>, scope: string}|null $requestedRoles
     *
     * @throws RoleException
     */
    private function applyRolesAndDefaults(int $actorId, int $personId, ?array $requestedRoles): void
    {
        if ($requestedRoles !== null) {
            $this->roles->assign($personId, $requestedRoles['roles'], $actorId);
            $this->roles->setRecordScope($personId, $requestedRoles['scope'], $actorId);
        }

        if ($this->request->getPost('default_location_present') !== null) {
            $locationId = (int) $this->request->getPost('default_location_id');

            (new DefaultLocations())->set($personId, $locationId > 0 ? $locationId : null, $actorId, $this->employee);
        }
    }

    /**
     * The branches an employee works in, as the form shows them. Null hides the tab: it is only
     * for someone who may set branches, and only once the company has more than one.
     *
     * @return array{scope: array{all: bool, branch_ids: list<int>, default_branch_id: ?int}, branches: list<object>, can_all: bool, locked: bool, hidden: bool}|null
     */
    private function branchTab(int $employeeId, int $actorId): ?array
    {
        $targetId = $employeeId == NEW_ENTRY ? null : $employeeId;

        if (! $this->branchScopes->canManageScope($actorId, $targetId)) {
            return null;
        }

        $branches = (new BranchService())->all($actorId);

        if (count((new BranchService())->all()) < 2) {
            return null;
        }

        $guard   = new BranchGuard(null, $this->employee);
        $scope   = $targetId === null
            ? ['all' => false, 'branch_ids' => [$guard->defaultBranchId($actorId)], 'default_branch_id' => $guard->defaultBranchId($actorId)]
            : $this->branchScopes->scopeOf($targetId);
        $visible = array_map(static fn (object $branch): int => (int) $branch->branch_id, $branches);

        return [
            'scope'    => $scope,
            'branches' => $branches,
            'can_all'  => $guard->isUnrestricted($actorId),
            'locked'   => $scope['all'] && ! $guard->isUnrestricted($actorId),
            'hidden'   => array_diff($scope['branch_ids'], $visible) !== [],
        ];
    }

    /**
     * The branches the employee form asked for, null when the form carried none or the actor may
     * not change them.
     *
     * @return array{all: bool, branch_ids: list<int>, default_branch_id: ?int}|null
     */
    private function requestedBranchScope(int $actorId, ?int $targetId): ?array
    {
        if ($this->request->getPost('branch_scope_present') === null
            || ! $this->branchScopes->canManageScope($actorId, $targetId)
            || filter_var(getenv('DISALLOW_GRANT_CHANGE'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $branchIds = $this->request->getPost('branch_ids');
        $default   = (int) $this->request->getPost('default_branch_id');

        return [
            'all'               => $this->request->getPost('branch_scope') === 'all',
            'branch_ids'        => is_array($branchIds) ? array_values(array_map('intval', $branchIds)) : [],
            'default_branch_id' => $default > 0 ? $default : null,
        ];
    }

    /**
     * This deletes employees from the employees table
     * @return ResponseInterface
     */
    public function postDelete(): ResponseInterface
    {
        $employees_to_delete = $this->request->getPost('ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $current_user = $this->employee->get_logged_in_employee_info();

        if (!$this->employee->isAdmin($current_user->person_id)) {
            foreach ($employees_to_delete as $emp_id) {
                if ($this->employee->isAdmin((int)$emp_id)) {
                    return $this->response->setJSON(['success' => false, 'message' => lang('Employees.error_deleting_admin')]);
                }
            }
        }

        if ((new EmployeeBranchService())->wouldLeaveNobodyWithEveryBranch((array) $employees_to_delete)) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Branches.error_last_unrestricted')]);
        }

        $sensitiveHeld = [];

        foreach ((array) $employees_to_delete as $employeeId) {
            $sensitiveHeld[(int) $employeeId] = $this->sensitivePermissions->heldBy((int) $employeeId);
        }

        if ($this->employee->delete_list($employees_to_delete)) {    // TODO: this is passing a string, but delete_list expects an array
            foreach ($sensitiveHeld as $employeeId => $held) {
                $this->sensitivePermissions->recordChange($current_user->person_id, $employeeId, $held, []);
            }

            return $this->response->setJSON([
                'success' => true,
                'message' => lang('Employees.successful_deleted') . ' ' . count($employees_to_delete) . ' ' . lang('Employees.one_or_multiple')
            ]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => lang('Employees.cannot_be_deleted')]);
        }
    }

    /**
     * Checks an employee username against the database. Used in app\Views\employees\form.php
     *
     * @param $employee_id
     * @return ResponseInterface
     * @noinspection PhpUnused
     */
    public function getCheckUsername($employee_id): ResponseInterface
    {
        $exists = $this->employee->username_exists($employee_id, $this->request->getGet('username'));
        return $this->response->setJSON(!$exists ? 'true' : 'false');
    }
}
