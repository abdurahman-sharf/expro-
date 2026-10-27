<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_sales_taxes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportBranchFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The sales tax by authority and category adds up the sales of the branches the employee works in.
 */
class Summary_sales_taxesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ReportBranchFixtureTrait;

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
        $this->businessInTwoBranches();
    }

    protected function tearDown(): void
    {
        $this->forgetTheReportBusiness();
        $this->stopBuying();
        $this->removeTheReportItems();

        parent::tearDown();
    }

    private function taxedBy(string $scope): string
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        return $this->amount(array_sum(array_column((new Summary_sales_taxes())->getData($this->saleInputs()), 'tax')));
    }

    private function summedBy(string $scope): string
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        return $this->amount((new Summary_sales_taxes())->getSummaryData($this->saleInputs())['tax']);
    }

    public function testGetData_AddsUpOnlyTheTaxOfTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame('27.00', $this->taxedBy('a'));
        $this->assertSame('6.00', $this->taxedBy('b'));
        $this->assertSame('33.00', $this->taxedBy('both'));
        $this->assertSame('33.00', $this->taxedBy('all'));
        $this->assertSame('33.00', $this->taxedBy('system'));
        $this->assertSame('0.00', $this->taxedBy('none'));
    }

    public function testGetSummaryData_AddsUpOnlyTheTaxOfTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame('27.00', $this->summedBy('a'));
        $this->assertSame('6.00', $this->summedBy('b'));
        $this->assertSame('33.00', $this->summedBy('all'));
        $this->assertSame('0.00', $this->summedBy('none'));
    }
}
