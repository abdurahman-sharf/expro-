<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\FamilyReports\StockTurnoverReport;
use Tests\Support\FamilyReportTestCase;

/**
 * How fast the stock turns over, in the usual way: the cost of sales divided by the average of the
 * value at the start and at the end, the days to sell the stock, the turnover of the whole stock as the
 * total, and the ties to the accounts of the stock locations and to the cost of sales the sales journals
 * charged.
 *
 * @internal
 */
final class StockTurnoverReportTest extends FamilyReportTestCase
{
    private StockTurnoverReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyShelves();

        $this->report = new StockTurnoverReport();
    }

    protected function tearDown(): void
    {
        $this->restoreShelves();

        parent::tearDown();
    }

    /**
     * Ten bought at 50 and four sold, which cost 200 and leave 300.
     */
    private function trade(): int
    {
        $item = $this->named('Cream', 'Care');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50.00')]));
        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '4', '100.00', '50.00')], [], [$this->payment(lang('Sales.cash'), '400')]));

        return $item;
    }

    public function testBuild_DividesTheCostOfSalesByTheAverageOfTheOpeningAndClosingValue(): void
    {
        $this->trade();

        $table = $this->built($this->report, $this->thisDay());
        $row   = $this->rowWhere($table, 'name', 'Cream');

        $this->assertNotNull($row);
        $this->assertAmount('200.00', $row->cell('cogs'));
        $this->assertAmount('0.00', $row->cell('opening'));
        $this->assertAmount('300.00', $row->cell('closing'));
        $this->assertAmount('150.00', $row->cell('average'));
        $this->assertSame('1.33', $row->cell('ratio'));
        $this->assertSame('0.75', $row->cell('days'));
    }

    public function testBuild_GivesTheTurnoverOfTheWholeStockAsTheTotalNotTheSumOfTheItems(): void
    {
        $this->trade();

        $soap = $this->named('Soap', 'Home');
        $this->bookStock($this->today(), 1, $soap, '10', '300.00');

        $table = $this->built($this->report, $this->thisDay());

        $this->assertAmount('200.00', $this->grandOf($table, 'cogs'));
        $this->assertAmount('600.00', $this->grandOf($table, 'closing'));
        $this->assertAmount('300.00', $this->grandOf($table, 'average'));
        $this->assertSame('0.67', $this->grandOf($table, 'ratio'));
        $this->assertSame('1.50', $this->grandOf($table, 'days'));
    }

    public function testBuild_TiesTheClosingValueToTheAccountsAndTheCostOfSalesToTheJournals(): void
    {
        $this->trade();

        $table = $this->built($this->report, $this->thisDay());

        $this->assertCount(2, $table->ties);
        $this->assertReportTies($table);
        $this->assertAmount('200.00', $table->ties[1]->book);
    }

    public function testBuild_SaysSoWhenTheMovementsOfTheSalesCostMoreThanTheJournalsChargedToTheCostOfSales(): void
    {
        $this->trade();

        $this->accountingDb()->table('accounting_stock_entries')->where('source_type', 'sale')->set('value', 'value - 5', false)->update();

        $table = $this->built($this->report, $this->thisDay());

        $this->assertFalse($table->ties());
        $this->assertAmount('5.00', $table->ties[1]->difference());
        $this->assertAmount('-5.00', $table->ties[0]->difference());
    }

    public function testBuild_SpreadsTheDaysOfThePeriodAndGivesNoTurnoverToAnItemThatDidNotSell(): void
    {
        $this->trade();

        $idle = $this->named('Idle', 'Home');
        $this->bookStock($this->ago(9), 1, $idle, '5', '100.00');

        $table = $this->built($this->report, ['from' => $this->ago(9), 'to' => $this->today(), 'group' => 'category']);

        $this->assertSame('0.00', $this->rowWhere($table, 'name', 'Home')->cell('ratio'));
        $this->assertSame('0.00', $this->rowWhere($table, 'name', 'Home')->cell('days'));
        $this->assertNotNull($this->rowWhere($table, 'name', 'Care'));
        $this->assertStringContainsString('10', implode(' ', $table->notes));
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndDoesNotCompareAnItem(): void
    {
        $item   = $this->trade();
        $branch = $this->createBranch('TO2', 'Turnover branch');

        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));
        $narrow  = $this->built($this->report, [...$this->thisDay(), 'item_id' => (string) $item]);

        $this->assertSame([], $this->rowsOf($limited));
        $this->assertAmount('0', $this->grandOf($limited, 'cogs'));
        $this->assertSame([], $narrow->ties);
    }

    public function testBuild_RefusesADayThatIsNotOneWhenOnlyTheEndOfThePeriodIsGiven(): void
    {
        foreach (['abc', '2026-13-45', '2026-02-30'] as $day) {
            try {
                $this->built($this->report, ['to' => $day]);
                $this->fail('The day ' . $day . ' was accepted');
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::InvalidDate, $refusal->error, $day);
            }
        }
    }

    public function testBuild_StartsAYearThatIsGivenOnlyItsEndOnTheFirstDayOfThatYear(): void
    {
        $table = $this->built($this->report, ['to' => '2026-03-15']);

        $this->assertMatchesRegularExpression('/\b74\b/u', implode(' ', $table->notes));
    }

    public function testBuild_GivesTheDaysToSellWithoutDividingByATurnoverTooSmallToShow(): void
    {
        $item = $this->named('Bulk', 'Care');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1000000', '10.00')]));
        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '0.1', '20.00', '10.00')], [], [$this->payment(lang('Sales.cash'), '2')]));

        $table = $this->built($this->report, $this->thisDay());
        $row   = $this->rowWhere($table, 'name', 'Bulk');

        $this->assertNotNull($row);
        $this->assertAmount('1.00', $row->cell('cogs'));
        $this->assertSame('0.00', $row->cell('ratio'));
        $this->assertAmount('4999999.50', $row->cell('days'));
        $this->assertSame('0.00', $this->grandOf($table, 'ratio'));
        $this->assertAmount('4999999.50', $this->grandOf($table, 'days'));
    }

    public function testBuild_RefusesAPeriodThatEndsBeforeItStarts(): void
    {
        $this->expectException(AccountingException::class);

        $this->built($this->report, ['from' => '2026-05-02', 'to' => '2026-05-01']);
    }
}
