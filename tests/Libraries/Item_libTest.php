<?php

namespace Tests\Libraries;

use App\Libraries\Item_lib;
use CodeIgniter\Database\Config;
use CodeIgniter\HTTP\Exceptions\RedirectException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;

class Item_libTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        session()->remove(['person_id', 'item_location']);

        parent::tearDown();
    }

    /**
     * A second branch with a stock location of its own, which the employee holds the items permission for.
     *
     * @return array{branch: int, store: int}
     */
    private function secondBranchWithAStoreOf(int $employeeId): array
    {
        $branch = $this->createBranch('L' . substr(md5(uniqid('', true)), 0, 6), 'Second branch');
        $name   = 'store' . substr(md5(uniqid('', true)), 0, 8);
        $store  = $this->locationOfBranch($branch, $name);

        $this->accountingDb()->table('permissions')->insert(['permission_id' => "items_{$name}", 'module_id' => 'items', 'location_id' => $store]);
        $this->accountingDb()->table('grants')->insert(['permission_id' => "items_{$name}", 'person_id' => $employeeId, 'menu_group' => 'home']);

        return ['branch' => $branch, 'store' => $store];
    }

    public function testGetItemLocation_FallsBackToALocationOfTheEmployeesBranchesWhenTheSessionHoldsAnother(): void
    {
        $employeeId = $this->employeeWithGrants(['items', 'items_stock'], [1]);
        ['store' => $store] = $this->secondBranchWithAStoreOf($employeeId);
        session()->set(['person_id' => $employeeId, 'item_location' => (string) $store]);

        $this->assertSame('1', (new Item_lib())->get_item_location());
        $this->assertSame('1', (string) session()->get('item_location'));
    }

    public function testGetItemLocation_KeepsTheLocationOfTheSessionWhenTheEmployeeMayUseIt(): void
    {
        $employeeId = $this->employeeWithGrants(['items', 'items_stock'], [1]);
        ['branch' => $branch, 'store' => $store] = $this->secondBranchWithAStoreOf($employeeId);
        $this->accountingDb()->table('employee_branches')->insert(['person_id' => $employeeId, 'branch_id' => $branch, 'is_default' => 0]);
        session()->set(['person_id' => $employeeId, 'item_location' => (string) $store]);

        $this->assertSame((string) $store, (new Item_lib())->get_item_location());
    }

    public function testGetItemLocation_RejectsAnEmployeeWhoseBranchesHaveNoLocationTheyMayUse(): void
    {
        $employeeId = $this->employeeWithGrants(['items', 'items_stock'], [1]);
        ['branch' => $branch] = $this->secondBranchWithAStoreOf($employeeId);
        $this->accountingDb()->table('employee_branches')->where('person_id', $employeeId)->update(['branch_id' => $branch]);
        $this->accountingDb()->table('grants')->where('person_id', $employeeId)->like('permission_id', 'items_store', 'after')->delete();
        session()->set(['person_id' => $employeeId, 'item_location' => '1']);

        $this->expectException(RedirectException::class);
        $this->expectExceptionMessage('no_access/items/items_stock');

        (new Item_lib())->get_item_location();
    }
}
