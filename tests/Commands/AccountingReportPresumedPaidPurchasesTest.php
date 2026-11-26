<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class AccountingReportPresumedPaidPurchasesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

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

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    private function runReport(string $arguments = ''): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:report_presumed_paid_purchases ' . $arguments);

        return $this->getStreamFilterBuffer();
    }

    public function testRun_ListsThePurchasesPaidInFullAtTheMomentTheyWereSavedAndChangesNothing(): void
    {
        $this->purchaseOf('300.00', null, lang('Sales.cash'));
        $this->purchaseOf('120.00');
        $journals = $this->countRows('accounting_journals');

        $output = $this->runReport();

        $this->assertStringContainsString('300.00', $output);
        $this->assertStringContainsString('Gulf Supplies', $output);
        $this->assertStringNotContainsString('120.00', $output);
        $this->assertStringContainsString('1 purchase(s) and return(s) are held as paid or refunded in full at the moment they were saved. Nothing was changed.', $output);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testRun_PrintsTheListAsCsvWhenAsked(): void
    {
        $receivingId = $this->purchaseOf('300.00', null, lang('Sales.cash'));

        $output = $this->runReport('--csv');

        $this->assertStringContainsString('receiving_id,kind,number,date,supplier,amount,treasury,payment_type', $output);
        $this->assertMatchesRegularExpression('/^' . $receivingId . ',purchase,[^,]*,\d{4}-\d{2}-\d{2},"?Gulf Supplies"?,300\.00,/m', $output);
    }

    public function testRun_AcceptsBooksThatHoldNoSuchPurchase(): void
    {
        $this->assertStringContainsString('No purchase or return is held as paid or refunded in full at the moment it was saved.', $this->runReport());
    }
}
