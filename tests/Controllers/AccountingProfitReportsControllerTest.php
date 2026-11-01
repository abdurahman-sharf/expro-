<?php

namespace Tests\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\OperationalReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screens of the profit from the stock movements and of the profit by item: the cost against the cost of
 * sales of the books, the percentages, the last month the profit by item starts from, the permission of each
 * grouping, and the file.
 */
class AccountingProfitReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use OperationalReportFixtureTrait;
    use SaleAccountingFixtureTrait;

    private const PROFIT = '/accounting_reports/profit';
    private const ITEMS  = '/accounting_reports/item_profit';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function sell(): void
    {
        $item = $this->item('40');
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['name' => 'Cream', 'category' => 'Creams']);
        $sale = $this->save([$this->line($item, 0, '10', '100', '40')], [], [], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($item, 0, '-2', '100', '40')], [], [], SALE_TYPE_RETURN, $this->customerId, true, $sale);
    }

    public function testGetIndex_ShowsNoTableUntilRunAndThenTheProfitWithTheCostTiedToTheBooks(): void
    {
        $this->sell();
        $this->reader();

        $this->get(self::PROFIT)->assertDontSee('id="report_table"');

        $response = $this->get(self::PROFIT . '?show=1&group=item');

        $this->assertSame($this->shown('480'), $this->grandTotal($response, 'profit'));
        $this->assertSame($this->shown('320'), $this->grandTotal($response, 'cost'));
        $this->assertCount(1, $this->tieRows($response));
        $this->assertStringContainsString(lang('Accounting_operational_reports.ties'), $this->tieRows($response)[0]);
    }

    public function testGetIndex_EqualsTheAccountOfTheCostOfSalesForThePeriod(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->get(self::PROFIT . '?show=1&group=item');

        $this->assertSame($this->shown($this->balance($this->accountId('cogs'))), $this->grandTotal($response, 'cost'));
    }

    public function testGetIndex_ReadsEachGroupingUnderThePermissionOfWhatItGroupsBy(): void
    {
        $this->reader(['accounting_reports', 'reports_items']);

        $this->get(self::PROFIT . '?show=1&group=day')->assertStatus(200);
        $this->get(self::PROFIT . '?show=1&group=customer')->assertRedirect();
        $this->get(self::PROFIT . '/csv?group=user')->assertRedirect();

        $this->reader(['accounting_reports']);

        $this->get(self::PROFIT . '?show=1&group=day')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->reader();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get(self::PROFIT);
    }

    public function testGetItems_StartsFromTheLastMonthWhenGivenNoDays(): void
    {
        $this->sell();
        $this->reader();

        $untouched = $this->get(self::ITEMS . '?show=1');
        $explicit  = $this->get(self::ITEMS . '?show=1&from=2020-01-01');

        $this->assertSame($this->shown('0'), $this->grandTotal($untouched, 'profit'));
        $this->assertSame($this->shown('480'), $this->grandTotal($explicit, 'profit'));
        $untouched->assertSee('value="last_month" selected');
    }

    public function testGetItems_ShowsThePercentagesOfTheProfitOfEachItem(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->get(self::ITEMS . '?show=1&from=2020-01-01');

        $this->assertSame(['60.00%'], array_map(static fn (string $text): string => $text, $this->column($response, 'profit_to_sales')));
        $this->assertSame(['150.00%'], $this->column($response, 'profit_to_cost'));
        $this->assertSame('100.00%', $this->grandTotal($response, 'profit_share'));
    }

    public function testGetItems_ReadsEachGroupingUnderItsPermission(): void
    {
        $this->reader(['accounting_reports', 'reports_items']);

        $this->get(self::ITEMS . '?show=1&group=item')->assertStatus(200);
        $this->get(self::ITEMS . '?show=1&group=category')->assertRedirect();
        $this->get(self::ITEMS . '/csv?group=customer')->assertRedirect();
    }

    public function testGetItemsCsv_GivesTheRowsWithThePercentagesAndRefusesABadDay(): void
    {
        $this->sell();
        $this->reader();

        $rows = $this->csvRows($this->get(self::ITEMS . '/csv?from=2020-01-01'));

        $this->assertContains('60.00', array_merge(...$rows));
        $this->assertContains('480.0000', array_merge(...$rows));
        $this->get(self::ITEMS . '/csv?from=nonsense')->assertStatus(422);
    }

    public function testGetCsv_GivesTheMovementsOfTheDaysWithTheCheck(): void
    {
        $this->sell();
        $this->reader();

        $rows = $this->csvRows($this->get(self::PROFIT . '/csv?group=item&view=details'));
        $flat = array_merge(...$rows);

        $this->assertContains('480.0000', $flat);
        $this->assertContains(lang('Accounting_operational_reports.ties'), $flat);
    }
}
