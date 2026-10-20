<?php

namespace Tests\Libraries;

use App\Libraries\Dashboard_lib;
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

        parent::tearDown();
    }

    private function clearCache(): void
    {
        foreach (['sales', 'top_items_100', 'low_stock_100', 'expenses_month'] as $section) {
            cache()->delete('dashboard_' . $section . '_' . date('Ymd'));
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
}
