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
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleFixtureTrait;

/**
 * @internal
 */
final class Dashboard_libTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use RoleFixtureTrait;
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
        cache()->deleteMatching('dashboard_*');
    }

    private function employeeOfEveryBranch(): int
    {
        return $this->createEmployee(grants: [['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--']]);
    }

    private function ownRecordsEmployee(): int
    {
        $personId = $this->employeeOfEveryBranch();

        (new RoleService())->setRecordScope($personId, 'own', null);

        return $personId;
    }

    /**
     * The dashboard a request of the employee builds, or of nobody, which is the system itself.
     */
    private function seenBy(?int $personId): Dashboard_lib
    {
        $personId === null ? session()->remove('person_id') : session()->set('person_id', $personId);

        return new Dashboard_lib();
    }

    /**
     * Makes a sale of the employee, of the quantity given of an item no other sale has, and returns the name of the item.
     */
    private function soldBy(int $employeeId, float $quantity = 1.0): string
    {
        $saleId = $this->createSale($employeeId);
        $db     = Database::connect();

        $db->table('sales_items')->where('sale_id', $saleId)->update(['quantity_purchased' => $quantity]);

        return (string) $db->table('sales_items')->select('items.name')->join('items', 'items.item_id = sales_items.item_id')->where('sales_items.sale_id', $saleId)->get()->getRow()->name;
    }

    private function spent(int $employeeId, float $amount): void
    {
        $db = Database::connect();

        $db->table('expense_categories')->insert(['category_name' => 'Dashboard ' . uniqid(), 'category_description' => '', 'deleted' => 0]);
        $db->table('expenses')->insert([
            'date'                => date('Y-m-d H:i:s'),
            'amount'              => $amount,
            'tax_amount'          => 0,
            'payment_type'        => 'Cash',
            'expense_category_id' => (int) $db->insertID(),
            'description'         => 'dashboard fixture',
            'employee_id'         => $employeeId,
            'deleted'             => 0,
        ]);
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

    public function testSales_AddsUpOnlyTheSalesOfAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $own   = $this->ownRecordsEmployee();
        $other = $this->employeeOfEveryBranch();
        $this->createSale($own);
        $this->createSale($other);
        $this->createSale($other);

        $sales = $this->seenBy($own)->sales();

        $this->assertSame(1, $sales['today']['count']);
        $this->assertEqualsWithDelta(1.0, $sales['today']['total'], 0.001);
        $this->assertEqualsWithDelta(1.0, $sales['week']['total'], 0.001);
        $this->assertEqualsWithDelta(1.0, $sales['month']['total'], 0.001);
        $this->assertEqualsWithDelta(1.0, end($sales['series']['values']), 0.001);
    }

    public function testSales_AddsUpEverySaleOfTheCompanyForAnEmployeeWhoSeesEveryRecord(): void
    {
        $this->createSale($this->ownRecordsEmployee());
        $this->createSale($this->employeeOfEveryBranch());
        $this->createSale($this->employeeOfEveryBranch());

        $this->assertGreaterThanOrEqual(3, $this->seenBy($this->employeeOfEveryBranch())->sales()['today']['count']);
    }

    public function testSales_KeepsTheFiguresOfOneOwnRecordsEmployeeApartFromThoseOfAnother(): void
    {
        $first  = $this->ownRecordsEmployee();
        $second = $this->ownRecordsEmployee();
        $this->createSale($first);
        $this->createSale($second);
        $this->createSale($second);

        $this->assertSame(1, $this->seenBy($first)->sales()['today']['count']);
        $this->assertSame(2, $this->seenBy($second)->sales()['today']['count']);
        $this->assertSame(1, $this->seenBy($first)->sales()['today']['count']);
    }

    public function testSales_ShowsTheCompanyToAnEmployeeWhoSeesEveryRecordRightAfterAnEmployeeWhoSeesTheirOwn(): void
    {
        $own = $this->ownRecordsEmployee();
        $this->createSale($own);
        $this->createSale($this->employeeOfEveryBranch());
        $this->createSale($this->employeeOfEveryBranch());

        $this->assertSame(1, $this->seenBy($own)->sales()['today']['count']);
        $this->assertGreaterThanOrEqual(3, $this->seenBy($this->employeeOfEveryBranch())->sales()['today']['count']);
    }

    public function testSales_ShowsAnEmployeeWhoSeesTheirOwnRecordsOnlyTheirOwnRightAfterAnEmployeeWhoSeesEveryRecord(): void
    {
        $own = $this->ownRecordsEmployee();
        $this->createSale($own);
        $this->createSale($this->employeeOfEveryBranch());
        $this->createSale($this->employeeOfEveryBranch());

        $this->assertGreaterThanOrEqual(3, $this->seenBy($this->employeeOfEveryBranch())->sales()['today']['count']);
        $this->assertSame(1, $this->seenBy($own)->sales()['today']['count']);
    }

    public function testSales_SharesOneEntryBetweenEmployeesWhoSeeEveryRecordAndKeepsTheOwnOnesApart(): void
    {
        $own = $this->ownRecordsEmployee();
        $day = date('Ymd');
        $this->createSale($own);

        $this->seenBy($own)->sales();

        $this->assertNull(cache()->get('dashboard_sales_' . $day));
        $this->assertNotNull(cache()->get("dashboard_sales_o{$own}_{$day}"));

        $first = $this->seenBy($this->employeeOfEveryBranch())->sales()['today']['count'];
        $this->createSale($this->employeeOfEveryBranch());

        $this->assertNotNull(cache()->get('dashboard_sales_' . $day));
        $this->assertSame($first, $this->seenBy($this->employeeOfEveryBranch())->sales()['today']['count']);
    }

    public function testSales_FollowsAnEmployeeWhoseRecordScopeChangesInsideTheCachePeriod(): void
    {
        $personId = $this->employeeOfEveryBranch();
        $this->createSale($personId);
        $this->createSale($this->employeeOfEveryBranch());
        $this->createSale($this->employeeOfEveryBranch());

        $this->assertGreaterThanOrEqual(3, $this->seenBy($personId)->sales()['today']['count']);

        (new RoleService())->setRecordScope($personId, 'own', null);

        $this->assertSame(1, $this->seenBy($personId)->sales()['today']['count']);

        (new RoleService())->setRecordScope($personId, 'all', null);

        $this->assertGreaterThanOrEqual(3, $this->seenBy($personId)->sales()['today']['count']);
    }

    public function testSales_CutsAnEmployeeWhoSeesTheirOwnRecordsAlsoToTheBranchesTheyWorkIn(): void
    {
        $inTheOther = $this->loggedInWorkingIn([$this->newBranch()]);
        (new RoleService())->setRecordScope($inTheOther, 'own', null);
        $this->createSale($inTheOther);

        $this->assertSame(0, (new Dashboard_lib())->sales()['today']['count']);

        $inTheFirst = $this->loggedInWorkingIn([1]);
        (new RoleService())->setRecordScope($inTheFirst, 'own', null);
        $this->createSale($inTheFirst);

        $this->assertSame(1, (new Dashboard_lib())->sales()['today']['count']);
    }

    public function testSales_FollowsAnEmployeeWhoSeesTheirOwnRecordsAndMovesToAnotherBranchInsideTheCachePeriod(): void
    {
        $personId = $this->loggedInWorkingIn([1]);
        (new RoleService())->setRecordScope($personId, 'own', null);
        $this->createSale($personId);

        $this->assertSame(1, (new Dashboard_lib())->sales()['today']['count']);

        Database::connect()->table('employee_branches')->where('person_id', $personId)->update(['branch_id' => $this->newBranch()]);

        $this->assertSame(0, (new Dashboard_lib())->sales()['today']['count']);
    }

    public function testSales_CutsAnEmployeeToTheirOwnSalesWhenTheirRoleSeesOwnRecordsOnly(): void
    {
        $personId = $this->employeeWithRoles([$this->createRole([], 'own')], [BranchGuard::ALL_BRANCHES]);
        $this->createSale($personId);
        $this->createSale($this->employeeOfEveryBranch());

        $this->assertSame(1, $this->seenBy($personId)->sales()['today']['count']);
    }

    public function testSales_CutsAnEmployeeToTheirOwnSalesWhenTheRoleThatLimitsThemIsInactive(): void
    {
        $personId = $this->employeeWithRoles([$this->createRole([], 'own', false)], [BranchGuard::ALL_BRANCHES]);
        $this->createSale($personId);
        $this->createSale($this->employeeOfEveryBranch());

        $this->assertSame(1, $this->seenBy($personId)->sales()['today']['count']);
    }

    public function testSales_AddsUpEverySaleOfTheCompanyWhenNobodyIsLoggedIn(): void
    {
        $this->createSale($this->ownRecordsEmployee());
        $this->createSale($this->employeeOfEveryBranch());
        $this->createSale($this->employeeOfEveryBranch());

        $this->assertGreaterThanOrEqual(3, $this->seenBy(null)->sales()['today']['count']);
    }

    public function testTopItems_RanksOnlyWhatAnEmployeeWhoSeesTheirOwnRecordsSold(): void
    {
        $own  = $this->ownRecordsEmployee();
        $mine = $this->soldBy($own, 2.0);
        $this->soldBy($this->employeeOfEveryBranch(), 500.0);

        $this->assertSame([$mine], array_column($this->seenBy($own)->topItems(100), 'name'));
    }

    public function testTopItems_KeepsTheRankingOfOneOwnRecordsEmployeeApartFromTheCompanysAndFromAnotherEmployees(): void
    {
        $first  = $this->ownRecordsEmployee();
        $second = $this->ownRecordsEmployee();
        $ofFirst  = $this->soldBy($first);
        $ofSecond = $this->soldBy($second);

        $this->assertSame([$ofFirst], array_column($this->seenBy($first)->topItems(100), 'name'));
        $this->assertContains($ofSecond, array_column($this->seenBy($this->employeeOfEveryBranch())->topItems(100), 'name'));
        $this->assertSame([$ofSecond], array_column($this->seenBy($second)->topItems(100), 'name'));
        $this->assertSame([$ofFirst], array_column($this->seenBy($first)->topItems(100), 'name'));
    }

    public function testExpensesThisMonth_AddsUpOnlyTheExpensesOfAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $own = $this->ownRecordsEmployee();
        $this->spent($own, 40);
        $this->spent($this->employeeOfEveryBranch(), 75);

        $this->assertEqualsWithDelta(40.0, $this->seenBy($own)->expensesThisMonth(), 0.001);
        $this->assertGreaterThanOrEqual(115.0, $this->seenBy($this->employeeOfEveryBranch())->expensesThisMonth());
    }

    public function testExpensesThisMonth_KeepsTheFigureOfOneOwnRecordsEmployeeApartFromTheCompanysAndFromAnotherEmployees(): void
    {
        $first  = $this->ownRecordsEmployee();
        $second = $this->ownRecordsEmployee();
        $this->spent($first, 40);

        $this->assertEqualsWithDelta(40.0, $this->seenBy($first)->expensesThisMonth(), 0.001);
        $this->assertSame(0.0, $this->seenBy($second)->expensesThisMonth());
        $this->assertGreaterThanOrEqual(40.0, $this->seenBy($this->employeeOfEveryBranch())->expensesThisMonth());
        $this->assertSame(0.0, $this->seenBy($second)->expensesThisMonth());
    }

    public function testExpensesThisMonth_StaysHiddenFromAnEmployeeWhoSeesTheirOwnRecordsAndDoesNotWorkInEveryBranch(): void
    {
        $this->spent($this->employeeOfEveryBranch(), 75);

        $personId = $this->loggedInWorkingIn([1]);
        (new RoleService())->setRecordScope($personId, 'own', null);

        $this->assertNull((new Dashboard_lib())->expensesThisMonth());
    }

    public function testLowStock_SharesOneEntryWithAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $this->seenBy($this->ownRecordsEmployee())->lowStock(100);

        $this->assertNotNull(cache()->get('dashboard_low_stock_100_' . date('Ymd')));
    }
}
