<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Roles\DefaultLocations;
use App\Libraries\Roles\RoleService;
use App\Libraries\Stock\StockLocationLifecycle;
use App\Models\Stock_location;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class Stock_locationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SALES_GRANTS = ['sales', 'sales_stock'];

    /** @var list<int> */
    private array $createdLocations = [];

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        foreach ($this->createdLocations as $locationId) {
            $permissionIds = array_column($db->table('permissions')->select('permission_id')->where('location_id', $locationId)->get()->getResultArray(), 'permission_id');

            if ($permissionIds !== []) {
                $db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
                $db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
            }

            $db->table('accounting_accounts')->where('entity_type', 'stock_location')->where('entity_id', $locationId)->delete();
            $db->table('item_quantities')->where('location_id', $locationId)->delete();
            $db->table('stock_locations')->where('location_id', $locationId)->delete();
        }

        $db->query('UPDATE ' . $db->prefixTable('stock_locations') . ' SET is_primary = 0, status = 1 WHERE deleted = 0');
        $db->query('UPDATE ' . $db->prefixTable('stock_locations') . ' SET is_primary = 1 WHERE location_id = 1');

        foreach ($this->itemIds as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $this->itemIds          = [];
        $this->createdLocations = [];
        $this->resetBranches();
        $this->setConfigValue('accounting_enabled', '0');
        session()->remove('person_id');

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function salesEmployee(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(self::SALES_GRANTS, $branchIds);
        session()->set('person_id', $personId);

        return $personId;
    }

    private function createLocation(string $name, int $branchId, int $forEmployee): int
    {
        $db = $this->accountingDb();
        $db->table('stock_locations')->insert(['location_name' => $name, 'deleted' => 0, 'branch_id' => $branchId]);
        $locationId = (int) $db->insertID();
        $this->createdLocations[] = $locationId;

        foreach (['items', 'sales'] as $module) {
            $permissionId = "{$module}_{$name}";
            $db->table('permissions')->insert(['permission_id' => $permissionId, 'module_id' => $module, 'location_id' => $locationId]);
            $db->table('grants')->insert(['permission_id' => $permissionId, 'person_id' => $forEmployee, 'menu_group' => 'home']);
        }

        return $locationId;
    }

    private function startAccounting(): EntityAccountService
    {
        $this->reinstallChart();
        $this->setConfigValue('accounting_enabled', '1');

        return new EntityAccountService();
    }

    private function saveLocation(string $name): int
    {
        $data = ['location_name' => $name];

        $this->assertTrue(model(Stock_location::class)->save_value($data, -1));

        return $this->createdLocations[] = (int) $data['location_id'];
    }

    public function testSaveValue_OpensASubAccountForANewLocationWhileAccountingIsOn(): void
    {
        $entities   = $this->startAccounting();
        $locationId = $this->saveLocation('Back store');

        $accountId = $entities->accountFor(EntityAccountService::STOCK_LOCATION, $locationId);

        $this->assertNotNull($accountId);
        $this->assertSame('Back store', $this->accountingDb()->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow()->name);
    }

    public function testSaveValue_OpensNoAccountWhileAccountingIsOff(): void
    {
        $entities = $this->startAccounting();
        $this->setConfigValue('accounting_enabled', '0');

        $locationId = $this->saveLocation('Back store');

        $this->assertNull($entities->accountFor(EntityAccountService::STOCK_LOCATION, $locationId));
    }

    public function testSaveValue_RenamesTheAccountWhenTheLocationIsRenamed(): void
    {
        $entities   = $this->startAccounting();
        $locationId = $this->saveLocation('Back store');

        $data = ['location_name' => 'Rear store'];
        model(Stock_location::class)->save_value($data, $locationId);

        $this->assertSame('Rear store', $this->accountingDb()->table('accounting_accounts')->where('account_id', $entities->accountFor(EntityAccountService::STOCK_LOCATION, $locationId))->get()->getRow()->name);
    }

    public function testDelete_RetiresTheAccountOfTheLocation(): void
    {
        $entities   = $this->startAccounting();
        $locationId = $this->saveLocation('Back store');

        model(Stock_location::class)->delete($locationId);

        $this->assertSame('0', (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $entities->accountFor(EntityAccountService::STOCK_LOCATION, $locationId))->get()->getRow()->active);
    }

    public function testGetAllowedLocations_OffersEveryGrantedLocationToAnEmployeeOfEveryBranch(): void
    {
        $personId = $this->salesEmployee();
        $second   = $this->createLocation('depot', $this->createBranch('B2'), $personId);

        $locations = (new Stock_location())->get_allowed_locations('sales', true);

        $this->assertArrayHasKey(1, $locations);
        $this->assertArrayHasKey($second, $locations);
    }

    public function testGetAllowedLocations_OffersOnlyTheLocationsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $branch   = $this->createBranch('B2');
        $personId = $this->salesEmployee([$branch]);
        $depot    = $this->createLocation('depot', $branch, $personId);

        $locations = (new Stock_location())->get_allowed_locations('sales', true);

        $this->assertSame([$depot], array_keys($locations));
    }

    public function testGetAllowedLocations_OffersNothingWhenNoLocationBelongsToTheEmployeesBranches(): void
    {
        $this->salesEmployee([$this->createBranch('B2')]);

        $this->assertSame([], (new Stock_location())->get_allowed_locations('sales', true));
        $this->assertFalse((new Stock_location())->show_locations('sales', true));
    }

    public function testGetAllowedLocations_IgnoresBranchesUnlessTheCallerAsksForThem(): void
    {
        $this->salesEmployee([$this->createBranch('B2')]);

        $model = new Stock_location();

        $this->assertArrayHasKey(1, $model->get_allowed_locations('sales'));
        $this->assertTrue($model->is_allowed_location(1, 'sales'));
        $this->assertSame([], $model->get_allowed_locations('sales', true));
    }

    public function testGetAllowedLocations_LeavesOtherModulesAlone(): void
    {
        $personId = $this->employeeWithGrants(['items', 'items_stock'], [$this->createBranch('B2')]);
        session()->set('person_id', $personId);

        $this->assertArrayHasKey(1, (new Stock_location())->get_allowed_locations('items'));
    }

    public function testIsAllowedLocation_RefusesALocationOfAnotherBranch(): void
    {
        $branch   = $this->createBranch('B2');
        $personId = $this->salesEmployee([$branch]);
        $depot    = $this->createLocation('depot', $branch, $personId);

        $model = new Stock_location();

        $this->assertTrue($model->is_allowed_location($depot, 'sales', true));
        $this->assertFalse($model->is_allowed_location(1, 'sales', true));
    }

    public function testGetDefaultLocationId_SkipsALocationOfAnotherBranch(): void
    {
        $branch   = $this->createBranch('B2');
        $personId = $this->salesEmployee([$branch]);
        $depot    = $this->createLocation('depot', $branch, $personId);

        $this->assertSame($depot, (int) (new Stock_location())->get_default_location_id('sales', true));
    }

    public function testGetDefaultLocationId_PreselectsTheDefaultLocationOfTheEmployeeForTheRegisterAndTheReceivingScreen(): void
    {
        $personId = $this->salesEmployee();
        $first    = $this->createLocation('first depot', 1, $personId);
        $second   = $this->createLocation('second depot', 1, $personId);
        $model    = new Stock_location();

        $this->accountingDb()->table('permissions')->insert(['permission_id' => 'receivings_second depot', 'module_id' => 'receivings', 'location_id' => $second]);
        $this->accountingDb()->table('grants')->insert(['permission_id' => 'receivings_second depot', 'person_id' => $personId, 'menu_group' => 'home']);

        (new DefaultLocations())->set($personId, $second, null);

        $this->assertSame($second, (int) $model->get_default_location_id('sales', true));
        $this->assertSame($second, (int) $model->get_default_location_id('receivings', true));
        $this->assertNotSame($second, (int) $model->get_default_location_id('items'), 'The items screens start where they always did');
        $this->assertContains($first, array_keys($model->get_allowed_locations('sales')), 'The default grants nothing and takes nothing away');
    }

    public function testGetDefaultLocationId_BehavesAsBeforeForAnEmployeeWithoutADefaultOrWhoMayNotUseIt(): void
    {
        $personId = $this->salesEmployee();
        $granted  = $this->createLocation('granted depot', 1, $personId);
        $model    = new Stock_location();
        $before   = (int) $model->get_default_location_id('sales', true);

        $this->accountingDb()->table('stock_locations')->insert(['location_name' => 'closed depot', 'deleted' => 0, 'branch_id' => 1]);

        $closed = (int) $this->accountingDb()->insertID();

        $this->createdLocations[] = $closed;

        (new DefaultLocations())->set($personId, $closed, null);

        $this->assertSame($before, (int) $model->get_default_location_id('sales', true), 'A default the employee holds no permission for is not preselected');

        (new DefaultLocations())->set($personId, null, null);

        $this->assertSame($before, (int) $model->get_default_location_id('sales', true));
        $this->assertContains($granted, array_keys($model->get_allowed_locations('sales')));
    }

    public function testGetAllowedLocations_OffersTheLocationsThePermissionsOfARoleGive(): void
    {
        $holder = $this->createEmployee();
        $depot  = $this->createLocation('role depot', 1, $holder);

        $this->accountingDb()->table('grants')->where('person_id', $holder)->delete();

        $roleId = (new RoleService())->create(['name' => 'Depot sellers ' . uniqid(), 'permissions' => ['sales', 'sales_role depot']], null);

        (new RoleService())->assign($holder, [$roleId], null);
        session()->set('person_id', $holder);

        $model = new Stock_location();

        $this->assertSame([$depot => 'role depot'], $model->get_allowed_locations('sales'));
        $this->assertTrue($model->is_allowed_location($depot, 'sales'));
        $this->assertSame($depot, (int) $model->get_default_location_id('sales'));
        $this->assertSame([], $model->get_allowed_locations('items'), 'The role gives nothing else');
    }

    public function testSaveValue_GivesTheNewLocationToTheRolesThatHoldTheModule(): void
    {
        $holder = (new RoleService())->create(['name' => 'Module holders ' . uniqid(), 'permissions' => ['sales']], null);
        $other  = (new RoleService())->create(['name' => 'Others ' . uniqid(), 'permissions' => ['items']], null);
        $name   = 'carried depot ' . uniqid();

        $this->saveLocation($name);

        $permission = 'sales_' . str_replace(' ', '_', $name);

        $this->assertContains($permission, (new RoleService())->permissionsOf($holder));
        $this->assertNotContains($permission, (new RoleService())->permissionsOf($other));
    }

    public function testGetAllowedLocations_HidesAnInactiveLocation(): void
    {
        $personId = $this->salesEmployee();
        $depot    = $this->createLocation('quiet depot', 1, $personId);
        $this->accountingDb()->table('stock_locations')->where('location_id', $depot)->update(['status' => 0]);

        $model = new Stock_location();

        $this->assertArrayNotHasKey($depot, $model->get_allowed_locations('sales'));
        $this->assertFalse($model->is_allowed_location($depot, 'sales'));
        $this->assertNotSame($depot, (int) $model->get_default_location_id('sales'));
        $this->assertArrayHasKey($depot, array_column($model->get_all()->getResultArray(), null, 'location_id'), 'The settings still list it');
    }

    public function testGetAllowedLocations_OffersAnInactiveLocationToWhoAsksForItForTheReportsOfWhatWasDone(): void
    {
        $personId = $this->salesEmployee();
        $depot    = $this->createLocation('closed depot', 1, $personId);
        $this->accountingDb()->table('stock_locations')->where('location_id', $depot)->update(['status' => 0]);

        $model = new Stock_location();

        $this->assertArrayHasKey($depot, $model->get_allowed_locations('sales', false, true));
        $this->assertArrayNotHasKey($depot, $model->get_allowed_locations('sales'));
        $this->assertArrayNotHasKey($depot, $model->get_allowed_locations('sales', true));
    }

    public function testGetDefaultLocationId_SkipsAnInactiveLocationThatComesFirst(): void
    {
        $branch   = $this->createBranch('B2');
        $personId = $this->salesEmployee([$branch]);
        $quiet    = $this->createLocation('a quiet depot', $branch, $personId);
        $open     = $this->createLocation('b open depot', $branch, $personId);

        $this->accountingDb()->table('stock_locations')->where('location_id', $quiet)->update(['status' => 0]);

        $this->assertSame($open, (int) (new Stock_location())->get_default_location_id('sales', true));
    }

    public function testSaveValue_MakesTheFirstLocationOfACompanyWithoutAPrimaryLocationPrimary(): void
    {
        $this->accountingDb()->table('stock_locations')->update(['is_primary' => 0]);

        $locationId = $this->saveLocation('Fresh start');

        $this->assertSame($locationId, (int) (new StockLocationLifecycle())->primaryId());
    }

    public function testSaveValue_LeavesThePrimaryLocationWhereItIsForALaterLocation(): void
    {
        $before = (int) (new StockLocationLifecycle())->primaryId();

        $locationId = $this->saveLocation('Second place');

        $this->assertSame($before, (int) (new StockLocationLifecycle())->primaryId());
        $this->assertSame('0', (string) $this->accountingDb()->table('stock_locations')->where('location_id', $locationId)->get()->getRow()->is_primary);
        $this->assertSame('1', (string) $this->accountingDb()->table('stock_locations')->where('location_id', $locationId)->get()->getRow()->status);
    }

    public function testDelete_RefusesThePrimaryLocationAndKeepsItsSubAccountOpen(): void
    {
        $entities = $this->startAccounting();
        $primary  = (int) (new StockLocationLifecycle())->primaryId();
        $entities->sync(EntityAccountService::STOCK_LOCATION, $primary);
        $this->saveLocation('Other place');
        $model = model(Stock_location::class);

        $this->assertFalse($model->delete($primary));
        $this->assertSame(AccountingError::StockLocationIsPrimary, $model->removalRefusal()?->error);
        $this->assertSame('0', (string) $this->accountingDb()->table('stock_locations')->where('location_id', $primary)->get()->getRow()->deleted);
        $this->assertSame('1', (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $entities->accountFor(EntityAccountService::STOCK_LOCATION, $primary))->get()->getRow()->active);
    }

    public function testDelete_RefusesALocationThatHoldsStockAndLeavesItsAccountOpen(): void
    {
        $entities   = $this->startAccounting();
        $locationId = $this->saveLocation('Loaded place');
        $itemId     = $this->itemIds[] = $this->createTestItem();
        $this->accountingDb()->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => '3']);
        $model = model(Stock_location::class);

        $this->assertFalse($model->delete($locationId));
        $this->assertSame(AccountingError::StockLocationHoldsStock, $model->removalRefusal()?->error);
        $this->assertSame('0', (string) $this->accountingDb()->table('stock_locations')->where('location_id', $locationId)->get()->getRow()->deleted);
        $this->assertSame('1', (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $entities->accountFor(EntityAccountService::STOCK_LOCATION, $locationId))->get()->getRow()->active);
    }

    public function testDelete_ForgetsAnEarlierRefusalWhenALaterRemovalSucceeds(): void
    {
        $this->saveLocation('Kept place');
        $gone  = $this->saveLocation('Gone place');
        $model = model(Stock_location::class);

        $this->assertFalse($model->delete((int) (new StockLocationLifecycle())->primaryId()));
        $this->assertTrue($model->delete($gone));
        $this->assertNull($model->removalRefusal());
    }
}
