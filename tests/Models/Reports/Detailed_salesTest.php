<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Detailed_sales;
use App\Models\Sale;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class Detailed_salesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const MONTH = '2003-04-';

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->dropReportTables();
        $this->removeTheSalesOfTheMonth();
    }

    protected function tearDown(): void
    {
        $this->dropReportTables();
        $this->removeTheSalesOfTheMonth();

        parent::tearDown();
    }

    private function removeTheSalesOfTheMonth(): void
    {
        $db  = Database::connect();
        $ids = array_column($db->table('sales')->select('sale_id')->like('sale_time', self::MONTH, 'after')->get()->getResultArray(), 'sale_id');

        if ($ids === []) {
            return;
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['sales_payments', 'sales_items_taxes', 'sales_items', 'sales_taxes', 'sales'] as $table) {
            $db->table($table)->whereIn('sale_id', $ids)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function dropReportTables(): void
    {
        $db = Database::connect();

        foreach (['sales_items_temp', 'sales_payments_temp', 'sales_items_taxes_temp'] as $table) {
            $db->query('DROP TEMPORARY TABLE IF EXISTS ' . $db->prefixTable($table));
        }
    }

    /**
     * @param array{type: int, value: string}|null $invoiceDiscount
     */
    private function sale(int $itemId, string $quantity, string $price, ?array $invoiceDiscount, string $day = self::MONTH . '05'): int
    {
        $status = COMPLETED;
        $items  = [0 => ['item_id' => $itemId, 'line' => 0, 'description' => 'Test Item', 'serialnumber' => '', 'quantity' => $quantity, 'discount' => '0', 'discount_type' => PERCENT, 'cost_price' => '1', 'price' => $price, 'item_location' => 1, 'print_option' => 0]];
        $taxes  = [[], []];
        $saleId = model(Sale::class)->save_value(NEW_ENTRY, $status, $items, NEW_ENTRY, $this->createEmployee(), 'report', null, null, null, SALE_TYPE_POS, [], null, $taxes, true, null, $invoiceDiscount);

        Database::connect()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $day . ' 10:00:00']);

        return $saleId;
    }

    /**
     * @return array<string, mixed>
     */
    private function inputs(): array
    {
        return ['start_date' => self::MONTH . '05', 'end_date' => self::MONTH . '05', 'sale_type' => 'complete', 'location_id' => 'all', 'definition_ids' => []];
    }

    public function testGetDataColumns_NamesTheDiscountOnTheWholeOfTheSaleBesideTheSubtotal(): void
    {
        $columns = array_map(static fn (array $column): string => (string) array_key_first($column), (new Detailed_sales())->getDataColumns()['summary']);

        $this->assertSame($columns[array_search('subtotal', $columns, true) - 1], 'invoice_discount');
    }

    public function testGetData_ListsTheDiscountOnTheWholeOfEachSaleAndNothingForASaleWithoutOne(): void
    {
        $itemId     = $this->createTestItem(HAS_NO_STOCK);
        $discounted = $this->sale($itemId, '2', '50', ['type' => FIXED, 'value' => '12.5']);
        $plain      = $this->sale($itemId, '1', '30', null);
        $report     = new Detailed_sales();

        $report->create($this->inputs());

        $rows = array_column($report->getData($this->inputs())['summary'], null, 'sale_id');

        $this->assertSame('12.50', bcadd((string) $rows[$discounted]['invoice_discount'], '0', 2));
        $this->assertSame('87.50', bcadd((string) $rows[$discounted]['subtotal'], '0', 2));
        $this->assertSame('87.50', bcadd((string) $rows[$discounted]['total'], '0', 2));
        $this->assertSame('0.00', bcadd((string) $rows[$plain]['invoice_discount'], '0', 2));
        $this->assertSame('30.00', bcadd((string) $rows[$plain]['total'], '0', 2));
    }

    public function testGetDataBySaleId_GivesTheDiscountOnTheWholeOfTheSale(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->sale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10']);
        $report = new Detailed_sales();

        $report->create(['sale_id' => $saleId]);

        $row = $report->getDataBySaleId($saleId);

        $this->assertSame('10.00', bcadd((string) $row['invoice_discount'], '0', 2));
        $this->assertSame('90.00', bcadd((string) $row['total'], '0', 2));
    }

    public function testGetSummaryData_AddsUpTheSalesOfTheDayAfterTheirDiscounts(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->sale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10']);
        $this->sale($itemId, '1', '30', null);

        $report = new Detailed_sales();

        $report->create($this->inputs());

        $this->assertSame('120.00', bcadd((string) $report->getSummaryData($this->inputs())['total'], '0', 2));
    }
}
