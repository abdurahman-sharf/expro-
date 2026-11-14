<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\OperationalReports\DateFilter;
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
 * The screens of the revenue and of the sales of each customer: who may open them and under which
 * permission of the grouping, that nothing is shown until the report is run, the shortcuts and formats of
 * the days, the branches each reader adds up, and the file that comes out of them.
 */
class AccountingSalesReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use OperationalReportFixtureTrait;
    use SaleAccountingFixtureTrait;

    private const REVENUE = '/accounting_reports/revenue';
    private const CLIENTS = '/accounting_reports/clients_sales';

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
        $paid = $this->invoiceOf('115', $this->customerId, [$this->payment(lang('Sales.cash'), '100')]);
        $this->invoiceOf('230', $this->anotherCustomer());
        $this->creditNoteOf($paid, '57.50', $this->customerId);
    }

    public function testGetRevenue_ShowsNoTableUntilTheReportIsRun(): void
    {
        $this->reader();

        $response = $this->fetch(self::REVENUE);

        $response->assertStatus(200);
        $response->assertSee('id="report_not_run"');
        $response->assertDontSee('id="report_table"');
    }

    public function testGetRevenue_ShowsTheTableWhenRunWithEveryFilterInTheAddress(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::REVENUE . '?show=1&group=customer&view=details');

        $response->assertSee('id="report_table"');
        $this->assertSame($this->shown('287.50'), $this->grandTotal($response, 'total'));
        $this->assertContains($this->shown('230'), $this->column($response, 'total'));
        $response->assertSee('id="report_csv"');
        $response->assertSee('show=1');
        $response->assertSee('group=customer');
    }

    public function testGetRevenue_SaysWhetherTheTotalTheTaxAndTheRevenueAgreeWithTheJournals(): void
    {
        $this->sell();
        $this->reader();

        $ties = $this->tieRows($this->fetch(self::REVENUE . '?show=1&group=customer'));

        $this->assertCount(3, $ties);

        foreach ($ties as $row) {
            $this->assertStringContainsString(lang('Accounting_operational_reports.ties'), $row);
            $this->assertStringNotContainsString(lang('Accounting_operational_reports.differs'), $row);
        }
    }

    public function testGetRevenue_SaysByNameThatTheChecksAreNotMadeWhenADocumentIsInAForeignCurrencyOnTheScreenAndInTheFile(): void
    {
        $this->sell();
        $this->reader();

        (new Currencies())->setActive('USD', true);
        $this->accountingDb()->table('sales')->orderBy('sale_id', 'DESC')->limit(1)->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $saleId  = (int) $this->accountingDb()->table('sales')->selectMax('sale_id')->get()->getRow()->sale_id;
        $refusal = lang('Accounting_operational_reports.tie_foreign_currency', ['POS ' . $saleId . ' (USD)']);
        $ties    = $this->tieRows($this->fetch(self::REVENUE . '?show=1&group=customer'));

        $this->assertCount(3, $ties);

        foreach ($ties as $row) {
            $this->assertStringContainsString($refusal, $row);
            $this->assertStringNotContainsString(lang('Accounting_operational_reports.differs'), $row);
        }

        $csv = $this->fetch(self::REVENUE . '/csv?group=customer')->response()->getBody();

        $this->assertStringContainsString($refusal, $csv);
        $this->assertStringNotContainsString(lang('Accounting_operational_reports.differs'), $csv);
    }

    public function testGetRevenue_RedirectsSomeoneWithoutTheModuleOfTheFinancialReports(): void
    {
        $this->reader(['reports_sales', 'reports_customers']);

        $this->fetch(self::REVENUE)->assertRedirect();
    }

    public function testGetRevenue_RedirectsSomeoneWithoutThePermissionOfTheGrouping(): void
    {
        $this->reader(['accounting_reports', 'reports_sales']);

        $this->fetch(self::REVENUE . '?show=1&group=day')->assertStatus(200);
        $this->fetch(self::REVENUE . '?show=1&group=customer')->assertRedirect();
        $this->fetch(self::REVENUE . '?show=1&group=user')->assertRedirect();
        $this->fetch(self::REVENUE . '/csv?group=customer')->assertRedirect();
    }

    public function testGetRevenue_OffersOnlyTheGroupingsTheEmployeeMayRead(): void
    {
        $this->reader(['accounting_reports', 'reports_customers']);

        $response = $this->fetch(self::REVENUE . '?group=customer');

        $response->assertSee('value="customer"');
        $response->assertDontSee('value="day"');
        $response->assertDontSee('value="user"');
    }

    public function testGetRevenue_ReadsEveryDayBeforeADayForAGroupingByPeriodAsForOneByCustomer(): void
    {
        $this->invoiceDated('10', $this->daysAgo(400), null, $this->customerId);
        $this->invoiceDated('20', $this->daysAgo(5), null, $this->customerId);
        $this->reader();

        $before = '&mode=before&date=' . date('Y-m-d');

        foreach (['day', 'week', 'month', 'year', 'customer'] as $group) {
            $this->assertSame($this->shown('30'), $this->grandTotal($this->fetch(self::REVENUE . '?show=1&group=' . $group . $before), 'total'), $group);
        }

        $this->assertSame($this->shown('20'), $this->grandTotal($this->fetch(self::REVENUE . '?show=1&group=month'), 'total'));
        $this->fetch(self::REVENUE . '?show=1&group=month' . $before)->assertSee(lang('Accounting_operational_reports.all_dates_until'));
    }

    public function testGetRevenue_OpensTheFirstGroupingTheEmployeeMayReadWhenGivenNone(): void
    {
        $this->reader(['accounting_reports', 'reports_customers']);

        $response = $this->fetch(self::REVENUE);

        $response->assertStatus(200);
        $this->assertSame('customer', $this->pageOf($response)->query("//select[@id='f_group']/option[@selected]/@value")->item(0)?->nodeValue);
        $response->assertSee('id="report_reset"');
        $this->fetch(self::REVENUE . '?show=1')->assertStatus(200);
    }

    public function testGetRevenue_OffersTheOtherReportOnlyToWhoMayOpenIt(): void
    {
        $links = fn (string $route): array => array_values(array_filter($this->titleBarLinks($this->fetch($route)), static fn (string $href): bool => ! str_contains($href, '/csv')));

        $this->reader(['accounting_reports', 'reports_sales']);
        $this->assertSame([], $links(self::REVENUE));

        $this->reader(['accounting_reports', 'reports_sales', 'reports_customers']);
        $this->assertSame([base_url('accounting_reports/clients_sales')], $links(self::REVENUE));
        $this->assertSame([base_url('accounting_reports/revenue')], $links(self::CLIENTS));

        $this->reader(['accounting_reports', 'reports_employees']);
        $this->assertSame([], $links(self::REVENUE));

        $this->reader(['accounting_reports', 'reports_customers']);
        $this->assertSame([base_url('accounting_reports/revenue')], $links(self::CLIENTS));
    }

    public function testGetRevenue_RedirectsSomeoneWithoutThePermissionOfTheCustomerOrTheEmployeeTheyFilterBy(): void
    {
        $this->reader(['accounting_reports', 'reports_sales']);

        $this->fetch(self::REVENUE . '?show=1&group=day&customer=' . $this->customerId)->assertRedirect();
        $this->fetch(self::REVENUE . '?show=1&group=day&user=' . $this->employeeId)->assertRedirect();
        $this->fetch(self::REVENUE . '/csv?group=day&user=' . $this->employeeId)->assertRedirect();

        $response = $this->fetch(self::REVENUE . '?show=1&group=day&customer=');

        $response->assertStatus(200);
        $response->assertDontSee('id="f_customer"');
        $response->assertDontSee('id="f_user"');
    }

    public function testGetRevenue_FiltersByTheCustomerAndTheEmployeeForWhoMayReadThem(): void
    {
        $this->sell();
        $this->reader();

        $mine  = $this->fetch(self::REVENUE . '?show=1&group=day&customer=' . $this->customerId);
        $byMe  = $this->fetch(self::REVENUE . '?show=1&group=day&user=' . $this->employeeId);
        $other = $this->fetch(self::REVENUE . '?show=1&group=day&user=999999');

        $mine->assertSee('id="f_customer"');
        $this->assertSame($this->shown('57.50'), $this->grandTotal($mine, 'total'));
        $this->assertSame($this->shown('287.50'), $this->grandTotal($byMe, 'total'));
        $this->assertSame($this->shown('0'), $this->grandTotal($other, 'total'));
    }

    public function testGetRevenue_ShowsTheDaysAsAFormTakesThemAndRunsNothingForADayThatIsNotOne(): void
    {
        $this->sell();
        $this->reader();

        $typed = $this->fetch(self::REVENUE . '?show=1&group=customer&from=01%2F01%2F2020&to=' . urlencode(date('d/m/Y')));
        $bad   = $this->fetch(self::REVENUE . '?show=1&group=customer&from=31%2F02%2026');

        $typed->assertSee('name="from" class="form-control form-control-sm" value="2020-01-01"');
        $typed->assertSee('name="to" class="form-control form-control-sm" value="' . date('Y-m-d') . '"');
        $bad->assertSee('id="report_error"');
        $bad->assertSee('id="report_not_run"');
        $bad->assertDontSee('id="report_table"');
        $bad->assertDontSee('id="report_csv"');
    }

    public function testGetRevenue_GivesTheGrandTotalARowHeaderOnlyWhereItHasText(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::REVENUE . '?show=1&group=customer&view=details');

        $response->assertDontSee('<th scope="row"></th>');
        $this->assertSame(1, $this->pageOf($response)->query("//table[@id='report_table']/tfoot//th[@scope='row'][@colspan]")->length);
        $this->assertSame(lang('Accounting_operational_reports.grand_total'), trim($this->pageOf($response)->query("//table[@id='report_table']/tfoot//th")->item(0)->textContent));
    }

    public function testGetRevenue_SaysWhenTheReaderHasNoBranchAndWhenTheReaderSeesSomeOfThem(): void
    {
        $second = $this->createBranch('RB4', 'Fourth report branch');
        $third  = $this->createBranch('RB5', 'Fifth report branch');
        $this->invoiceInBranch($second, '70', [], $this->customerId);
        $this->invoiceInBranch($third, '30', [], $this->customerId);

        $this->reader($this->everyReportPermission(), []);
        $none = $this->fetch(self::REVENUE . '?show=1&group=customer');

        $this->reader($this->everyReportPermission(), [1, $second]);
        $some = $this->fetch(self::REVENUE . '?show=1&group=customer');

        $this->reader();
        $all = $this->fetch(self::REVENUE . '?show=1&group=customer');

        $none->assertSee('id="report_no_branches"');
        $this->assertSame($this->shown('0'), $this->grandTotal($none, 'total'));
        $some->assertSee('id="report_scope"');
        $some->assertDontSee('id="report_no_branches"');
        $this->assertSame($this->shown('70'), $this->grandTotal($some, 'total'));
        $all->assertDontSee('id="report_no_branches"');
    }

    public function testGetRevenue_ShowsAnEmptyPeriodWithItsMessage(): void
    {
        $this->reader();

        $response = $this->fetch(self::REVENUE . '?show=1&group=customer&preset=last_year');

        $response->assertSee('id="report_empty"');
        $this->sell();
        $this->fetch(self::REVENUE . '?show=1&group=customer')->assertDontSee('id="report_empty"');
    }

    public function testGetRevenue_CarriesEveryFilterToTheLinkOfItsFile(): void
    {
        $this->reader();

        $this->assertFileCarries(self::REVENUE, ['group', 'view', 'preset', 'mode', 'from', 'to', 'date', 'status', 'customer', 'user', 'not_taxed', 'show']);
        $this->assertFileCarries(self::CLIENTS, ['type', 'group', 'view', 'order', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'show']);
    }

    public function testGetRevenue_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->reader();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->fetch(self::REVENUE);
    }

    public function testGetRevenue_OffersTheShortcutsAndTheModesOfTheDays(): void
    {
        $this->reader();

        $response = $this->fetch(self::REVENUE);

        foreach (DateFilter::PRESETS as $preset) {
            $response->assertSee('value="' . $preset . '"');
        }

        foreach (DateFilter::MODES as $mode) {
            $response->assertSee('value="' . $mode . '"');
        }
    }

    public function testGetRevenue_AcceptsADayTypedAsDayMonthYearAndRefusesAnImpossibleOne(): void
    {
        $this->sell();
        $this->reader();

        $good = $this->fetch(self::REVENUE . '?show=1&group=customer&from=01%2F01%2F2020&to=' . urlencode(date('d/m/Y')));
        $bad  = $this->fetch(self::REVENUE . '?show=1&group=customer&from=31%2F02%2F2026');

        $good->assertDontSee('id="report_error"');
        $this->assertSame($this->shown('287.50'), $this->grandTotal($good, 'total'));
        $bad->assertSee('id="report_error"');
        $bad->assertSee(lang('Accounting.error_invalid_date'));
    }

    public function testGetRevenue_RefusesAPeriodThatEndsBeforeItStarts(): void
    {
        $this->reader();

        $response = $this->fetch(self::REVENUE . '?show=1&from=2026-10-31&to=2026-10-01');

        $response->assertSee('id="report_error"');
        $response->assertSee(lang('Accounting.error_report_period_invalid'));
    }

    public function testGetRevenue_ReadsTheDaysOfAShortcut(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::REVENUE . '?show=1&group=customer&preset=last_year');

        $this->assertSame($this->shown('0'), $this->grandTotal($response, 'total'));
    }

    public function testGetRevenue_AddsUpOnlyTheBranchesOfTheReader(): void
    {
        $second = $this->createBranch('RB2', 'Second report branch');
        $this->invoiceInBranch(1, '100', [], $this->customerId);
        $this->invoiceInBranch($second, '70', [], $this->customerId);

        $this->reader([...$this->everyReportPermission()], [1]);
        $mine = $this->fetch(self::REVENUE . '?show=1&group=customer');

        $this->reader([...$this->everyReportPermission()], null);
        $all      = $this->fetch(self::REVENUE . '?show=1&group=customer');
        $onlyBoth = $this->fetch(self::REVENUE . '?show=1&group=customer&branches[]=' . $second);

        $this->assertSame($this->shown('100'), $this->grandTotal($mine, 'total'));
        $mine->assertDontSee('name="branches[]"');
        $this->assertSame($this->shown('170'), $this->grandTotal($all, 'total'));
        $all->assertSee('name="branches[]"');
        $this->assertSame($this->shown('70'), $this->grandTotal($onlyBoth, 'total'));
    }

    public function testGetRevenue_NeverGivesABranchOutsideTheScopeOfTheReader(): void
    {
        $second = $this->createBranch('RB3', 'Third report branch');
        $this->invoiceInBranch($second, '70', [], $this->customerId);

        $this->reader($this->everyReportPermission(), [1]);

        $response = $this->fetch(self::REVENUE . '?show=1&group=customer&branches[]=' . $second);

        $this->assertSame($this->shown('0'), $this->grandTotal($response, 'total'));
    }

    public function testGetRevenueCsv_GivesTheTableWithAByteOrderMarkAndTheTotals(): void
    {
        $this->sell();
        $this->reader();

        $rows = $this->csvRows($this->fetch(self::REVENUE . '/csv?group=customer&view=details'));

        $this->assertSame(lang('Accounting_operational_reports.revenue_title'), $rows[0][0]);
        $this->assertContains(lang('Accounting_operational_reports.grand_total'), array_column($rows, 0));
        $total = array_values(array_filter($rows, static fn (array $row): bool => ($row[0] ?? '') === lang('Accounting_operational_reports.grand_total')))[0];

        $this->assertContains('287.5000', $total);
    }

    public function testGetRevenueCsv_ShowsATextThatASpreadsheetWouldReadAsAFormulaAsText(): void
    {
        $this->invoiceOf('50', $this->anotherCustomer('=HYPERLINK("http://x")', 'Evil'));
        $this->reader();

        $response = $this->fetch(self::REVENUE . '/csv?group=customer&view=details');

        $this->assertStringContainsString("'=HYPERLINK", $response->response()->getBody());
        $this->assertStringNotContainsString(',=HYPERLINK', $response->response()->getBody());
        $this->assertStringNotContainsString("\n=HYPERLINK", $response->response()->getBody());
    }

    public function testGetRevenueCsv_RefusesABadDayInWords(): void
    {
        $this->reader();

        $response = $this->fetch(self::REVENUE . '/csv?from=nonsense');

        $response->assertStatus(422);
        $response->assertSee(lang('Accounting.error_invalid_date'));
    }

    public function testGetRevenueCsv_GivesTheSameFigureAsTheScreen(): void
    {
        $this->sell();
        $this->reader();

        $screen = $this->grandTotal($this->fetch(self::REVENUE . '?show=1&group=user&view=summary'), 'total');
        $rows   = $this->csvRows($this->fetch(self::REVENUE . '/csv?group=user&view=summary'));
        $total  = array_values(array_filter($rows, static fn (array $row): bool => ($row[0] ?? '') === lang('Accounting_operational_reports.grand_total')))[0];

        $this->assertSame($screen, $this->shown(end($total)));
    }

    public function testGetClients_ShowsTheSalesOfEachCustomerUnderThePermissionOfCustomers(): void
    {
        $this->sell();
        $this->reader(['accounting_reports', 'reports_sales']);

        $this->fetch(self::CLIENTS . '?show=1')->assertRedirect();
        $this->fetch(self::CLIENTS . '/csv')->assertRedirect();

        $this->reader(['accounting_reports', 'reports_customers']);

        $response = $this->fetch(self::CLIENTS . '?show=1&group=document&view=details');

        $this->assertSame($this->shown('287.50'), $this->grandTotal($response, 'total'));
        $this->assertCount(3, $this->column($response, 'total'));
    }

    public function testGetClients_FiltersByTheTypeOfDocument(): void
    {
        $this->sell();
        $this->reader();

        $invoices = $this->fetch(self::CLIENTS . '?show=1&group=document&type=invoice');
        $notes    = $this->fetch(self::CLIENTS . '?show=1&group=document&type=credit_note');

        $this->assertSame($this->shown('345'), $this->grandTotal($invoices, 'total'));
        $this->assertSame($this->shown('-57.50'), $this->grandTotal($notes, 'total'));
    }

    public function testGetClientsCsv_GivesTheDocumentsWithTheirColumns(): void
    {
        $this->sell();
        $this->reader();

        $this->fetch(self::CLIENTS . '/csv?from=nonsense')->assertStatus(422);

        $rows = $this->csvRows($this->fetch(self::CLIENTS . '/csv?group=document'));

        $this->assertSame(lang('Accounting_operational_reports.clients_title'), $rows[0][0]);
        $this->assertContains(lang('Accounting_operational_reports.col_shipping'), array_merge(...$rows));
        $this->assertContains('-57.5000', array_merge(...$rows));
    }
}
