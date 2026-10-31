<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TaxAccountFigures;
use App\Libraries\Accounting\TaxBasis;
use App\Libraries\Accounting\TaxReportResult;
use App\Libraries\Accounting\TaxSection;
use App\Libraries\Accounting\TaxSectionFigures;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use LogicException;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class TaxReportResultTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    /**
     * @param array<string, array{string, string}> $parts The base and the tax of the parts, by part.
     * @param list<TaxAccountFigures>              $accounts
     */
    private function reportOf(array $parts, array $accounts = [], ?string $from = null, ?string $start = null, bool $output = true, bool $input = true, int $documents = 1): TaxReportResult
    {
        $sections = [];

        foreach (TaxSection::cases() as $section) {
            [$base, $tax] = $parts[$section->value] ?? ['0.0000', '0.0000'];

            $sections[] = new TaxSectionFigures($section, $base, $tax, $documents, [], 0, '0.0000', '0.0000');
        }

        return new TaxReportResult($sections, $accounts, ReportScope::resolve(null, null), $from, '2026-10-31', TaxBasis::Accrual, $start, 0, 0, $output, $input);
    }

    private function figuresOf(string $movement): TaxAccountFigures
    {
        return new TaxAccountFigures(1, '215001', 'Tax', ['output'], $movement, $movement, 0);
    }

    public function testOutput_IsTheTaxOfSalesLessTheTaxOfTheSalesTakenBack(): void
    {
        $report = $this->reportOf(['sales' => ['2210.0000', '331.5000'], 'sales_returns' => ['-220.0000', '-33.0000'], 'purchases' => ['-100.0000', '-15.0000']]);

        $this->assertSame('298.5000', $report->output());
        $this->assertSame('1990.0000', $report->outputBase());
    }

    public function testOutput_AddsTheTaxOfReceiptVouchersToTheTaxOfSalesAndTakesThemIntoWhatIsPayable(): void
    {
        $report = $this->reportOf(['sales' => ['100.0000', '15.0000'], 'revenues' => ['40.0000', '6.0000'], 'expenses' => ['-20.0000', '-3.0000']]);

        $this->assertSame('21.0000', $report->output());
        $this->assertSame('140.0000', $report->outputBase());
        $this->assertSame('3.0000', $report->input());
        $this->assertSame('18.0000', $report->net());
    }

    public function testInput_IsTheTaxPaidOnPurchasesAndExpensesLessWhatWentBack(): void
    {
        $report = $this->reportOf(['sales' => ['100.0000', '15.0000'], 'purchases' => ['-1000.0000', '-150.0000'], 'purchase_returns' => ['200.0000', '30.0000'], 'expenses' => ['-200.0000', '-30.0000']]);

        $this->assertSame('150.0000', $report->input());
        $this->assertSame('1000.0000', $report->inputBase());
    }

    public function testInput_IsBelowNothingWhenMoreWentBackThanWasPaid(): void
    {
        $report = $this->reportOf(['sales' => ['100.0000', '15.0000'], 'purchase_returns' => ['200.0000', '30.0000']]);

        $this->assertSame('-30.0000', $report->input());
        $this->assertSame('-200.0000', $report->inputBase());
        $this->assertSame('45.0000', $report->net(), 'What went back to the supplier adds to what is payable');
    }

    public function testNet_IsTheTaxCollectedLessTheTaxPaidAndIsTheSumOfTheSignedParts(): void
    {
        $report = $this->reportOf(['sales' => ['1000.0000', '150.0000'], 'sales_returns' => ['-100.0000', '-15.0000'], 'purchases' => ['-1000.0000', '-150.0000'], 'purchase_returns' => ['200.0000', '30.0000'], 'expenses' => ['-200.0000', '-30.0000']]);

        $this->assertSame('-15.0000', $report->net());
        $this->assertSame(bcsub($report->output(), $report->input(), 4), $report->net());
        $this->assertSame('135.0000', $report->output());
    }

    public function testNet_IsPositiveWhenMoreWasCollectedThanPaid(): void
    {
        $this->assertSame('298.5000', $this->reportOf(['sales' => ['2210.0000', '331.5000'], 'sales_returns' => ['-220.0000', '-33.0000']])->net());
        $this->assertSame('0.0000', $this->reportOf([])->net());
    }

    public function testSection_FindsEveryPartByName(): void
    {
        $report = $this->reportOf(['expenses' => ['-200.0000', '-30.0000']]);

        foreach (TaxSection::cases() as $section) {
            $this->assertSame($section, $report->section($section)->section);
        }

        $this->assertSame('-30.0000', $report->section(TaxSection::Expenses)->tax);
    }

    public function testSection_RefusesAPartTheReportDoesNotHave(): void
    {
        $report = new TaxReportResult([], [], ReportScope::resolve(null, null), null, '2026-10-31', TaxBasis::Accrual, null, 0, 0, true, true);

        $this->expectException(LogicException::class);

        $report->section(TaxSection::Purchases);
    }

    public function testTies_IsTrueWhenEveryAccountTiesAndBothKindsOfTaxAreRouted(): void
    {
        $this->assertTrue($this->reportOf([], [$this->figuresOf('10.0000'), $this->figuresOf('0.0000')])->ties());
        $this->assertTrue($this->reportOf([])->ties());
    }

    public function testTies_IsFalseWhenAnAccountDoesNotTie(): void
    {
        $differs = new TaxAccountFigures(2, '215002', 'Tax paid', ['input'], '5.0000', '0.0000', 1);

        $this->assertFalse($this->reportOf([], [$this->figuresOf('10.0000'), $differs])->ties());
    }

    public function testTies_IsFalseWhenAKindOfTaxHasNoAccountToBeRead(): void
    {
        $this->assertFalse($this->reportOf([], [], output: false)->ties());
        $this->assertFalse($this->reportOf([], [], input: false)->ties());
    }

    public function testIsEmpty_IsTrueWhenNoPartHasADocumentAndNoAccountOfTaxMoved(): void
    {
        $this->assertTrue($this->reportOf([], [$this->figuresOf('0.0000'), $this->figuresOf('0.0000')], documents: 0)->isEmpty());
        $this->assertTrue($this->reportOf([], documents: 0)->isEmpty());
    }

    public function testIsEmpty_IsFalseWhenAPartHasADocument(): void
    {
        $this->assertFalse($this->reportOf(['sales' => ['100.0000', '15.0000']], [$this->figuresOf('0.0000')])->isEmpty());
    }

    public function testIsEmpty_IsFalseWhenAnAccountOfTaxMovedOrHoldsAJournalThatIsNotOfADocument(): void
    {
        $this->assertFalse($this->reportOf([], [$this->figuresOf('10.0000')], documents: 0)->isEmpty());
        $this->assertFalse($this->reportOf([], [new TaxAccountFigures(1, '215001', 'Tax', ['output'], '0.0000', '0.0000', 2)], documents: 0)->isEmpty());
    }

    public function testIsEmpty_IsFalseWhenAKindOfTaxHasNoAccountToBeRead(): void
    {
        $this->assertFalse($this->reportOf([], [], output: false, documents: 0)->isEmpty());
        $this->assertFalse($this->reportOf([], [], input: false, documents: 0)->isEmpty());
    }

    public function testReachesBeforeBooks_IsTrueWhenThePeriodStartsBeforeTheDayTheBooksBeganOrHasNoStart(): void
    {
        $this->assertTrue($this->reportOf([], from: null, start: '2026-10-01')->reachesBeforeBooks());
        $this->assertTrue($this->reportOf([], from: '2026-09-30', start: '2026-10-01')->reachesBeforeBooks());
    }

    public function testReachesBeforeBooks_IsFalseFromTheDayTheBooksBeganOrWhenNoDayIsSet(): void
    {
        $this->assertFalse($this->reportOf([], from: '2026-10-01', start: '2026-10-01')->reachesBeforeBooks());
        $this->assertFalse($this->reportOf([], from: '2026-10-02', start: '2026-10-01')->reachesBeforeBooks());
        $this->assertFalse($this->reportOf([], from: null, start: null)->reachesBeforeBooks());
    }
}
