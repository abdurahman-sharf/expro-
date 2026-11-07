<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\IncomeReport;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Roles\RoleService;
use Tests\Support\CashReportTestCase;

/**
 * What was received by receipt voucher: by category, employee, treasury and period, as a summary or as
 * the documents, the total beside the journals that stand for the vouchers, and the branches an employee
 * may read.
 *
 * @internal
 */
final class IncomeReportTest extends CashReportTestCase
{
    private IncomeReport $report;
    private int $drawer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new IncomeReport();
        $this->drawer = $this->treasury('Income drawer');
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function voucher(array $changes = [], ?int $actor = null): int
    {
        return (new RevenueVouchers())->save([...['date' => $this->today(), 'amount' => '100.00', 'payment_type' => 'Cash', 'treasury_id' => $this->drawer, 'description' => 'Stand rent'], ...$changes], null, $actor ?? $this->clerkId);
    }

    private function revenueCategory(string $name): int
    {
        $this->accountingDb()->table('revenue_categories')->insert(['category_name' => $name, 'category_description' => '', 'deleted' => 0, 'account_id' => null]);

        return (int) $this->accountingDb()->insertID();
    }

    public function testBuild_GivesTheAmountTheTaxAndTheTotalOfEachVoucherAndGroupsByCategory(): void
    {
        $stands = $this->revenueCategory('Stands');
        $fees   = $this->revenueCategory('Fees');

        $this->voucher(['category_id' => $stands]);
        $this->voucher(['amount' => '115.00', 'tax_amount' => '15.00', 'category_id' => $fees]);

        $table = $this->built($this->report, $this->thisDay());

        $this->assertCount(2, $this->rowsOf($table));
        $this->assertAmount('215.00', $this->grandOf($table, 'total'));
        $this->assertAmount('200.00', $this->grandOf($table, 'amount'));
        $this->assertAmount('15.00', $this->grandOf($table, 'tax'));
        $this->assertAmount('100.00', $this->rowWhere($table, 'group', 'Fees')->cell('amount'));
    }

    public function testBuild_GivesTheSameTotalInEveryGroupingAndView(): void
    {
        $this->voucher();
        $this->voucher(['amount' => '40.00']);

        foreach (['category', 'user', 'treasury', 'day', 'week', 'month', 'year'] as $group) {
            foreach (['summary', 'detail'] as $view) {
                $this->assertAmount('140.00', $this->grandOf($this->built($this->report, [...$this->thisDay(), 'group' => $group, 'view' => $view]), 'total'), "{$view} by {$group}");
            }
        }
    }

    public function testBuild_TiesTheTotalToTheJournalsAndSaysSoWhenAVoucherHasNone(): void
    {
        $id = $this->voucher();
        $this->voucher(['amount' => '60.00']);

        $this->assertReportTies($this->built($this->report, $this->thisDay()));

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'revenue')->where('source_id', $id)->update(['status' => 'reversed']);

        $table = $this->built($this->report, $this->thisDay());

        $this->assertFalse($table->ties());
        $this->assertAmount('100.00', $table->ties[0]->difference());
    }

    public function testBuild_ListsTheTreasuryAndTheAccountOfEachVoucher(): void
    {
        $this->voucher();

        $row = $this->rowsOf($this->built($this->report, [...$this->thisDay(), 'view' => 'detail']))[0];

        $this->assertStringStartsWith('Income drawer', $row->cell('treasury'));
        $this->assertNotSame('', $row->cell('account'));
        $this->assertSame('Stand rent', $row->cell('note'));
        $this->assertSame('', $row->cell('vendor'));
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $this->voucher();

        $branch = $this->createBranch('IN2', 'Income branch');
        $actor  = $this->employeeWithGrants([], [$branch]);
        $till   = $this->treasury('Income branch till', $branch);

        $this->voucher(['amount' => '25.00', 'treasury_id' => $till], $actor);

        $this->assertAmount('125.00', $this->grandOf($this->built($this->report, $this->thisDay()), 'total'));
        $this->assertAmount('25.00', $this->grandOf($this->built($this->report, $this->thisDay(), $this->limitedTo($branch)), 'total'));
        $this->assertAmount('100.00', $this->grandOf($this->built($this->report, $this->thisDay(), $this->limitedTo(1)), 'total'));
    }

    public function testBuild_ReadsOnlyTheVouchersOfAViewerWhoSeesTheirOwnRecords(): void
    {
        $author = $this->employeeWithGrants(['receipt_vouchers']);

        $this->voucher(['amount' => '100.00']);
        $this->voucher(['amount' => '30.00'], $author);

        (new RoleService())->setRecordScope($author, 'own', null);

        $own      = $this->built($this->report, $this->thisDay(), null, $author);
        $everyone = $this->built($this->report, $this->thisDay(), null, $this->clerkId);

        $this->assertAmount('30.00', $this->grandOf($own, 'total'));
        $this->assertAmount('130.00', $this->grandOf($everyone, 'total'));
        $this->assertReportTies($own);
    }
}
