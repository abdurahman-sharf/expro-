<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_taxes;
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
 * The tax collected is that of the sales of the branches the employee works in.
 */
class Summary_taxesTest extends CIUnitTestCase
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

    /**
     * @return array<string, string> The tax of each name.
     */
    private function taxedBy(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $rows = (new Summary_taxes())->getData($this->saleInputs());

        $taxed = array_filter($rows, static fn (array $row): bool => (string) $row['name'] !== '');

        return array_column(array_map(fn (array $row): array => ['name' => $row['name'], 'tax' => $this->amount($row['tax'])], $taxed), 'tax', 'name');
    }

    private function summedBy(string $scope): string
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        return $this->amount((new Summary_taxes())->getSummaryData($this->saleInputs())['tax']);
    }

    public function testGetData_ShowsOnlyTheTaxesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame(['VAT-A' => '27.00'], $this->taxedBy('a'));
        $this->assertSame(['VAT-B' => '6.00'], $this->taxedBy('b'));
    }

    public function testGetData_ShowsEveryBranchToAnEmployeeOfTheTwoAndOfEveryBranchAndToTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame(['VAT-A' => '27.00', 'VAT-B' => '6.00'], $this->taxedBy($scope), $scope);
        }
    }

    public function testGetSummaryData_AddsUpOnlyTheTaxOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame('27.00', $this->summedBy('a'));
        $this->assertSame('6.00', $this->summedBy('b'));
        $this->assertSame('33.00', $this->summedBy('all'));
        $this->assertSame('0.00', $this->summedBy('none'));
    }
}
