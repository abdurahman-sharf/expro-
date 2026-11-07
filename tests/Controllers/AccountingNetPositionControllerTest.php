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
 * The screens of the net position of the invoices and of the invoices with their returns: the permission of
 * the sales reports, the credit note with no invoice that can be hidden, the rows under each invoice, the
 * tie to the ledger of the customers, and the file.
 */
class AccountingNetPositionControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use OperationalReportFixtureTrait;
    use SaleAccountingFixtureTrait;

    private const NET   = '/accounting_reports/net_position';
    private const CROSS = '/accounting_reports/invoice_returns';

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
        $other    = $this->anotherCustomer();
        $this->invoiceOf('115', $this->customerId, [$this->payment(lang('Sales.cash'), '90')]);
        $returned = $this->invoiceOf('172.50', $other, [$this->payment(lang('Sales.cash'), '156.25')]);

        $this->refundInvoiceOf($returned, '86.25', $other);
        $this->save([$this->line($this->item('0', false), 0, '-1', '253')], [], [], SALE_TYPE_RETURN, $this->anotherCustomer('Third', 'Buyer'), true, null, COMPLETED, NEW_ENTRY, null, returnOverrideReason: 'test');
    }

    public function testGetIndex_ShowsNoTableUntilRunAndThenTheNetAfterReturns(): void
    {
        $this->sell();
        $this->reader();

        $this->fetch(self::NET)->assertDontSee('id="report_table"');

        $response = $this->fetch(self::NET . '?show=1');

        $this->assertSame($this->shown('-51.75'), $this->grandTotal($response, 'net'));
        $this->assertSame($this->shown('34.50'), $this->grandTotal($response, 'total'));
        $this->assertSame($this->shown('-86.25'), $this->grandTotal($response, 'returns'));
    }

    public function testGetIndex_HidesTheCreditNoteWithNoInvoiceOnRequest(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::NET . '?show=1&hide_credit_notes=1');

        $this->assertSame($this->shown('201.25'), $this->grandTotal($response, 'net'));
        $this->assertCount(2, $this->column($response, 'number'));
    }

    public function testGetIndex_NestsThePaymentsAndTheReturnsUnderAnInvoiceInDetail(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::NET . '?show=1&view=details');

        $this->assertGreaterThan(5, count($this->column($response, 'number')));
        $response->assertSee(lang('Accounting_operational_reports.row_credit_applied'));
    }

    public function testGetIndex_NestsOnlyThePaymentsThatStandAndAddsThemUpToWhatTheInvoiceShowsPaid(): void
    {
        $this->invoiceOf('100', $this->customerId, [$this->payment(lang('Sales.cash'), '40'), $this->payment(lang('Sales.due'), '60')]);
        $this->reader();

        $response = $this->fetch(self::NET . '?show=1&view=details');

        $this->assertSame([lang('Accounting_operational_reports.kind_invoice'), lang('Accounting_operational_reports.row_payment')], $this->column($response, 'kind'));
        $this->assertSame([$this->shown('40'), $this->shown('40')], $this->column($response, 'paid'));
    }

    public function testGetIndex_RunsNothingForADayThatIsNotOne(): void
    {
        $this->sell();
        $this->reader();

        foreach ([self::NET, self::CROSS] as $screen) {
            $response = $this->fetch($screen . '?show=1&from=31%2F02%2F2026');

            $response->assertSee('id="report_error"');
            $response->assertDontSee('id="report_table"');
        }
    }

    public function testGetIndex_CarriesEveryFilterToTheLinkOfItsFile(): void
    {
        $this->reader();

        $this->assertFileCarries(self::NET, ['view', 'preset', 'mode', 'from', 'to', 'date', 'hide_credit_notes', 'status', 'customer', 'show']);
        $this->assertFileCarries(self::CROSS, ['group', 'view', 'order', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'type', 'status', 'show']);
    }

    public function testGetIndex_GivesTheGrandTotalOneRowHeaderOverTheColumnsOfTextAndNoneForTheStatus(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::NET . '?show=1');
        $page     = $this->pageOf($response);

        $response->assertDontSee('<th scope="row"></th>');
        $this->assertSame('4', $page->query("//table[@id='report_table']/tfoot//th[@scope='row']/@colspan")->item(0)?->nodeValue);
        $this->assertSame(1, $page->query("//table[@id='report_table']/tfoot//th")->length);
    }

    public function testGetIndex_TiesTheNetAndTheSettledAmountsToTheLedger(): void
    {
        $this->sell();
        $this->reader();

        $ties = $this->tieRows($this->fetch(self::NET . '?show=1'));

        $this->assertCount(2, $ties);

        foreach ($ties as $row) {
            $this->assertStringContainsString(lang('Accounting_operational_reports.ties'), $row);
        }
    }

    public function testGetIndex_RedirectsWhoeverLacksThePermissionOfTheSalesReportsOrTheModule(): void
    {
        $this->reader(['accounting_reports', 'reports_customers']);

        $this->fetch(self::NET . '?show=1')->assertRedirect();
        $this->fetch(self::NET . '/csv')->assertRedirect();
        $this->fetch(self::CROSS . '?show=1')->assertRedirect();
        $this->fetch(self::CROSS . '/csv')->assertRedirect();

        $this->reader(['reports_sales']);

        $this->fetch(self::NET)->assertRedirect();

        $this->reader(['accounting_reports', 'reports_sales']);

        $this->fetch(self::NET . '?show=1')->assertStatus(200);
        $this->fetch(self::CROSS . '?show=1')->assertStatus(200);
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->reader();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->fetch(self::NET);
    }

    public function testGetIndex_AddsUpOnlyTheBranchesOfTheReader(): void
    {
        $second = $this->createBranch('NB2', 'Second net branch');
        $this->invoiceInBranch(1, '100', [], $this->customerId);
        $this->invoiceInBranch($second, '70', [], $this->customerId);

        $this->reader($this->everyReportPermission(), [1]);

        $this->assertSame($this->shown('100'), $this->grandTotal($this->fetch(self::NET . '?show=1'), 'total'));

        $this->reader($this->everyReportPermission(), null);

        $this->assertSame($this->shown('170'), $this->grandTotal($this->fetch(self::NET . '?show=1'), 'total'));
    }

    public function testGetCross_ListsOnlyTheInvoicesThatHaveAReturn(): void
    {
        $this->sell();
        $this->reader();

        $response = $this->fetch(self::CROSS . '?show=1&view=details');

        $this->assertSame($this->shown('172.50'), $this->grandTotal($response, 'total'));
        $this->assertSame($this->shown('86.25'), $this->grandTotal($response, 'returns'));
        $this->assertCount(2, $this->column($response, 'number'));
        $this->assertCount(1, $this->tieRows($response));
    }

    public function testGetCrossCsvAndGetCsv_GiveTheRowsAndRefuseABadDay(): void
    {
        $this->sell();
        $this->reader();

        $this->assertContains('-51.7500', array_merge(...$this->csvRows($this->fetch(self::NET . '/csv'))));
        $this->assertContains('86.2500', array_merge(...$this->csvRows($this->fetch(self::CROSS . '/csv'))));
        $this->fetch(self::NET . '/csv?to=nonsense')->assertStatus(422);
        $this->fetch(self::CROSS . '/csv?to=nonsense')->assertStatus(422);
    }
}
