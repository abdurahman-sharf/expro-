<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\IncomeStatement;
use App\Libraries\Accounting\ProfitAndLoss;
use App\Libraries\Accounting\ProfitAndLossReport;
use App\Libraries\Accounting\ProfitBasis;
use App\Libraries\Accounting\ProfitLine;
use App\Libraries\Accounting\ProfitPeriodKind;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\StatementFixtureTrait;

/**
 * The profit and loss by accrual: a column for each month, quarter or year, the columns adding up
 * to the income statement of the whole range, the levels, the branches, the journals that close a
 * year, and the amounts kept at the four decimals they are stored at.
 */
final class ProfitAndLossTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ReportFixtureTrait;
    use StatementFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ProfitAndLoss $statement;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetBranches();
        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->second    = $this->createBranch('T2', 'Second branch');
        $this->statement = new ProfitAndLoss();
    }

    protected function tearDown(): void
    {
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function profit(string $to = '2026-12-31', ?string $from = '2026-01-01', ProfitPeriodKind $kind = ProfitPeriodKind::Monthly, ?int $level = null, ?ReportScope $scope = null): ProfitAndLossReport
    {
        return $this->statement->build($scope ?? ReportScope::resolve(null, null), ReportPeriod::of($from, $to), $kind, $level);
    }

    private function lineOf(ProfitAndLossReport $report, string $code): ?ProfitLine
    {
        foreach ([...$report->revenueLines, ...$report->expenseLines] as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param list<string> $amounts
     */
    private function sum(array $amounts): string
    {
        return array_reduce($amounts, static fn (string $total, string $amount): string => bcadd($total, $amount, 4), '0.0000');
    }

    private function threeMonths(): void
    {
        $cash = $this->accountId('cash');

        $this->postOn('2026-08-10', 1, [[$cash, '100.00', '0'], [$this->accountId('sales_revenue'), '0', '100.00']]);
        $this->postOn('2026-08-12', 1, [[$this->accountId('cogs'), '40.00', '0'], [$cash, '0', '40.00']]);
        $this->postOn('2026-09-03', 1, [[$cash, '250.00', '0'], [$this->accountId('sales_revenue'), '0', '250.00']]);
        $this->postOn('2026-09-20', 1, [[$this->accountId('sales_returns'), '30.00', '0'], [$cash, '0', '30.00']]);
        $this->postOn('2026-10-05', 1, [[$cash, '400.00', '0'], [$this->accountId('sales_revenue'), '0', '400.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountIdByCode('545'), '75.00', '0'], [$cash, '0', '75.00']]);
        $this->postOn('2026-11-02', 1, [[$this->accountIdByCode('545'), '25.00', '0'], [$cash, '0', '25.00']]);
    }

    public function testBuild_SplitsTheIncomeStatementIntoMonthsQuartersAndYears(): void
    {
        $this->threeMonths();

        $monthly   = $this->profit();
        $quarterly = $this->profit(kind: ProfitPeriodKind::Quarterly);
        $yearly    = $this->profit(kind: ProfitPeriodKind::Yearly);

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11', '2026-12'], array_map(static fn ($period): string => $period->label, $monthly->periods));
        $this->assertSame(['Q1 2026', 'Q2 2026', 'Q3 2026', 'Q4 2026'], array_map(static fn ($period): string => $period->label, $quarterly->periods));
        $this->assertSame(['2026'], array_map(static fn ($period): string => $period->label, $yearly->periods));

        $this->assertSame(['100.0000', '220.0000', '400.0000', '0.0000'], [$monthly->revenue[7], $monthly->revenue[8], $monthly->revenue[9], $monthly->revenue[10]]);
        $this->assertSame(['40.0000', '0.0000', '75.0000', '25.0000'], [$monthly->expenses[7], $monthly->expenses[8], $monthly->expenses[9], $monthly->expenses[10]]);

        foreach ([$monthly, $quarterly, $yearly] as $report) {
            $this->assertSame($report->totalRevenue, $this->sum($report->revenue), 'The columns add up to the revenue of the range');
            $this->assertSame($report->totalExpenses, $this->sum($report->expenses));
            $this->assertSame($report->totalProfit, $this->sum($report->profit));
            $this->assertSame(['720.0000', '140.0000', '580.0000'], [$report->totalRevenue, $report->totalExpenses, $report->totalProfit]);
        }

        $this->assertSame(['320.0000', '400.0000'], [$quarterly->revenue[2], $quarterly->revenue[3]]);
    }

    public function testBuild_TiesToTheIncomeStatementOfTheSameDaysAndBranches(): void
    {
        $this->threeMonths();
        $this->postOn('2026-10-09', $this->second, [[$this->accountId('cash'), '60.00', '0'], [$this->accountId('sales_revenue'), '0', '60.00']]);

        foreach ([null, [1], [$this->second]] as $branches) {
            $scope     = ReportScope::resolve($branches, null);
            $income    = (new IncomeStatement())->build($scope, ReportPeriod::of('2026-01-01', '2026-12-31'), 5);
            $profit    = $this->profit(scope: $scope, level: 5);

            $this->assertSame($income->revenue->total, $profit->totalRevenue, 'Branches ' . json_encode($branches));
            $this->assertSame($income->expenses->total, $profit->totalExpenses);
            $this->assertSame($income->netIncome, $profit->totalProfit);

            foreach ($income->revenue->lines as $line) {
                $this->assertSame($line->amount, $this->lineOf($profit, $line->code)?->total ?? '0.0000', "Revenue account {$line->code}");
            }
        }
    }

    public function testBuild_ShowsSalesReturnsAsANegativeInsideTheRevenueOfTheirColumn(): void
    {
        $this->threeMonths();

        $report = $this->profit(level: 3);

        $this->assertSame(['0.0000', '-30.0000', '0.0000'], [$this->lineOf($report, '412')->amounts[7], $this->lineOf($report, '412')->amounts[8], $this->lineOf($report, '412')->amounts[9]]);
        $this->assertSame('-30.0000', $this->lineOf($report, '412')->total);
        $this->assertSame('220.0000', $this->lineOf($report, '41')->amounts[8], 'Net sales of September are the sales less the returns');
    }

    public function testBuild_ListsOnlyTheAccountsThatMovedInTheRangeWithAColumnForEveryPeriod(): void
    {
        $this->threeMonths();

        $report = $this->profit(level: 5);

        $this->assertSame(['4', '41', '411', '412'], array_map(static fn (ProfitLine $line): string => $line->code, $report->revenueLines));
        $this->assertSame(['5', '51', '511', '54', '545'], array_map(static fn (ProfitLine $line): string => $line->code, $report->expenseLines));
        $this->assertCount(12, $this->lineOf($report, '411')->amounts);
    }

    public function testBuild_KeepsTheTotalsWhateverTheLevelAndGrowsTheRowsWithIt(): void
    {
        $this->threeMonths();

        $sizes  = [];
        $totals = [];

        foreach ([1, 2, 3, 4, 5] as $level) {
            $report         = $this->profit(level: $level);
            $sizes[$level]  = count($report->revenueLines) + count($report->expenseLines);
            $totals[$level] = [$report->totalRevenue, $report->totalExpenses, $report->totalProfit, $report->revenue, $report->profit];
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $totals)), 'The level changes what is listed and never what is added up');
        $this->assertSame(2, $sizes[1]);
        $this->assertGreaterThan($sizes[1], $sizes[3]);
        $this->assertSame($sizes[4], $sizes[5], 'The chart of the test has no account at the fifth level that moved');
    }

    public function testBuild_ReadsLevelThreeWhenNoneIsAskedAndNeverGoesPastWhatTheChartHas(): void
    {
        $this->threeMonths();

        $this->assertSame(3, $this->profit()->level);
        $this->assertSame(3, $this->profit(level: 0)->level, 'A level below one is read as the default');
        $this->assertSame($this->profit(level: 9)->deepestLevel, $this->profit(level: 9)->level);
    }

    public function testBuild_StartsOnTheFirstOfJanuaryOfTheYearOfTheLastDayWithoutAFirstDay(): void
    {
        $this->cashSale('2025-12-31', 1, '999.00');
        $this->cashSale('2026-02-10', 1, '100.00');

        $report = $this->statement->build(ReportScope::resolve(null, null), ReportPeriod::of(null, '2026-03-31'));

        $this->assertSame('2026-01-01', $report->from);
        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_map(static fn ($period): string => $period->label, $report->periods));
        $this->assertSame('100.0000', $report->totalRevenue, 'The sale of last year is not in a range that starts in January');
    }

    public function testBuild_CutsTheFirstAndTheLastColumnShortByTheRange(): void
    {
        $this->cashSale('2026-10-10', 1, '1.00');
        $this->cashSale('2026-10-20', 1, '20.00');
        $this->cashSale('2026-11-05', 1, '300.00');
        $this->cashSale('2026-11-20', 1, '4000.00');

        $report = $this->profit('2026-11-10', '2026-10-15');

        $this->assertSame([['2026-10', '2026-10-15', '2026-10-31'], ['2026-11', '2026-11-01', '2026-11-10']], array_map(static fn ($period): array => [$period->label, $period->from, $period->to], $report->periods));
        $this->assertSame(['20.0000', '300.0000'], $report->revenue);
    }

    public function testBuild_ReadsARangeInsideOneMonthAsOneColumn(): void
    {
        $this->cashSale('2026-10-10', 1, '5.00');

        $report = $this->profit('2026-10-12', '2026-10-09');

        $this->assertCount(1, $report->periods);
        $this->assertSame('5.0000', $report->totalProfit);
    }

    public function testBuild_ReadsAReversedJournalWithItsReversalInTheColumnsTheyFallIn(): void
    {
        $journal = $this->cashSale('2026-10-05', 1, '100.00');
        $this->reverseOn($journal, '2026-11-03');

        $report = $this->profit();

        $this->assertSame(['100.0000', '-100.0000'], [$report->revenue[9], $report->revenue[10]], 'The sale is in October and its reversal in November');
        $this->assertSame('0.0000', $report->totalRevenue);
        $this->assertSame('0.0000', $report->totalProfit);
    }

    public function testBuild_LeavesOutTheJournalsThatCloseAYear(): void
    {
        $this->threeMonths();
        $this->postYearEnd('2026-12-31', 1, [
            [$this->accountId('sales_revenue'), '720.00', '0'],
            [$this->accountId('sales_returns'), '0', '30.00'],
            [$this->accountId('cogs'), '0', '40.00'],
            [$this->accountIdByCode('545'), '0', '100.00'],
            [$this->accountId('retained_earnings'), '0', '550.00'],
        ]);

        $report = $this->profit();

        $this->assertSame(['720.0000', '140.0000', '580.0000'], [$report->totalRevenue, $report->totalExpenses, $report->totalProfit], 'The year reads the same after it is closed');
        $this->assertSame('0.0000', $report->revenue[11], 'Nothing of the closing falls in December');
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-10-06', $this->second, '30.00');

        $first = $this->profit(scope: ReportScope::resolve([1], null));
        $both  = $this->profit();
        $none  = $this->profit(scope: ReportScope::resolve(null, []));

        $this->assertSame('100.0000', $first->totalRevenue);
        $this->assertSame('130.0000', $both->totalRevenue);
        $this->assertSame(['0.0000', '0.0000', '0.0000'], [$none->totalRevenue, $none->totalExpenses, $none->totalProfit]);
    }

    public function testBuild_HasZerosForARangeWithNothingInIt(): void
    {
        $report = $this->profit('2026-03-31');

        $this->assertSame(['0.0000', '0.0000', '0.0000'], [$report->totalRevenue, $report->totalExpenses, $report->totalProfit]);
        $this->assertSame([], $report->revenueLines);
        $this->assertSame(['0.0000', '0.0000', '0.0000'], $report->revenue);
        $this->assertSame(ProfitBasis::Accrual, $report->basis);
    }

    public function testBuild_RoundsOnlyWhenShowingSoTheTotalsComeFromTheStoredAmounts(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountId('cash'), '0.0040', '0'], [$this->accountId('sales_revenue'), '0', '0.0040']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '0.0040', '0'], [$this->accountId('sales_revenue'), '0', '0.0040']]);

        $report = $this->profit();

        $this->assertSame('0.0080', $report->totalRevenue, 'Two cells that show as 0.00 add up to 0.01');
        $this->assertSame('0.0080', $report->revenue[9]);
        $this->assertSame('0.0080', $this->lineOf($report, '411')->amounts[9]);
    }

    public function testBuild_RejectsARangeOfMoreColumnsThanTheReportDraws(): void
    {
        $this->assertCount(ProfitAndLoss::MAX_PERIODS, $this->profit('2030-12-31', '2026-01-01')->periods);

        try {
            $this->profit('2031-01-31', '2026-01-01');
            $this->fail('A range of sixty one months was drawn.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::ReportTooManyPeriods, $refusal->error);
            $this->assertSame(ProfitAndLoss::MAX_PERIODS, $refusal->context['max']);
        }

        $this->assertCount(6, $this->profit('2031-01-31', '2026-01-01', ProfitPeriodKind::Yearly)->periods, 'Years of the same range fit');
    }
}
