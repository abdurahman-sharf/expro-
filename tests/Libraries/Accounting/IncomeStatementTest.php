<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\IncomeStatement;
use App\Libraries\Accounting\IncomeStatementReport;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\StatementDisplay;
use App\Libraries\Accounting\StatementLine;
use App\Libraries\Accounting\StatementSection;
use App\Libraries\Accounting\TrialBalance;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StatementFixtureTrait;

/**
 * What the revenue and the expenses of a period add up to, read from the journals that are in the
 * books: the signs by section, the period, the branches, the journals that close a year, and the
 * ties to the trial balance and to the documents.
 */
class IncomeStatementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PreviewJournalFixtureTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use ReportFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use StatementFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private IncomeStatement $statements;

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

        $this->second     = $this->createBranch('T2', 'Second branch');
        $this->statements = new IncomeStatement();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();
        $this->stopBuying();
        $this->clearJournals();
        $this->reinstallChart();
        $this->resetBranches();

        parent::tearDown();
    }

    private function statement(string $to = '2026-12-31', ?string $from = null, ?ReportScope $scope = null, ?int $level = null, StatementDisplay $display = StatementDisplay::All): IncomeStatementReport
    {
        return $this->statements->build($scope ?? ReportScope::resolve(null, null), ReportPeriod::of($from, $to), $level, $display);
    }

    private function lineOf(StatementSection $section, string $code): ?StatementLine
    {
        foreach ($section->lines as $line) {
            if ($line->code === $code) {
                return $line;
            }
        }

        return null;
    }

    private function amountOf(StatementSection $section, string $code): ?string
    {
        return $this->lineOf($section, $code)?->amount;
    }

    /**
     * @return list<string>
     */
    private function codes(StatementSection $section): array
    {
        return array_map(static fn (StatementLine $line): string => $line->code, $section->lines);
    }

    private function closeTheYearOfTheScenario(int $branchId = 1): int
    {
        return $this->postYearEnd('2026-12-31', $branchId, [
            [$this->accountId('sales_revenue'), '2210.00', '0'],
            [$this->accountId('sales_returns'), '0', '220.00'],
            [$this->accountId('cogs'), '0', '5280.00'],
            [$this->accountIdByCode('545'), '0', '3000.00'],
            [$this->accountId('retained_earnings'), '6290.00', '0'],
        ]);
    }

    public function testBuild_GivesRevenueMinusExpensesAndPutsTheCostOfSalesAmongTheExpenses(): void
    {
        $this->abScenario();

        $report = $this->statement(level: 5);

        $this->assertSame('1990.0000', $report->revenue->total);
        $this->assertSame('8280.0000', $report->expenses->total);
        $this->assertSame('-6290.0000', $report->netIncome);
        $this->assertSame('5280.0000', $this->amountOf($report->expenses, '51'));
        $this->assertSame('5280.0000', $this->amountOf($report->expenses, '511'), 'The cost of sales is one of the expenses');
        $this->assertSame('3000.0000', $this->amountOf($report->expenses, '54'));
        $this->assertNull($this->lineOf($report->revenue, '511'));
        $this->assertSame(bcsub($report->revenue->total, $report->expenses->total, 4), $report->netIncome);
    }

    public function testBuild_ShowsSalesReturnsAsANegativeInsideTheRevenue(): void
    {
        $this->abScenario();

        $report = $this->statement(level: 3);

        $this->assertSame('2210.0000', $this->amountOf($report->revenue, '411'));
        $this->assertSame('-220.0000', $this->amountOf($report->revenue, '412'), 'A debit balance inside the revenue is a minus');
        $this->assertSame('1990.0000', $this->amountOf($report->revenue, '41'), 'Net sales are the sales less the returns: 2,210 less 220');
    }

    public function testBuild_ShowsAnExpenseThatWasCreditedAsANegative(): void
    {
        $this->postOn('2026-10-05', 1, [[$this->accountIdByCode('545'), '100.00', '0'], [$this->accountId('cash'), '0', '100.00']]);
        $this->postOn('2026-10-06', 1, [[$this->accountId('cash'), '30.00', '0'], [$this->accountIdByCode('545'), '0', '30.00']]);
        $this->postOn('2026-10-07', 1, [[$this->accountId('cash'), '5.00', '0'], [$this->accountIdByCode('542'), '0', '5.00']]);

        $report = $this->statement(level: 3);

        $this->assertSame('70.0000', $this->amountOf($report->expenses, '545'));
        $this->assertSame('-5.0000', $this->amountOf($report->expenses, '542'));
        $this->assertSame('65.0000', $report->expenses->total);
    }

    public function testBuild_GivesTheTotalsObservedInTheReferenceSystem(): void
    {
        $this->observedBooks(1, $this->second);

        $report = $this->statement(level: 3);

        $this->assertSame('9222.0100', $this->amountOf($report->revenue, '411'));
        $this->assertSame('-1110.0000', $this->amountOf($report->revenue, '412'));
        $this->assertSame('8112.0100', $this->amountOf($report->revenue, '41'));
        $this->assertSame('0.0000', $this->amountOf($report->revenue, '42'));
        $this->assertSame('8112.0100', $report->revenue->total);
        $this->assertSame('8188.4700', $this->amountOf($report->expenses, '51'));
        $this->assertSame('200.0000', $this->amountOf($report->expenses, '541'));
        $this->assertSame('3000.0000', $this->amountOf($report->expenses, '545'));
        $this->assertSame('3200.0000', $this->amountOf($report->expenses, '54'));
        $this->assertSame('11388.4700', $report->expenses->total);
        $this->assertSame('-3276.4600', $report->netIncome);
    }

    public function testBuild_TiesToTheRootsOfTheTrialBalanceForTheSamePeriodAndBranches(): void
    {
        $this->observedBooks(1, $this->second);

        $trial = new TrialBalance();

        foreach ([[null, '2026-12-31'], ['2026-10-06', '2026-10-08'], ['2026-10-05', '2026-10-05']] as [$from, $to]) {
            foreach ([null, [1], [$this->second], [1, $this->second]] as $branches) {
                $scope   = ReportScope::resolve($branches, null);
                $period  = ReportPeriod::of($from, $to);
                $roots   = [];
                $label   = ($from ?? 'start') . ' to ' . $to . ' for ' . json_encode($branches);
                $balance = $trial->build($scope, $period);

                foreach ($balance->rows as $row) {
                    $roots[$row->code] = $row;
                }

                $report = $this->statements->build($scope, $period);

                $this->assertSame(isset($roots['4']) ? bcsub($roots['4']->credit, $roots['4']->debit, 4) : '0.0000', $report->revenue->total, "Revenue, {$label}");
                $this->assertSame(isset($roots['5']) ? bcsub($roots['5']->debit, $roots['5']->credit, 4) : '0.0000', $report->expenses->total, "Expenses, {$label}");
            }
        }
    }

    public function testBuild_AddsUpOnlyTheBranchesOfTheScope(): void
    {
        $this->observedBooks(1, $this->second);

        $first  = $this->statement(scope: ReportScope::resolve([1], null));
        $second = $this->statement(scope: ReportScope::resolve([$this->second], null));
        $both   = $this->statement();

        $this->assertSame('8112.0100', $first->revenue->total);
        $this->assertSame('0.0000', $first->expenses->total);
        $this->assertSame('0.0000', $second->revenue->total);
        $this->assertSame('11388.4700', $second->expenses->total);
        $this->assertSame('-3276.4600', $both->netIncome);
        $this->assertSame(bcadd($first->netIncome, $second->netIncome, 4), $both->netIncome, 'The company is what its branches are');
        $this->assertTrue($both->scope->isCompany());
        $this->assertFalse($first->scope->isCompany());
    }

    public function testBuild_HasNothingToShowForNoBranch(): void
    {
        $this->abScenario();

        $report = $this->statement(scope: ReportScope::resolve(null, []));

        $this->assertSame('0.0000', $report->revenue->total);
        $this->assertSame('0.0000', $report->expenses->total);
        $this->assertSame('0.0000', $report->netIncome);
        $this->assertTrue($report->scope->isEmpty());
    }

    public function testBuild_CountsTheFirstAndTheLastDayOfThePeriodAndNothingOutsideIt(): void
    {
        $this->cashSale('2026-09-30', 1, '1.00');
        $this->cashSale('2026-10-01', 1, '2.00');
        $this->cashSale('2026-10-31', 1, '4.00');
        $this->cashSale('2026-11-01', 1, '8.00');

        $this->assertSame('6.0000', $this->statement('2026-10-31', '2026-10-01')->revenue->total);
        $this->assertSame('2.0000', $this->statement('2026-10-01', '2026-10-01')->revenue->total);
    }

    public function testBuild_AddsUpEveryDayFromTheStartOfTheBooksWithoutAFirstDay(): void
    {
        $this->cashSale('2020-01-01', 1, '5.00');
        $this->cashSale('2026-10-05', 1, '100.00');
        $this->cashSale('2026-11-05', 1, '1000.00');

        $report = $this->statement('2026-10-31');

        $this->assertNull($report->from);
        $this->assertSame('2026-10-31', $report->to);
        $this->assertSame('105.0000', $report->revenue->total);
    }

    public function testBuild_ReadsAJournalThatWasReversedWithItsReversalSoTheyLeaveNothing(): void
    {
        $this->abScenario();

        $before  = $this->statement();
        $journal = $this->cashSale('2026-10-09', 1, '700.00');

        $this->assertSame('2690.0000', $this->statement()->revenue->total);

        $this->reverseOn($journal, '2026-10-10');

        $after = $this->statement();

        $this->assertSame($before->revenue->total, $after->revenue->total);
        $this->assertSame($before->netIncome, $after->netIncome);
        $this->assertSame('2210.0000', $this->amountOf($this->statement(level: 3)->revenue, '411'));
    }

    public function testBuild_LeavesOutADraftThatWasNeverPosted(): void
    {
        $this->abScenario();

        $before = $this->statement();

        (new JournalService())->saveDraft($this->journalInput('500.00'), null);

        $after = $this->statement();

        $this->assertSame($before->netIncome, $after->netIncome);
        $this->assertSame($before->revenue->total, $after->revenue->total);
    }

    public function testBuild_LeavesOutAJournalThatIsOnlyAPreview(): void
    {
        $this->abScenario();

        $before  = $this->statement();
        $journal = $this->cashSale('2026-10-09', 1, '500.00');

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal)->update(['status' => 'preview']);

        $this->assertSame($before->netIncome, $this->statement()->netIncome);
        $this->assertSame($before->revenue->total, $this->statement()->revenue->total);
    }

    public function testBuild_LeavesOutTheJournalThatClosesAYear(): void
    {
        $this->abScenario();

        $before = $this->statement('2026-12-31', '2026-01-01', level: 3);

        $this->closeTheYearOfTheScenario();

        $after = $this->statement('2026-12-31', '2026-01-01', level: 3);

        $this->assertSame('-6290.0000', $after->netIncome);
        $this->assertEquals($before, $after, 'A year reads the same before and after it is closed');
        $this->assertSame('2210.0000', $this->amountOf($after->revenue, '411'));
        $this->assertSame('-6290.0000', $this->statement('2026-12-31', level: 3)->netIncome, 'Without a first day as well');
    }

    public function testBuild_ReadsAYearTheSameAfterItsClosingIsReversed(): void
    {
        $this->abScenario();

        $before  = $this->statement('2027-12-31', '2026-01-01', level: 3);
        $closing = $this->closeTheYearOfTheScenario();

        $this->reverseAClosing($closing, '2027-01-05');

        $after = $this->statement('2027-12-31', '2026-01-01', level: 3);

        $this->assertSame('-6290.0000', $after->netIncome);
        $this->assertEquals($before, $after, 'A closing that was taken back is left out with its reversal');
        $this->assertSame('1990.0000', $after->revenue->total);
        $this->assertSame('8280.0000', $after->expenses->total);
        $this->assertSame('-6290.0000', $this->statement('2027-12-31', level: 3)->netIncome, 'Without a first day as well');
    }

    public function testBuild_ReadsTheMonthOfAReversedClosingAsOneWithNothingInIt(): void
    {
        $this->abScenario();

        $closing = $this->closeTheYearOfTheScenario();

        $this->reverseAClosing($closing, '2027-01-05');

        $january = $this->statement('2027-01-31', '2027-01-01', level: 3);

        $this->assertSame('0.0000', $january->revenue->total);
        $this->assertSame('0.0000', $january->expenses->total);
        $this->assertSame('0.0000', $january->netIncome);
    }

    public function testBuild_ReadsAYearTheSameWhenItsClosingWasReversedAndReplaced(): void
    {
        $this->abScenario();

        $before  = $this->statement('2027-12-31', '2026-01-01', level: 3);
        $closing = $this->closeTheYearOfTheScenario();

        $this->reverseAClosing($closing, '2027-01-05');
        $this->closeTheYearOfTheScenario();

        $this->assertEquals($before, $this->statement('2027-12-31', '2026-01-01', level: 3));
    }

    public function testBuild_ReadsAYearTheSameWhenTheReversalOfItsClosingIsReversedToo(): void
    {
        $this->abScenario();

        $before   = $this->statement('2027-12-31', '2026-01-01', level: 3);
        $closing  = $this->closeTheYearOfTheScenario();
        $reversal = $this->reverseAClosing($closing, '2027-01-05');

        $this->reverseAReversal($reversal, '2027-01-06');

        $this->assertEquals($before, $this->statement('2027-12-31', '2026-01-01', level: 3));
        $this->assertSame('0.0000', $this->statement('2027-01-31', '2027-01-01')->netIncome);
    }

    public function testBuild_LeavesOutAJournalWhoseTypeIsYearEnd(): void
    {
        $this->abScenario();

        $before  = $this->statement();
        $closing = $this->postOn('2026-12-31', 1, [
            [$this->accountId('sales_revenue'), '2210.00', '0'],
            [$this->accountId('sales_returns'), '0', '220.00'],
            [$this->accountId('cogs'), '0', '5280.00'],
            [$this->accountIdByCode('545'), '0', '3000.00'],
            [$this->accountId('retained_earnings'), '6290.00', '0'],
        ]);

        $this->assertSame('0.0000', $this->statement()->revenue->total, 'Taken for a journal like any other it empties the year');

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $closing)->update(['entry_type' => 'year_end']);

        $this->assertEquals($before, $this->statement());
    }

    public function testBuild_CountsEveryJournalThatDoesNotCloseAYear(): void
    {
        $this->abScenario();
        $this->postOn('2026-12-31', 1, [[$this->accountId('cash'), '40.00', '0'], [$this->accountId('sales_revenue'), '0', '40.00']]);

        $this->assertSame('2030.0000', $this->statement('2026-12-31')->revenue->total);
        $this->assertSame('40.0000', $this->statement('2026-12-31', '2026-12-31')->revenue->total, 'A journal of the last day of the year is the revenue of that day');
    }

    public function testBuild_ReturnsToItsPriorFiguresWhenASaleIsCancelled(): void
    {
        $this->startBooks();
        $this->workInBranches(1);
        $this->invoiceOf('100.00', $this->customerId, [$this->payment(lang('Sales.cash'), '30.00')]);

        $before = $this->statement(level: 3);
        $saleId = $this->save([$this->line($this->item('0', false), 0, '1', '55.00')], [], [$this->payment(lang('Sales.cash'), '55.00')], SALE_TYPE_POS);

        $this->assertSame('155.0000', $this->statement(level: 3)->revenue->total);
        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $after = $this->statement(level: 3);

        $this->assertSame('100.0000', $after->revenue->total);
        $this->assertEquals($before, $after);
    }

    public function testBuild_ShowsTheExpensesOfTheExpenseScreenInTheExpenses(): void
    {
        $this->startExpenses();

        $category = $this->category('Rent', $this->accountIdByCode('545'));

        $this->assertNotNull($this->saveExpense($this->expenseData('230.00', '30.00', 'Cash', $category)));
        $this->assertNotNull($this->saveExpense($this->expenseData('100.00', '0', 'Cash', null)));

        $report = $this->statement(level: 3);

        $this->assertSame('200.0000', $this->amountOf($report->expenses, '545'), 'The amount less its tax goes to the account of the category');
        $this->assertSame('100.0000', $this->amountOf($report->expenses, '541'), 'A category with no account goes to the other expenses');
        $this->assertSame('300.0000', $report->expenses->total);
        $this->assertSame('-300.0000', $report->netIncome);
    }

    public function testBuild_TakesAnExpenseOutOfTheStatementWhenItIsDeleted(): void
    {
        $this->startExpenses();

        $id = $this->saveExpense($this->expenseData('100.00'));

        $this->assertSame('100.0000', $this->statement()->expenses->total);
        $this->assertTrue($this->expenses->delete_list([$id], $this->clerkId));
        $this->assertSame('0.0000', $this->statement()->expenses->total);
    }

    public function testBuild_AddsUpTheCostOfSalesOfRealSalesAmongTheExpenses(): void
    {
        $this->startBooks();
        $this->workInEveryBranch();

        $item = $this->item('40.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '40')]));
        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '2', '100', '40')]));

        $report = $this->statement(level: 3);

        $this->assertSame('200.0000', $report->revenue->total);
        $this->assertSame('80.0000', $this->amountOf($report->expenses, '511'));
        $this->assertSame('120.0000', $report->netIncome);
    }

    public function testBuild_ListsOnlyTheRevenueAndTheExpenses(): void
    {
        $this->abScenario();

        $report = $this->statement(level: 5);

        foreach ($report->revenue->lines as $line) {
            $this->assertStringStartsWith('4', $line->code);
        }

        foreach ($report->expenses->lines as $line) {
            $this->assertStringStartsWith('5', $line->code);
        }
    }

    public function testBuild_RejectsADayThatIsNotADate(): void
    {
        try {
            $this->statement('2026-02-30');
            $this->fail('A day that is not a date was taken');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::InvalidDate, $exception->error);
        }
    }

    public function testBuild_RejectsAPeriodThatEndsBeforeItStarts(): void
    {
        try {
            $this->statement('2026-10-01', '2026-10-31');
            $this->fail('A period that ends before it starts was taken');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::ReportPeriodInvalid, $exception->error);
        }
    }

    public function testBuild_LeavesOutThePreviewsOfABatch(): void
    {
        $this->abScenario();

        $before = $this->statement(level: 5);

        $this->previewEverything();
        $this->assertPreviewsStand();

        $this->assertEquals($before, $this->statement(level: 5));
    }
}
