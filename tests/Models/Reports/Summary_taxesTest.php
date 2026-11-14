<?php

namespace Tests\Models\Reports;

use App\Libraries\Accounting\SaleAmounts;
use App\Models\Appconfig;
use App\Models\Reports\Summary_taxes;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\RegisterFixtureTrait;
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
    use RegisterFixtureTrait;
    use ReportBranchFixtureTrait;
    use ReportSaleFixtureTrait;

    private const MONTH = '2005-06-';
    private const MEASURED_DAY = '2005-07-15';

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
        try {
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
        } finally {
            model(Appconfig::class)->save(['tax_included' => '0']);
        }
    }

    /**
     * Rings a seeded batch of invoices of three lines up on the real register (rates, prices, quantities and
     * discounts of every kind, one invoice in three with a discount on the whole of it), saves them with the books
     * on, and sets what the legacy summary of taxes gives against what the journals took.
     *
     * @param array<string, string> $settings
     *
     * @return array{lines: int, legacy: string, summed: string, journal: string, stored: string, perLine: string}
     */
    private function measuredTaxSummary(array $settings, int $invoices): array
    {
        $this->startSelling();
        $this->removeReportSales(self::MEASURED_DAY);
        $this->dropReportTables();

        mt_srand(95);

        $items = [];

        foreach (['15', '14', '5', '7.5', '12.5'] as $rate) {
            $items[] = $this->taxedItem('0', false, $rate);
        }

        $quantities = ['1', '2', '3', '0.5', '1.5', '2.25', '7', '0.333'];
        $discounts  = ['0', '0', '5', '10', '12.5'];
        $saleIds    = [];

        for ($number = 0; $number < $invoices; $number++) {
            $lines = [];

            for ($line = 0; $line < 3; $line++) {
                $lines[] = [
                    'item'     => $items[mt_rand(0, count($items) - 1)],
                    'quantity' => $quantities[mt_rand(0, count($quantities) - 1)],
                    'price'    => number_format(mt_rand(100, 99999) / 100, 2, '.', ''),
                    'discount' => $discounts[mt_rand(0, count($discounts) - 1)],
                ];
            }

            $sale = $this->ringUp($lines, $number % 3 === 0 ? ['type' => PERCENT, 'value' => '5'] : null, null, true, $settings);

            $this->assertGreaterThan(0, $sale['sale']);

            $saleIds[] = $sale['sale'];
        }

        $db = $this->accountingDb();

        $db->table('sales')->whereIn('sale_id', $saleIds)->update(['sale_time' => self::MEASURED_DAY . ' 10:00:00']);

        $inputs  = ['start_date' => self::MEASURED_DAY, 'end_date' => self::MEASURED_DAY];
        $rows    = (new Summary_taxes())->getData($inputs);
        $legacy  = '0';
        $summed  = (new Summary_taxes())->getSummaryData($inputs)['tax'];
        $journal = $db->query(
            'SELECT COALESCE(SUM(l.credit - l.debit), 0) AS tax FROM ' . $db->prefixTable('accounting_journal_lines') . ' l JOIN ' . $db->prefixTable('accounting_journals') . ' j ON j.journal_id = l.journal_id WHERE j.status IN (?, ?) AND l.account_id = ?',
            ['posted', 'reversed', $this->accountId('tax_output')],
        )->getRow()->tax;

        foreach ($rows as $row) {
            $legacy = bcadd($legacy, (string) $row['tax'], 4);
        }

        $stored  = '0';
        $perLine = '0';
        $taxRows = $db->table('sales_items_taxes')->select('item_tax_amount')->whereIn('sale_id', $saleIds)->get()->getResultArray();

        foreach ($taxRows as $taxRow) {
            $stored  = bcadd($stored, (string) $taxRow['item_tax_amount'], 6);
            $perLine = bcadd($perLine, SaleAmounts::round((string) $taxRow['item_tax_amount'], 2), 6);
        }

        $this->removeReportSales(self::MEASURED_DAY);

        return ['lines' => count($taxRows), 'legacy' => $legacy, 'summed' => bcadd((string) $summed, '0', 4), 'journal' => bcadd((string) $journal, '0', 4), 'stored' => bcadd($stored, '0', 4), 'perLine' => bcadd($perLine, '0', 4)];
    }

    public function testGetData_GivesTheTaxOfTheJournalsToTheCentWhileEveryLineTaxIsKeptToTheCurrencyDecimals(): void
    {
        $measured = $this->measuredTaxSummary(['tax_decimals' => '2', 'tax_included' => '0'], 60);

        $this->assertGreaterThan(100, $measured['lines']);
        $this->assertSame($measured['journal'], $measured['stored'], 'the journals take the tax of the lines as it is stored');
        $this->assertSame($measured['journal'], $measured['legacy']);
        $this->assertSame($measured['journal'], $measured['summed']);
    }

    public function testGetData_DiffersFromTheJournalsByWhatItRoundsOffTheTaxOfEveryLineWhenTheTaxIsKeptToFourDecimals(): void
    {
        $measured = $this->measuredTaxSummary(['tax_decimals' => '4', 'tax_included' => '0'], 60);

        $difference = bcsub($measured['legacy'], $measured['journal'], 4);

        $this->assertSame($measured['journal'], $measured['stored']);
        $this->assertSame($measured['perLine'], $measured['legacy'], 'the legacy summary adds the tax of each line rounded to the currency decimals');
        $this->assertNotSame('0.0000', $difference, 'with a tax of four decimals the rounding of every line is not nothing');
        $this->assertLessThanOrEqual(0, bccomp(SaleAmounts::absolute($difference), bcmul('0.005', (string) $measured['lines'], 4), 4), 'never more than half a cent a line');
    }

    public function testGetData_DiffersFromTheJournalsByWhatItRoundsOffTheTaxInsideThePriceOfEveryLine(): void
    {
        $measured = $this->measuredTaxSummary(['tax_decimals' => '2', 'tax_included' => '1'], 60);

        $difference = bcsub($measured['legacy'], $measured['journal'], 4);

        $this->assertSame($measured['journal'], $measured['stored']);
        $this->assertSame($measured['perLine'], $measured['legacy']);
        $this->assertLessThanOrEqual(0, bccomp(SaleAmounts::absolute($difference), bcmul('0.005', (string) $measured['lines'], 4), 4), 'never more than half a cent a line');
    }
}
