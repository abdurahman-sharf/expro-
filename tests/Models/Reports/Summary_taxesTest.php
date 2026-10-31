<?php

namespace Tests\Models\Reports;

use App\Models\Appconfig;
use App\Models\Reports\Summary_taxes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportBranchFixtureTrait;
use Tests\Support\ReportSaleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The tax collected is that of the sales of the branches the employee works in, and the amounts the
 * report gives with it are those left after the discount given on the whole of each sale.
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
    use ReportSaleFixtureTrait;

    private const MONTH = '2005-06-';

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

        $this->dropReportTables();
        $this->removeReportSales(self::MONTH);
    }

    protected function tearDown(): void
    {
        if ($this->buyingStarted) {
            $this->forgetTheReportBusiness();
            $this->stopBuying();
            $this->removeTheReportItems();
        }

        $this->dropReportTables();
        $this->removeReportSales(self::MONTH);

        parent::tearDown();
    }

    private function openTheBooks(): void
    {
        $this->startBuying();
        $this->businessInTwoBranches();
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
        $this->openTheBooks();

        $this->assertSame(['VAT-A' => '27.00'], $this->taxedBy('a'));
        $this->assertSame(['VAT-B' => '6.00'], $this->taxedBy('b'));
    }

    public function testGetData_ShowsEveryBranchToAnEmployeeOfTheTwoAndOfEveryBranchAndToTheSystem(): void
    {
        $this->openTheBooks();

        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame(['VAT-A' => '27.00', 'VAT-B' => '6.00'], $this->taxedBy($scope), $scope);
        }
    }

    public function testGetSummaryData_AddsUpOnlyTheTaxOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();

        $this->assertSame('27.00', $this->summedBy('a'));
        $this->assertSame('6.00', $this->summedBy('b'));
        $this->assertSame('33.00', $this->summedBy('all'));
        $this->assertSame('0.00', $this->summedBy('none'));
    }

    public function testGetData_GivesTheSubtotalTaxAndTotalOfASaleAfterTheDiscountOnTheWholeOfIt(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '07', [$this->reportTax($itemId, '13.50')]);

        $rows = (new Summary_taxes())->getData(['start_date' => self::MONTH . '07', 'end_date' => self::MONTH . '07']);

        $this->assertCount(1, $rows);
        $this->assertSame('90.00', bcadd((string) $rows[0]['subtotal'], '0', 2));
        $this->assertSame('13.50', bcadd((string) $rows[0]['tax'], '0', 2));
        $this->assertSame('103.50', bcadd((string) $rows[0]['total'], '0', 2));
    }

    public function testGetData_GivesASaleWithoutADiscountWhatItAlwaysGave(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', null, self::MONTH . '08', [$this->reportTax($itemId, '15.00')]);

        $rows = (new Summary_taxes())->getData(['start_date' => self::MONTH . '08', 'end_date' => self::MONTH . '08']);

        $this->assertSame('100.00', bcadd((string) $rows[0]['subtotal'], '0', 2));
        $this->assertSame('115.00', bcadd((string) $rows[0]['total'], '0', 2));
    }

    public function testGetSummaryData_AddsUpTheTaxesOfTheDay(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '09', [$this->reportTax($itemId, '13.50')]);

        $summary = (new Summary_taxes())->getSummaryData(['start_date' => self::MONTH . '09', 'end_date' => self::MONTH . '09']);

        $this->assertSame('90.00', bcadd((string) $summary['subtotal'], '0', 2));
        $this->assertSame('103.50', bcadd((string) $summary['total'], '0', 2));
    }

    public function testGetData_GivesEachTaxOfAMixedLineInItsOwnModeWhateverTheDefaultModeIs(): void
    {
        foreach (['0', '1'] as $default) {
            model(Appconfig::class)->save(['tax_included' => $default]);
            $this->removeReportSales(self::MONTH);
            $this->dropReportTables();

            $itemId = $this->createTestItem(HAS_NO_STOCK);

            $this->reportSale($itemId, '1', '100', null, self::MONTH . '09', [$this->reportTax($itemId, '13.0435', 0, 'Inside'), $this->reportTax($itemId, '4.35', 1, 'Added', '5.0000')]);

            $rows = (new Summary_taxes())->getData(['start_date' => self::MONTH . '09', 'end_date' => self::MONTH . '09']);
            $by   = array_column($rows, null, 'name');

            $this->assertSame(['86.96', '13.04', '100.00'], [bcadd((string) $by['Inside']['subtotal'], '0', 2), bcadd((string) $by['Inside']['tax'], '0', 2), bcadd((string) $by['Inside']['total'], '0', 2)], 'the tax inside the price, default ' . $default);
            $this->assertSame(['100.00', '4.35', '104.35'], [bcadd((string) $by['Added']['subtotal'], '0', 2), bcadd((string) $by['Added']['tax'], '0', 2), bcadd((string) $by['Added']['total'], '0', 2)], 'the tax added to the price, default ' . $default);
        }

        model(Appconfig::class)->save(['tax_included' => '0']);
    }
}
