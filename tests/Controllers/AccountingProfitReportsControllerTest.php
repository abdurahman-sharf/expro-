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

        $this->fetch(self::PROFIT)->assertDontSee('id="report_table"');

        $response = $this->fetch(self::PROFIT . '?show=1&group=item');

        $this->assertSame($this->shown('480'), $this->grandTotal($response, 'profit'));
        $this->assertSame($this->shown('320'), $this->grandTotal($response, 'cost'));
        $this->assertCount(1, $this->tieRows($response));
        $this->assertStringContainsString(lang('Accounting_operational_reports.ties'), $this->tieRows($response)[0]);
    }

    public function testGetIndex_EqualsTheAccountOfTheCostOfSalesForThePeriod(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::PROFIT . '?show=1&group=item');

        $this->assertSame($this->shown($this->balance($this->accountId('cogs'))), $this->grandTotal($response, 'cost'));
    }

    public function testGetIndex_ReadsEachGroupingUnderThePermissionOfWhatItGroupsBy(): void
    {
        $this->reader(['accounting_reports', 'reports_items']);

        $this->fetch(self::PROFIT . '?show=1&group=day')->assertStatus(200);
        $this->fetch(self::PROFIT . '?show=1&group=customer')->assertRedirect();
        $this->fetch(self::PROFIT . '/csv?group=user')->assertRedirect();

        $this->reader(['accounting_reports']);

        $this->fetch(self::PROFIT . '?show=1&group=day')->assertRedirect();
    }

    public function testGetIndex_ReadsEveryDayBeforeADayForAGroupingByPeriodAsForOneByItem(): void
    {
        $this->grantInvoiceDate();

        $goods = $this->item('10');

        foreach ([400, 5] as $days) {
            $this->save([$this->line($goods, 0, '2', '30', '10')], [], [], SALE_TYPE_INVOICE, $this->customerId, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo($days));
        }

        $this->reader();

        $before = '&mode=before&date=' . date('Y-m-d');

        foreach (['day', 'week', 'month', 'year', 'item', 'category', 'customer', 'user'] as $group) {
            $this->assertSame($this->shown('80'), $this->grandTotal($this->fetch(self::PROFIT . '?show=1&group=' . $group . $before), 'profit'), $group);
        }

        $this->assertSame($this->shown('40'), $this->grandTotal($this->fetch(self::PROFIT . '?show=1&group=month'), 'profit'));
    }

    public function testGetIndex_OpensTheFirstGroupingTheEmployeeMayReadWhenGivenNoneAndOffersOnlyTheReportsItMayOpen(): void
    {
        $this->reader(['accounting_reports', 'reports_categories']);

        $profit = $this->fetch(self::PROFIT);
        $items  = $this->fetch(self::ITEMS);
        $links  = fn ($response): array => array_values(array_filter($this->titleBarLinks($response), static fn (string $href): bool => ! str_contains($href, '/csv')));

        $this->assertSame('category', $this->pageOf($profit)->query("//select[@id='f_group']/option[@selected]/@value")->item(0)?->nodeValue);
        $this->assertSame('category', $this->pageOf($items)->query("//select[@id='f_group']/option[@selected]/@value")->item(0)?->nodeValue);
        $this->assertSame([base_url('accounting_reports/item_profit')], $links($profit));
        $this->assertSame([base_url('accounting_reports/profit')], $links($items));
        $this->fetch(self::PROFIT . '?show=1')->assertStatus(200);

        $this->reader(['accounting_reports']);

        $this->fetch(self::PROFIT)->assertRedirect();
    }

    public function testGetIndex_RedirectsSomeoneWithoutThePermissionOfTheCustomerTheEmployeeOrTheCategoryTheyFilterBy(): void
    {
        $this->sell();
        $this->reader(['accounting_reports', 'reports_items']);

        foreach ([self::PROFIT, self::ITEMS] as $screen) {
            $this->fetch($screen . '?show=1&group=item&customer=' . $this->customerId)->assertRedirect();
            $this->fetch($screen . '?show=1&group=item&user=' . $this->employeeId)->assertRedirect();
            $this->fetch($screen . '?show=1&group=item&category=Creams')->assertRedirect();
            $this->fetch($screen . '/csv?group=item&user=' . $this->employeeId)->assertRedirect();
        }

        $response = $this->fetch(self::PROFIT . '?show=1&group=item');

        $response->assertStatus(200);
        $response->assertDontSee('id="f_user"');
        $response->assertDontSee('id="f_customer"');
        $response->assertDontSee('id="f_category"');
        $response->assertSee('id="f_item"');

        $this->reader();

        $this->assertSame($this->shown('480'), $this->grandTotal($this->fetch(self::PROFIT . '?show=1&group=item&user=' . $this->employeeId . '&category=Creams&customer=' . $this->customerId), 'profit'));
    }

    public function testGetIndex_CarriesEveryFilterToTheLinkOfItsFile(): void
    {
        $this->reader();

        $this->assertFileCarries(self::PROFIT, ['group', 'view', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'user', 'item', 'category', 'show']);
        $this->assertFileCarries(self::ITEMS, ['group', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'user', 'item', 'category', 'show']);
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->reader();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->fetch(self::PROFIT);
    }

    public function testGetItems_StartsFromTheLastMonthWhenGivenNoDays(): void
    {
        $this->sell();
        $this->reader();

        $untouched = $this->fetch(self::ITEMS . '?show=1');
        $explicit  = $this->fetch(self::ITEMS . '?show=1&from=2020-01-01');

        $this->assertSame($this->shown('0'), $this->grandTotal($untouched, 'profit'));
        $this->assertSame($this->shown('480'), $this->grandTotal($explicit, 'profit'));
        $untouched->assertSee('value="last_month" selected');
    }

    public function testGetItems_ShowsThePercentagesOfTheProfitOfEachItem(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::ITEMS . '?show=1&from=2020-01-01');

        $this->assertSame(['60.00%'], array_map(static fn (string $text): string => $text, $this->column($response, 'profit_to_sales')));
        $this->assertSame(['150.00%'], $this->column($response, 'profit_to_cost'));
        $this->assertSame('100.00%', $this->grandTotal($response, 'profit_share'));
    }

    public function testGetItems_ReadsEachGroupingUnderItsPermission(): void
    {
        $this->reader(['accounting_reports', 'reports_items']);

        $this->fetch(self::ITEMS . '?show=1&group=item')->assertStatus(200);
        $this->fetch(self::ITEMS . '?show=1&group=category')->assertRedirect();
        $this->fetch(self::ITEMS . '/csv?group=customer')->assertRedirect();
    }

    public function testGetItemsCsv_GivesTheRowsWithThePercentagesAndRefusesABadDay(): void
    {
        $this->sell();
        $this->reader();

        $rows = $this->csvRows($this->fetch(self::ITEMS . '/csv?from=2020-01-01'));

        $this->assertContains('60.00', array_merge(...$rows));
        $this->assertContains('480.0000', array_merge(...$rows));
        $this->fetch(self::ITEMS . '/csv?from=nonsense')->assertStatus(422);
    }

    public function testGetCsv_GivesTheMovementsOfTheDaysWithTheCheck(): void
    {
        $this->sell();
        $this->reader();

        $this->fetch(self::PROFIT . '/csv?from=nonsense')->assertStatus(422);

        $rows = $this->csvRows($this->fetch(self::PROFIT . '/csv?group=item&view=details'));
        $flat = array_merge(...$rows);

        $this->assertContains('480.0000', $flat);
        $this->assertContains(lang('Accounting_operational_reports.ties'), $flat);
    }

    public function testGetIndex_SaysByNameThatTheProfitIsNotReportedWhenADocumentIsInAForeignCurrencyAndOffersNoFile(): void
    {
        $this->enableDollars();
        $invoice = $this->foreignInvoice('100', [], '40.00', '2');
        $this->reader();

        $response = $this->fetch(self::PROFIT . '?show=1&group=item&mode=between&from=2020-01-01&to=' . date('Y-m-d'));

        $response->assertStatus(200);
        $response->assertSee('id="report_refused"');
        $response->assertSee('POS ' . $invoice . ' (USD)');
        $response->assertDontSee('id="report_table"');
        $response->assertDontSee('id="report_csv"');

        $file = $this->fetch(self::PROFIT . '/csv?group=item&mode=between&from=2020-01-01&to=' . date('Y-m-d'));

        $this->assertSame(422, $file->response()->getStatusCode());
        $this->assertStringContainsString('POS ' . $invoice . ' (USD)', $file->response()->getBody());
    }

    public function testGetItems_SaysByNameThatTheProfitByItemIsNotReportedWhenADocumentIsInAForeignCurrencyAndOffersNoFile(): void
    {
        $this->enableDollars();
        $invoice = $this->foreignInvoice('100', [], '40.00', '2');
        $this->reader();

        $response = $this->fetch(self::ITEMS . '?show=1&group=item&mode=between&from=2020-01-01&to=' . date('Y-m-d'));

        $response->assertStatus(200);
        $response->assertSee('id="report_refused"');
        $response->assertSee('POS ' . $invoice . ' (USD)');
        $response->assertDontSee('id="report_table"');
        $response->assertDontSee('id="report_csv"');

        $file = $this->fetch(self::ITEMS . '/csv?group=item&mode=between&from=2020-01-01&to=' . date('Y-m-d'));

        $this->assertSame(422, $file->response()->getStatusCode());
    }

    public function testGetIndex_ShowsTheProfitAndItsFileWhenNoDocumentIsInAForeignCurrency(): void
    {
        $this->enableDollars();
        $this->sell();
        $this->reader();

        $query    = '?show=1&group=item&mode=between&from=2020-01-01&to=' . date('Y-m-d');
        $response = $this->fetch(self::PROFIT . $query);

        $response->assertDontSee('id="report_refused"');
        $response->assertSee('id="report_table"');
        $response->assertSee('id="report_csv"');
        $this->assertSame(200, $this->fetch(self::PROFIT . '/csv?group=item&mode=between&from=2020-01-01&to=' . date('Y-m-d'))->response()->getStatusCode());
    }
}
