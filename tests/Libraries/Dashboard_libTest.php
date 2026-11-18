<?php

namespace Tests\Libraries;

use App\Libraries\Dashboard_lib;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Config\Factories;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleFixtureTrait;

/**
 * @internal
 */
final class Dashboard_libTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace;
    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();

        Factories::reset('models');
        Database::connect()->query('SELECT 1');
        // Loading the settings resets the connection's table aliases, so do it before a report query registers its own
        config(OSPOS::class);
        helper(['locale', 'number']);
        $this->clearCache();
    }

    protected function tearDown(): void
    {
        $this->clearCache();
        session()->remove('person_id');

        $db = Database::connect();

        foreach ($this->branchesMade as $branchId) {
            $db->table('employee_branches')->where('branch_id', $branchId)->delete();
            $db->table('branches')->where('branch_id', $branchId)->delete();
        }

        $this->branchesMade = [];

        parent::tearDown();
    }

    /**
     * @var list<int>
     */
    private array $branchesMade = [];

    private function newBranch(): int
    {
        $db = Database::connect();

        $db->table('branches')->insert(['company_id' => 1, 'code' => 'D' . substr(md5(uniqid('', true)), 0, 8), 'name' => 'Dashboard branch']);

        return $this->branchesMade[] = (int) $db->insertID();
    }

    /**
     * Logs in an employee who works in the branches given, or in every branch.
     *
     * @param list<int> $branchIds
     */
    private function loggedInWorkingIn(array $branchIds, bool $everyBranch = false): int
    {
        $db       = Database::connect();
        $personId = $this->createEmployee(grants: $everyBranch ? [['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--']] : []);

        foreach ($branchIds as $branchId) {
            $db->table('employee_branches')->insert(['person_id' => $personId, 'branch_id' => $branchId, 'is_default' => 1]);
        }

        session()->set('person_id', $personId);

        return $personId;
    }

    private function clearCache(): void
    {
        foreach (['sales', 'top_items_100', 'low_stock_100', 'expenses_month'] as $section) {
            cache()->delete('dashboard_' . $section . '_' . date('Ymd'));

            foreach ($this->branchesMade as $branchId) {
                cache()->delete('dashboard_' . $section . '_b' . $branchId . '_' . date('Ymd'));
                cache()->delete('dashboard_' . $section . '_b1-' . $branchId . '_' . date('Ymd'));
            }

            cache()->delete('dashboard_' . $section . '_b1_' . date('Ymd'));
        }
    }

    public function testSalesCountsTodaysCompletedSales(): void
    {
        $employeeId = $this->createEmployee();
        $this->createSale($employeeId);
        $this->createSale($employeeId);

        $sales = (new Dashboard_lib())->sales();

        $this->assertNotNull($sales);
        $this->assertGreaterThanOrEqual(2, $sales['today']['count']);
        $this->assertGreaterThanOrEqual(2.0, $sales['today']['total']);
        $this->assertGreaterThan(0.0, $sales['today']['average']);
    }

    public function testSalesReturnsOneValuePerTrendDayEndingToday(): void
    {
        $this->createSale($this->createEmployee());

        $series = (new Dashboard_lib())->sales()['series'];

        $this->assertCount(Dashboard_lib::TREND_DAYS, $series['labels']);
        $this->assertCount(Dashboard_lib::TREND_DAYS, $series['values']);
        $this->assertSame(date('m/d'), end($series['labels']));
        $this->assertGreaterThan(0.0, end($series['values']));
    }

    public function testSalesComparesTodayWithYesterday(): void
    {
        $this->createSale($this->createEmployee());

        $sales = (new Dashboard_lib())->sales();

        $this->assertNull($sales['today']['delta']);
    }

    public function testTopItemsRanksSoldItems(): void
    {
        $this->createSale($this->createEmployee());

        $items = (new Dashboard_lib())->topItems(100);

        $this->assertNotEmpty($items);
        $this->assertGreaterThan(0.0, $items[0]['quantity']);
        $quantities = array_column($items, 'quantity');
        $sorted     = $quantities;
        rsort($sorted);
        $this->assertSame($sorted, $quantities);
    }

    public function testLowStockCountsItemsAtOrBelowReorderLevel(): void
    {
        $itemId = $this->createTestItem();
        Database::connect()->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => 1, 'quantity' => 0]);

        $lowStock = (new Dashboard_lib())->lowStock(100);

        $this->assertGreaterThanOrEqual(1, $lowStock['count']);
        $this->assertContains('Test Item', array_column($lowStock['rows'], 'name'));
    }

    public function testExpensesThisMonthSumsActiveExpenses(): void
    {
        $db = Database::connect();
        $db->table('expense_categories')->insert(['category_name' => 'Dashboard ' . uniqid(), 'category_description' => '', 'deleted' => 0]);
        $db->table('expenses')->insert([
            'date'                => date('Y-m-d H:i:s'),
            'amount'              => 125.5,
            'tax_amount'          => 0,
            'payment_type'        => 'Cash',
            'expense_category_id' => (int) $db->insertID(),
            'description'         => 'dashboard fixture',
            'employee_id'         => $this->createEmployee(),
            'deleted'             => 0,
        ]);

        $total = (new Dashboard_lib())->expensesThisMonth();

        $this->assertGreaterThanOrEqual(125.5, $total);
    }

    public function testSales_AddsUpOnlyTheBranchesTheEmployeeWorksInAndKeepsWhatIsKeptForOneSetOfBranchesApart(): void
    {
        $this->createSale($this->createEmployee());

        $other = $this->newBranch();

        $this->loggedInWorkingIn([$other]);
        $inTheOther = (new Dashboard_lib())->sales();

        $this->loggedInWorkingIn([1]);
        $inTheFirst = (new Dashboard_lib())->sales();

        $this->loggedInWorkingIn([], true);
        $inEvery = (new Dashboard_lib())->sales();

        $this->assertSame(0, $inTheOther['today']['count']);
        $this->assertGreaterThanOrEqual(1, $inTheFirst['today']['count']);
        $this->assertSame($inTheFirst['today']['count'], $inEvery['today']['count']);
    }

    public function testTopItems_RanksOnlyWhatTheBranchesTheEmployeeWorksInSold(): void
    {
        $this->createSale($this->createEmployee());

        $other = $this->newBranch();

        $this->loggedInWorkingIn([$other]);
        $this->assertSame([], (new Dashboard_lib())->topItems(100));

        $this->loggedInWorkingIn([1]);
        $this->assertNotEmpty((new Dashboard_lib())->topItems(100));
    }

    public function testExpensesThisMonth_AddsUpTheExpensesOfEveryEmployeeEvenWhenAnOwnRecordsEmployeeIsLoggedIn(): void
    {
        $db = Database::connect();
        $db->table('expense_categories')->insert(['category_name' => 'Dashboard ' . uniqid(), 'category_description' => '', 'deleted' => 0]);
        $db->table('expenses')->insert([
            'date'                => date('Y-m-d H:i:s'),
            'amount'              => 75,
            'tax_amount'          => 0,
            'payment_type'        => 'Cash',
            'expense_category_id' => (int) $db->insertID(),
            'description'         => 'dashboard fixture',
            'employee_id'         => $this->createEmployee(),
            'deleted'             => 0,
        ]);

        (new RoleService())->setRecordScope($this->loggedInWorkingIn([], true), 'own', null);

        $this->assertGreaterThanOrEqual(75.0, (new Dashboard_lib())->expensesThisMonth());
    }

    public function testExpensesThisMonth_HidesTheFigureFromAnEmployeeWhoDoesNotWorkInEveryBranchBecauseExpensesBelongToNoBranch(): void
    {
        $db = Database::connect();
        $db->table('expense_categories')->insert(['category_name' => 'Dashboard ' . uniqid(), 'category_description' => '', 'deleted' => 0]);
        $db->table('expenses')->insert([
            'date'                => date('Y-m-d H:i:s'),
            'amount'              => 75,
            'tax_amount'          => 0,
            'payment_type'        => 'Cash',
            'expense_category_id' => (int) $db->insertID(),
            'description'         => 'dashboard fixture',
            'employee_id'         => $this->createEmployee(),
            'deleted'             => 0,
        ]);

        $this->loggedInWorkingIn([1]);
        $this->assertNull((new Dashboard_lib())->expensesThisMonth());

        $this->loggedInWorkingIn([], true);
        $this->assertGreaterThanOrEqual(75.0, (new Dashboard_lib())->expensesThisMonth());
    }
}
