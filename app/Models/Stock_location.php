<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\DefaultLocations;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use App\Libraries\Stock\StockLocationLifecycle;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use CodeIgniter\Session\Session;

/**
 * Stock_location class
 *
 * @property employee employee
 * @property item item
 * @property Session session
 */
class Stock_location extends Model
{
    protected $table            = 'stock_locations';
    protected $primaryKey       = 'location_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'location_name',
        'deleted',
    ];
    private Session $session;
    private Employee $employee;
    private ?AccountingException $removalRefusal = null;

    public function __construct()
    {
        parent::__construct();

        $this->session = session();
    }

    public function exists(int $location_id = NEW_ENTRY): bool
    {
        $builder = $this->db->table('stock_locations');
        $builder->where('location_id', $location_id);

        return $builder->get()->getNumRows() >= 1;
    }

    public function get_all(): ResultInterface
    {
        $builder = $this->db->table('stock_locations');
        $builder->where('deleted', 0);

        return $builder->get();
    }

    /**
     * The locations of the lists for new documents leave out an inactive one; the filters of the reports
     * of what was done ask for it too, because an inactive location keeps its history.
     */
    public function get_undeleted_all(string $module_id = 'items', bool $byBranch = false, bool $withInactive = false): ResultInterface
    {
        $branchIds = $this->employeeBranchIds($byBranch);
        $roleHeld  = $this->heldThroughRoles();
        $builder   = $this->db->table('stock_locations');
        $builder->join('permissions AS permissions', 'permissions.location_id = stock_locations.location_id');
        $this->limitToHeld($builder, $roleHeld);
        $builder->like('permissions.permission_id', $module_id, 'after');
        $builder->where('deleted', 0);

        if (! $withInactive) {
            $builder->where('stock_locations.status', 1);
        }

        $this->limitToBranches($builder, $branchIds);

        return $builder->get();
    }

    public function show_locations(string $module_id = 'items', bool $byBranch = false): bool
    {
        $stock_locations = $this->get_allowed_locations($module_id, $byBranch);

        return count($stock_locations) > 1;
    }

    public function multiple_locations(): bool
    {
        return $this->get_all()->getNumRows() > 1;
    }

    public function get_allowed_locations(string $module_id = 'items', bool $byBranch = false, bool $withInactive = false): array
    {
        $stock           = $this->get_undeleted_all($module_id, $byBranch, $withInactive)->getResultArray();
        $stock_locations = [];

        foreach ($stock as $location_data) {
            $stock_locations[$location_data['location_id']] = $location_data['location_name'];
        }

        return $stock_locations;
    }

    public function is_allowed_location(int $location_id, string $module_id = 'items', bool $byBranch = false): bool
    {
        $branchIds = $this->employeeBranchIds($byBranch);
        $roleHeld  = $this->heldThroughRoles();
        $builder   = $this->db->table('stock_locations');
        $builder->join('permissions AS permissions', 'permissions.location_id = stock_locations.location_id');
        $this->limitToHeld($builder, $roleHeld);
        $builder->like('permissions.permission_id', $module_id, 'after');
        $builder->where('stock_locations.location_id', $location_id);
        $builder->where('deleted', 0);
        $builder->where('stock_locations.status', 1);
        $this->limitToBranches($builder, $branchIds);

        return $builder->get()->getNumRows() == 1;    // TODO: ===
    }

    public function get_default_location_id(string $module_id = 'items', bool $byBranch = false): int
    {
        $own = in_array($module_id, ['sales', 'receivings'], true) ? (new DefaultLocations($this->db))->of((int) $this->session->get('person_id')) : null;

        if ($own !== null && $this->is_allowed_location($own, $module_id, $byBranch)) {
            return $own;
        }

        $branchIds = $this->employeeBranchIds($byBranch);
        $roleHeld  = $this->heldThroughRoles();
        $builder   = $this->db->table('stock_locations');
        $builder->join('permissions AS permissions', 'permissions.location_id = stock_locations.location_id');
        $this->limitToHeld($builder, $roleHeld);
        $builder->like('permissions.permission_id', $module_id, 'after');
        $builder->where('deleted', 0);
        $builder->where('stock_locations.status', 1);
        $this->limitToBranches($builder, $branchIds);
        $builder->limit(1);

        return $builder->get()->getRow()->location_id;    // TODO: this is puking. Trying to get property 'location_id' of non-object
    }

    /**
     * Selling happens in a branch, so the sales register asks for only the stock locations of
     * the branches the employee works in. Employees who work in every branch get them all, and
     * callers that do not ask for the limit, such as the reports, get every granted location.
     * The branches are read before the query is built, because reading them runs queries of
     * their own on the same connection.
     *
     * @return list<int>|null Null means no limit.
     */
    private function employeeBranchIds(bool $byBranch): ?array
    {
        return $byBranch ? (new BranchGuard($this->db))->allowedBranchIds((int) $this->session->get('person_id')) : null;
    }

    /**
     * The permissions the roles of the employee give. Read before the query is built, because
     * reading them runs queries of their own on the same connection.
     *
     * @return list<string>
     */
    private function heldThroughRoles(): array
    {
        return (new RoleGrants($this->db))->idsFor((int) $this->session->get('person_id'));
    }

    /**
     * Keeps the permissions of locations that the employee holds, through their own grants or
     * through their roles.
     *
     * @param list<string> $heldThroughRoles
     */
    private function limitToHeld(BaseBuilder $builder, array $heldThroughRoles): void
    {
        $personId = (int) $this->session->get('person_id');

        $builder->join('grants AS grants', 'grants.permission_id = permissions.permission_id AND grants.person_id = ' . $personId, 'left');
        $builder->groupStart();
        $builder->where('person_id', $personId);

        if ($heldThroughRoles !== []) {
            $builder->orWhereIn('permissions.permission_id', $heldThroughRoles);
        }

        $builder->groupEnd();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function limitToBranches(BaseBuilder $builder, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('stock_locations.branch_id', $branchIds);
    }

    public function get_location_name(int $location_id): string
    {
        $builder = $this->db->table('stock_locations');
        $builder->where('location_id', $location_id);

        return $builder->get()->getRow()->location_name;
    }

    public function get_location_id(string $location_name): int
    {
        $builder = $this->db->table('stock_locations');
        $builder->where('location_name', $location_name);

        return $builder->get()->getRow()->location_id;
    }

    public function save_value(array &$location_data, int $location_id): bool
    {
        $location_name = $location_data['location_name'];

        $location_data_to_save = ['location_name' => $location_name, 'deleted' => 0];

        if (! $this->exists($location_id)) {
            $this->db->transStart();

            $builder = $this->db->table('stock_locations');
            $builder->insert($location_data_to_save);
            $location_id                  = $this->db->insertID();
            $location_data['location_id'] = $location_id;

            $this->_insert_new_permission('items', $location_id, $location_name);    // TODO: need to refactor out the hungarian notation.
            $this->_insert_new_permission('sales', $location_id, $location_name);
            $this->_insert_new_permission('receivings', $location_id, $location_name);

            // Insert quantities for existing items
            $item    = model(Item::class);
            $builder = $this->db->table('item_quantities');
            $items   = $item->get_all();

            foreach ($items->getResultArray() as $item) {
                $quantity_data = [
                    'item_id'     => $item['item_id'],
                    'location_id' => $location_id,
                    'quantity'    => 0,
                ];
                $builder->insert($quantity_data);
            }

            (new StockLocationLifecycle($this->db))->ensurePrimary((int) $location_id);

            $this->db->transComplete();

            if ($this->db->transStatus()) {
                (new EntityAccountService())->sync(EntityAccountService::STOCK_LOCATION, (int) $location_id);
            }

            return $this->db->transStatus();
        }

        $original_location_name = $this->get_location_name($location_id);

        if ($original_location_name != $location_name) {
            $builder = $this->db->table('permissions');
            $builder->delete(['location_id' => $location_id]);

            $this->_insert_new_permission('items', $location_id, $location_name);
            $this->_insert_new_permission('sales', $location_id, $location_name);
            $this->_insert_new_permission('receivings', $location_id, $location_name);
        }

        $builder = $this->db->table('stock_locations');
        $builder->where('location_id', $location_id);

        $updated = $builder->update($location_data_to_save);

        if ($updated) {
            (new EntityAccountService())->sync(EntityAccountService::STOCK_LOCATION, $location_id);
        }

        return $updated;
    }

    private function _insert_new_permission(string $module, int $location_id, string $location_name): void    // TODO: refactor out hungarian notation
    {
        // Insert new permission for stock location
        $permission_id   = $module . '_' . str_replace(' ', '_', $location_name);    // TODO: String interpolation
        $permission_data = ['permission_id' => $permission_id, 'module_id' => $module, 'location_id' => $location_id];

        $builder = $this->db->table('permissions');
        $builder->insert($permission_data);

        // Insert grants for new permission
        $employee  = model(Employee::class);
        $employees = $employee->get_all();

        $builder = $this->db->table('grants');

        foreach ($employees->getResultArray() as $employee) {
            $this->employee = model(Employee::class);

            // Retrieve the menu_group assigned to the grant for the module and use that for the new stock locations
            $menu_group = $this->employee->get_menu_group($module, $employee['person_id']);

            $grants_data = ['permission_id' => $permission_id, 'person_id' => $employee['person_id'], 'menu_group' => $menu_group];

            $builder->insert($grants_data);
        }

        (new RoleService($this->db))->carryLocationPermission($module, $permission_id);
    }

    /**
     * Deletes one item
     *
     * @param int|null $locationId
     */
    public function delete($locationId = null, bool $purge = false): bool
    {
        $this->removalRefusal = null;

        try {
            (new StockLocationLifecycle($this->db))->checkRemoval((int) $locationId);
        } catch (AccountingException $refusal) {
            $this->removalRefusal = $refusal;

            return false;
        }

        $this->db->transStart();

        $builder = $this->db->table('stock_locations');
        $builder->where('location_id', $locationId);
        $builder->update(['deleted' => 1]);

        $builder = $this->db->table('permissions');
        $builder->delete(['location_id' => $locationId]);

        $this->db->transComplete();

        if ($this->db->transStatus()) {
            (new EntityAccountService())->syncDeleted(EntityAccountService::STOCK_LOCATION, (int) $locationId);
        }

        return $this->db->transStatus();
    }

    /**
     * Why the last removal was refused: the location is the primary or the only active one, or it holds
     * stock, a value in the books or an open document.
     */
    public function removalRefusal(): ?AccountingException
    {
        return $this->removalRefusal;
    }
}
