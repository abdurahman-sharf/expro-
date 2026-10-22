<?php

namespace Tests\Models;

use App\Libraries\Accounting\EntityAccountService;
use App\Models\Stock_location;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class Stock_locationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SALES_GRANTS = ['sales', 'sales_stock'];

    /** @var list<int> */
    private array $createdLocations = [];

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

            $db->table('stock_locations')->where('location_id', $locationId)->delete();
        }

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
}
