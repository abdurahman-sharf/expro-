<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\ExpensesReport;
use App\Libraries\Roles\RoleService;
use Tests\Support\CashReportTestCase;

/**
 * What was spent: by category, employee, vendor, treasury and period, as a summary or as the
 * documents, with the amount without tax, the tax and the total with tax, the total beside the journals
 * that stand for the expenses, and the branches an employee may read.
 *
 * @internal
 */
final class ExpensesReportTest extends CashReportTestCase
{
    private ExpensesReport $report;
    private int $drawer;
    private int $till;
    private int $rent;
    private int $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new ExpensesReport();
        $this->drawer = $this->treasury('Main drawer');
        $this->till   = $this->treasury('Side till');
        $this->rent   = $this->category('Rent');
        $this->other  = $this->category('Other');
    }

    /**
     * Rent of 3,000 without tax and 230 with 30 of tax, both through the main drawer, and 100 through
     * the side till.
     *
     * @return array{rent: int, other: int, small: int}
     */
    private function spending(): array
    {
        return [
            'rent'  => (int) $this->saveExpense([...$this->expenseData('3000.00', '0', 'Cash', $this->rent, $this->now()), 'treasury_id' => $this->drawer]),
            'other' => (int) $this->saveExpense([...$this->expenseData('230.00', '30.00', 'Cash', $this->other, $this->now()), 'treasury_id' => $this->drawer]),
            'small' => (int) $this->saveExpense([...$this->expenseData('100.00', '0', 'Cash', $this->other, $this->now()), 'treasury_id' => $this->till]),
        ];
    }

    public function testBuild_GroupsByTreasuryAndTotalsTheAmountTheTaxAndTheTotalWithTax(): void
    {
        $this->spending();

        $table = $this->built($this->report, [...$this->thisDay(), 'group' => 'treasury']);

        $this->assertCount(2, $this->rowsOf($table));
        $this->assertAmount('3300.00', $this->grandOf($table, 'amount'));
        $this->assertAmount('30.00', $this->grandOf($table, 'tax'));
        $this->assertAmount('3330.00', $this->grandOf($table, 'total'));

        $main = array_values(array_filter($this->rowsOf($table), static fn ($row): bool => str_starts_with($row->cell('group'), 'Main drawer')))[0];

        $this->assertAmount('3200.00', $main->cell('amount'));
        $this->assertAmount('3230.00', $main->cell('total'));
    }

    public function testBuild_GivesTheSameTotalInEveryGroupingAndView(): void
    {
        $this->spending();

        foreach (['category', 'user', 'vendor', 'treasury', 'day', 'week', 'month', 'year'] as $group) {
            foreach (['summary', 'detail'] as $view) {
                $table = $this->built($this->report, [...$this->thisDay(), 'group' => $group, 'view' => $view]);

                $this->assertAmount('3330.00', $this->grandOf($table, 'total'), "{$view} by {$group}");
            }
        }
    }

    public function testBuild_ListsTheDocumentsWithTheTreasuryTheCategoryAndTheAccountOfTheCategory(): void
    {
        $this->spending();

        $table = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail']);
        $rows  = $this->rowsOf($table);

        $this->assertCount(3, $rows);

        $rent = $this->rowWhere($table, 'total', '3000.0000');
        $tax  = $this->rowWhere($table, 'total', '230.0000');

        $this->assertNotNull($rent);
        $this->assertNotNull($tax);
        $this->assertStringStartsWith('Main drawer', $rent->cell('treasury'));
        $this->assertStringStartsWith('Rent', $rent->cell('category'));
        $this->assertAmount('200.00', $tax->cell('amount'));
        $this->assertAmount('30.00', $tax->cell('tax'));
        $this->assertStringStartsWith('541', $tax->cell('account'));
        $this->assertSame('Test expense', $tax->cell('note'));
    }

    public function testBuild_TiesTheTotalToTheJournalsThroughAnEditAndADelete(): void
    {
        $facts = $this->spending();

        $this->assertReportTies($this->built($this->report, $this->thisDay()));

        $this->saveExpense([...$this->expenseData('3500.00', '0', 'Cash', $this->rent, $this->now()), 'treasury_id' => $this->drawer], $facts['rent']);

        $edited = $this->built($this->report, $this->thisDay());

        $this->assertAmount('3830.00', $this->grandOf($edited, 'total'));
        $this->assertReportTies($edited);

        $this->assertTrue($this->expenses->delete($facts['small'], $this->clerkId));

        $deleted = $this->built($this->report, $this->thisDay());

        $this->assertAmount('3730.00', $this->grandOf($deleted, 'total'));
        $this->assertReportTies($deleted);
    }

    public function testBuild_SaysSoWhenAnExpenseHasNoJournalInTheBooks(): void
    {
        $facts = $this->spending();

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'expense')->where('source_id', $facts['small'])->update(['status' => 'reversed']);

        $table = $this->built($this->report, $this->thisDay());

        $this->assertFalse($table->ties());
        $this->assertAmount('100.00', $table->ties[0]->difference());
        $this->assertContains(lang('Accounting_family_reports.note_unposted', [1]), $table->notes);
    }

    public function testBuild_SaysSoWhenTheJournalOfAnExpenseHoldsAnotherAmountThanTheExpense(): void
    {
        $facts = $this->spending();

        $this->accountingDb()->table('expenses')->where('expense_id', $facts['small'])->update(['amount' => '130.00']);

        $table = $this->built($this->report, $this->thisDay());

        $this->assertFalse($table->ties());
        $this->assertAmount('30.00', $table->ties[0]->difference());
    }

    public function testBuild_ListsEveryPeriodOnceThereIsADocumentAndNoneWhenThereIsNot(): void
    {
        $this->spending();

        $days  = $this->built($this->report, ['group' => 'day', 'from' => date('Y-m-d', strtotime('-4 days')), 'to' => $this->today()]);
        $empty = $this->built($this->report, ['group' => 'week', 'from' => '2019-01-01', 'to' => '2019-02-01']);

        $this->assertCount(5, $this->rowsOf($days));
        $this->assertAmount('0.00', $this->rowsOf($days)[0]->cell('total'));
        $this->assertAmount('3330.00', $this->rowsOf($days)[4]->cell('total'));
        $this->assertSame([], $this->rowsOf($empty));
    }

    public function testBuild_CutsDownToATreasuryACategoryAndAVendor(): void
    {
        $this->spending();

        $till     = $this->built($this->report, [...$this->thisDay(), 'treasury_id' => (string) $this->till]);
        $category = $this->built($this->report, [...$this->thisDay(), 'category_id' => (string) $this->rent]);
        $vendor   = $this->built($this->report, [...$this->thisDay(), 'vendor' => 'nobody']);

        $this->assertAmount('100.00', $this->grandOf($till, 'total'));
        $this->assertAmount('3000.00', $this->grandOf($category, 'total'));
        $this->assertSame([], $this->rowsOf($vendor));
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndKeepsAnExpenseWithoutABranchForTheWholeCompany(): void
    {
        $this->spending();

        $branch = $this->createBranch('EX2', 'Expense branch');
        $actor  = $this->employeeWithGrants([], [$branch]);
        $till   = $this->treasury('Branch till', $branch);

        $this->assertNotNull($this->saveExpense([...$this->expenseData('55.00', '0', 'Cash', $this->other, $this->now(), $actor), 'treasury_id' => $till], NEW_ENTRY, $actor));

        $this->accountingDb()->table('expenses')->insert([...$this->expenseData('9.00', '0', 'Cash', $this->other, $this->now()), 'branch_id' => null, 'treasury_id' => null, 'treasury_branch_id' => null]);

        $all     = $this->built($this->report, $this->thisDay());
        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));
        $first   = $this->built($this->report, $this->thisDay(), $this->limitedTo(1));

        $this->assertAmount('3394.00', $this->grandOf($all, 'total'));
        $this->assertAmount('55.00', $this->grandOf($limited, 'total'));
        $this->assertAmount('3330.00', $this->grandOf($first, 'total'));
        $this->assertReportTies($limited);
    }

    public function testBuild_ReadsOnlyTheExpensesOfAViewerWhoSeesTheirOwnRecords(): void
    {
        $this->spending();

        $author = $this->employeeWithGrants([]);

        $this->assertNotNull($this->saveExpense([...$this->expenseData('40.00', '0', 'Cash', $this->other, $this->now(), $author), 'treasury_id' => $this->till], NEW_ENTRY, $author));

        (new RoleService())->setRecordScope($author, 'own', null);

        $own      = $this->built($this->report, $this->thisDay(), null, $author);
        $everyone = $this->built($this->report, $this->thisDay(), null, $this->clerkId);

        $this->assertAmount('40.00', $this->grandOf($own, 'total'));
        $this->assertAmount('3370.00', $this->grandOf($everyone, 'total'));
        $this->assertReportTies($own);
    }
}
