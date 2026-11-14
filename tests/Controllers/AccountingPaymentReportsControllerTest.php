<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\TreasuryService;
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
 * The screens of the payments of customers and of the advances: the permission of the payments reports, the
 * method and the treasury as two groupings of one total tied to the treasuries, the branches of the reader,
 * and the file.
 */
class AccountingPaymentReportsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use OperationalReportFixtureTrait;
    use SaleAccountingFixtureTrait;

    private const PAYMENTS = '/accounting_reports/payments';
    private const ADVANCES = '/accounting_reports/advance_payments';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $drawer;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->drawer = (new TreasuryService())->create(['name' => 'Report drawer', 'kind' => 'cash']);
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function pay(): void
    {
        $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [[...$this->payment(lang('Sales.cash'), '60'), 'treasury_id' => $this->drawer]], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($this->item('0', false), 0, '1', '40')], [], [[...$this->payment(lang('Sales.check'), '40'), 'treasury_id' => $this->drawer, 'reference_code' => 'CHQ-7']], SALE_TYPE_INVOICE, $this->anotherCustomer());
    }

    public function testGetIndex_FiltersByCustomerInASearchBoxAboveThePickerThreshold(): void
    {
        $this->assertCustomerFilterSearches(self::PAYMENTS);
    }

    public function testGetAdvances_FiltersByCustomerInASearchBoxAboveThePickerThreshold(): void
    {
        $this->assertCustomerFilterSearches(self::ADVANCES);
    }

    public function testGetIndex_ShowsNoTableUntilTheReportIsRun(): void
    {
        $this->reader();

        $response = $this->fetch(self::PAYMENTS);

        $response->assertStatus(200);
        $response->assertSee('id="report_not_run"');
        $response->assertDontSee('id="report_table"');
    }

    public function testGetIndex_GivesTheSameTotalByMethodAndByTreasury(): void
    {
        $this->pay();
        $this->reader();

        $byMethod   = $this->fetch(self::PAYMENTS . '?show=1&group=method');
        $byTreasury = $this->fetch(self::PAYMENTS . '?show=1&group=treasury');

        $this->assertSame($this->shown('100'), $this->grandTotal($byMethod, 'amount'));
        $this->assertSame($this->shown('100'), $this->grandTotal($byTreasury, 'amount'));
        $this->assertSame(['Report drawer'], $this->column($byTreasury, 'treasury'));
        $this->assertCount(2, $this->column($byMethod, 'method'));
    }

    public function testGetIndex_TiesTheTotalToTheAccountsTheMoneyWentInto(): void
    {
        $this->pay();
        $this->reader();

        $ties = $this->tieRows($this->fetch(self::PAYMENTS . '?show=1&group=customer'));

        $this->assertCount(1, $ties);
        $this->assertStringContainsString(lang('Accounting_operational_reports.ties'), $ties[0]);
        $this->assertSame($this->shown('100'), $this->shown((new TreasuryService())->position($this->drawer)->balance));
    }

    public function testGetIndex_RedirectsWhoeverLacksThePermissionOfThePaymentsReportsOrTheModule(): void
    {
        $this->reader(['accounting_reports', 'reports_sales', 'reports_customers']);

        $this->fetch(self::PAYMENTS . '?show=1')->assertRedirect();
        $this->fetch(self::PAYMENTS . '/csv')->assertRedirect();
        $this->fetch(self::ADVANCES)->assertRedirect();
        $this->fetch(self::ADVANCES . '/csv')->assertRedirect();

        $this->reader(['reports_payments']);

        $this->fetch(self::PAYMENTS)->assertRedirect();

        $this->reader(['accounting_reports', 'reports_employees']);

        $this->fetch(self::PAYMENTS . '?show=1&group=user')->assertRedirect();
        $this->fetch(self::PAYMENTS . '/csv?group=collector')->assertRedirect();

        $this->reader(['accounting_reports', 'reports_payments']);

        $this->fetch(self::PAYMENTS . '?show=1')->assertStatus(200);
        $this->fetch(self::ADVANCES . '?show=1')->assertStatus(200);
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->reader();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->fetch(self::PAYMENTS);
    }

    public function testGetIndex_AddsUpOnlyThePaymentsOfTheBranchesOfTheReader(): void
    {
        $second = $this->createBranch('PB2', 'Second payment branch');
        $this->invoiceInBranch(1, '100', [$this->payment(lang('Sales.cash'), '100')], $this->customerId);
        $this->invoiceInBranch($second, '70', [$this->payment(lang('Sales.cash'), '70')], $this->customerId);

        $this->reader($this->everyReportPermission(), [1]);

        $this->assertSame($this->shown('100'), $this->grandTotal($this->fetch(self::PAYMENTS . '?show=1&group=customer'), 'amount'));

        $this->reader($this->everyReportPermission(), null);

        $this->assertSame($this->shown('170'), $this->grandTotal($this->fetch(self::PAYMENTS . '?show=1&group=customer'), 'amount'));
    }

    public function testGetIndex_ReadsEveryDayBeforeADayForAGroupingByPeriodAsForOneByCustomer(): void
    {
        $this->invoiceOf('10', $this->customerId, [$this->payment(lang('Sales.cash'), '10')]);
        $this->invoiceOf('20', $this->customerId, [$this->payment(lang('Sales.cash'), '20')]);

        $old = (int) $this->accountingDb()->table('sales_payments')->selectMin('payment_id', 'first')->get()->getRow()->first;

        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $old])->update(['journal_date' => $this->daysAgo(400)]);
        $this->reader();

        $before = '&mode=before&date=' . date('Y-m-d', strtotime('+1 day'));

        foreach (['day', 'week', 'month', 'year', 'customer', 'method', 'treasury'] as $group) {
            $this->assertSame($this->shown('30'), $this->grandTotal($this->fetch(self::PAYMENTS . '?show=1&group=' . $group . $before), 'amount'), $group);
        }

        $this->assertSame($this->shown('20'), $this->grandTotal($this->fetch(self::PAYMENTS . '?show=1&group=month'), 'amount'));
    }

    public function testGetIndex_RedirectsSomeoneWithoutThePermissionOfEmployeesFromWhatShowsTheTakingsOfOne(): void
    {
        $this->pay();
        $this->reader(['accounting_reports', 'reports_payments']);

        $this->fetch(self::PAYMENTS . '?show=1&group=user')->assertRedirect();
        $this->fetch(self::PAYMENTS . '?show=1&group=collector')->assertRedirect();
        $this->fetch(self::PAYMENTS . '/csv?group=collector')->assertRedirect();
        $this->fetch(self::PAYMENTS . '?show=1&group=customer&user=' . $this->employeeId)->assertRedirect();
        $this->fetch(self::PAYMENTS . '?show=1&group=customer&collector=' . $this->employeeId)->assertRedirect();
        $this->fetch(self::PAYMENTS . '/csv?group=customer&collector=' . $this->employeeId)->assertRedirect();
        $this->fetch(self::ADVANCES . '?show=1&user=' . $this->employeeId)->assertRedirect();
        $this->fetch(self::ADVANCES . '/csv?user=' . $this->employeeId)->assertRedirect();

        $response = $this->fetch(self::PAYMENTS . '?show=1&group=customer');

        $response->assertStatus(200);
        $response->assertDontSee('value="collector"');
        $response->assertDontSee('value="user"');
        $response->assertDontSee('id="f_user"');
        $response->assertDontSee('id="f_collector"');
        $response->assertSee('id="f_treasury"');
        $this->fetch(self::ADVANCES)->assertDontSee('id="f_user"');

        $this->reader(['accounting_reports', 'reports_payments', 'reports_employees']);

        $this->assertSame($this->shown('100'), $this->grandTotal($this->fetch(self::PAYMENTS . '?show=1&group=collector'), 'amount'));
        $this->assertSame($this->shown('100'), $this->grandTotal($this->fetch(self::PAYMENTS . '?show=1&group=user&collector=' . $this->employeeId), 'amount'));
        $this->fetch(self::PAYMENTS)->assertSee('id="f_collector"');
        $this->fetch(self::ADVANCES)->assertSee('id="f_user"');
    }

    public function testGetIndex_CarriesEveryFilterToTheLinkOfItsFile(): void
    {
        $this->reader();

        $this->assertFileCarries(self::PAYMENTS, ['group', 'view', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'user', 'collector', 'method', 'treasury', 'show']);
        $this->assertFileCarries(self::ADVANCES, ['preset', 'mode', 'from', 'to', 'date', 'customer', 'user', 'method', 'state', 'show']);
    }

    public function testGetIndex_RunsNothingForADayThatIsNotOne(): void
    {
        $this->pay();
        $this->reader();

        $response = $this->fetch(self::PAYMENTS . '?show=1&from=31%2F02%2F2026');

        $response->assertSee('id="report_error"');
        $response->assertDontSee('id="report_table"');
        $this->fetch(self::ADVANCES . '?show=1&from=31%2F02%2F2026')->assertDontSee('id="report_table"');
    }

    public function testGetCsv_GivesThePaymentsWithTheirReferenceAndTheCheck(): void
    {
        $this->pay();
        $this->reader();

        $this->fetch(self::PAYMENTS . '/csv?from=nonsense')->assertStatus(422);

        $response = $this->fetch(self::PAYMENTS . '/csv?group=method&view=details');
        $flat     = array_merge(...$this->csvRows($response));

        $this->assertContains('CHQ-7', $flat);
        $this->assertContains('100.0000', $flat);
        $this->assertContains(lang('Accounting_operational_reports.ties'), $flat);
    }

    public function testGetAdvances_ListsTheAdvancesWithWhatIsLeftOfEach(): void
    {
        $teller = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE]);

        (new CustomerAdvances())->receive($this->customerId, '200', date('Y-m-d'), 'Cash', null, 'REF-1', '', $teller);
        $this->reader();

        $response = $this->fetch(self::ADVANCES . '?show=1');

        $this->assertSame($this->shown('200'), $this->grandTotal($response, 'unsettled'));
        $this->assertCount(1, $this->column($response, 'number'));
        $response->assertSee(lang('Accounting_operational_reports.note_advances'));
    }

    public function testGetAdvancesCsv_GivesTheAdvances(): void
    {
        $teller = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE]);

        (new CustomerAdvances())->receive($this->customerId, '200', date('Y-m-d'), 'Cash', null, '', '', $teller);
        $this->reader();

        $this->assertContains('200.0000', array_merge(...$this->csvRows($this->fetch(self::ADVANCES . '/csv'))));
        $this->fetch(self::ADVANCES . '/csv?from=nonsense')->assertStatus(422);
    }
}
