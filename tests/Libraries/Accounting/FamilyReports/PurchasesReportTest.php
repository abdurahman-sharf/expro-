<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\FamilyReports\PurchasesReport;
use App\Libraries\Roles\RoleService;
use Tests\Support\FamilyReportTestCase;

/**
 * What was bought: the invoices less the returns, by supplier, employee and period, as a summary or as
 * documents, with the status of each purchase, and that every way of grouping gives the same net
 * purchases, which are what the journals of the purchases hold.
 *
 * @internal
 */
final class PurchasesReportTest extends FamilyReportTestCase
{
    private PurchasesReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new PurchasesReport();
    }

    public function testBuild_SummarisesBySupplierWithTheInvoicesLessTheReturnsAndDebitNotes(): void
    {
        $this->market();

        $table = $this->built($this->report, $this->thisDay());

        $first = $this->rowWhere($table, 'group', 'Gulf Supplies');

        $this->assertNotNull($first);
        $this->assertAmount('345.00', $first->cell('invoices'));
        $this->assertAmount('50.00', $first->cell('returns'));
        $this->assertAmount('295.00', $first->cell('net'));
        $this->assertAmount('100.00', $this->rowWhere($table, 'group', 'Najd Trading')->cell('net'));
        $this->assertAmount('395.00', $this->grandOf($table, 'net'));
        $this->assertAmount('445.00', $this->grandOf($table, 'invoices'));
        $this->assertAmount('50.00', $this->grandOf($table, 'returns'));
    }

    public function testBuild_GivesTheSameNetPurchasesInEveryGroupingAndView(): void
    {
        $this->market();

        foreach (PurchasesReport::GROUPS as $group) {
            $summary = $this->built($this->report, [...$this->thisDay(), 'group' => $group]);
            $detail  = $this->built($this->report, [...$this->thisDay(), 'group' => $group, 'view' => 'detail']);

            $this->assertAmount('395.00', $this->grandOf($summary, 'net'), "summary by {$group}");
            $this->assertAmount('395.00', $this->grandOf($detail, 'total'), "detail by {$group}");
        }
    }

    public function testBuild_RejectsTheCheckAgainstTheBooksByNameWhenAPurchaseIsInAForeignCurrency(): void
    {
        $facts = $this->market();

        (new Currencies())->setActive('USD', true);
        $this->accountingDb()->table('receivings')->where('receiving_id', $facts['other'])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $table = $this->built($this->report, $this->thisDay());

        $this->assertCount(1, $table->ties);
        $this->assertTrue($table->ties[0]->isRefused());
        $this->assertSame(lang('Accounting_family_reports.tie_foreign_currency', ['RECV ' . $facts['other'] . ' (USD)']), $table->ties[0]->refusal);
        $this->assertSame(lang('Accounting_family_reports.tie_net_purchases'), $table->ties[0]->label);
    }

    public function testBuild_TiesTheNetPurchasesToTheJournalsOfThePurchases(): void
    {
        $this->market();

        $this->assertReportTies($this->built($this->report, $this->thisDay()));
    }

    public function testBuild_SaysSoWhenAPurchaseHasNoJournalInTheBooks(): void
    {
        $ids = $this->market();

        $this->accountingDb()->table('accounting_journals')->where('source_type', 'purchase')->where('source_id', $ids['other'])->update(['status' => 'reversed']);

        $table = $this->built($this->report, $this->thisDay());

        $this->assertFalse($table->ties());
        $this->assertAmount('100.00', $table->ties[0]->difference());
        $this->assertStringContainsString('1', implode(' ', $table->notes));
    }

    public function testBuild_ListsEveryDayOfTheRangeIncludingTheEmptyOnesWithZeros(): void
    {
        $this->market();

        $from  = date('Y-m-d', strtotime('-5 days'));
        $table = $this->built($this->report, ['group' => 'day', 'from' => $from, 'to' => $this->today()]);

        $this->assertCount(6, $this->rowsOf($table));
        $this->assertAmount('0.00', $this->rowsOf($table)[0]->cell('net'));
        $this->assertAmount('395.00', $this->rowsOf($table)[5]->cell('net'));
        $this->assertSame($from, $this->rowsOf($table)[0]->cell('group'));
    }

    public function testBuild_ListsNoPeriodsWhenTheRangeHoldsNoDocument(): void
    {
        $table = $this->built($this->report, ['group' => 'month', 'from' => '2019-01-01', 'to' => '2019-12-31']);

        $this->assertSame([], $this->rowsOf($table));
        $this->assertSame(0, $table->rowCount());
    }

    public function testBuild_StartsTheWeekOnMondayAndNamesTheMonth(): void
    {
        $this->market();

        $week  = $this->built($this->report, ['group' => 'week', 'from' => $this->today(), 'to' => $this->today()]);
        $month = $this->built($this->report, ['group' => 'month', 'from' => $this->today(), 'to' => $this->today()]);

        $monday = date('Y-m-d', strtotime('monday this week', strtotime($this->today())));

        $this->assertStringStartsWith($monday, $this->rowsOf($week)[0]->cell('group'));
        $this->assertStringContainsString(date('Y'), $this->rowsOf($month)[0]->cell('group'));
        $this->assertNotSame(date('Y-m'), $this->rowsOf($month)[0]->cell('group'));
    }

    public function testBuild_FiltersByThePaymentStateOfThePurchases(): void
    {
        $ids = $this->market();

        $this->payPurchase($ids['big'], '230.00');
        $this->payPurchase($ids['small'], '40.00');

        $paid    = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail', 'status' => 'paid']);
        $partial = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail', 'status' => 'partial']);
        $unpaid  = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail', 'status' => 'unpaid']);
        $returns = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail', 'status' => 'returned']);

        $this->assertCount(1, $this->rowsOf($paid));
        $this->assertAmount('230.00', $this->rowsOf($paid)[0]->cell('paid'));
        $this->assertCount(1, $this->rowsOf($partial));
        $this->assertAmount('25.00', $this->rowsOf($partial)[0]->cell('open'));
        $this->assertAmount('90.00', $this->rowsOf($partial)[0]->cell('paid'));
        $this->assertCount(1, $this->rowsOf($unpaid));
        $this->assertAmount('100.00', $this->rowsOf($unpaid)[0]->cell('open'));
        $this->assertCount(1, $this->rowsOf($returns));
        $this->assertAmount('-50.00', $this->rowsOf($returns)[0]->cell('total'));
        $this->assertSame([], $paid->ties);
    }

    public function testBuild_CutsDownToOneSupplier(): void
    {
        $ids = $this->market();

        $table = $this->built($this->report, [...$this->thisDay(), 'supplier_id' => (string) $ids['second']]);

        $this->assertAmount('100.00', $this->grandOf($table, 'net'));
        $this->assertCount(1, $this->rowsOf($table));
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $this->market();

        $branch   = $this->createBranch('P2', 'Purchase branch');
        $location = $this->locationOfBranch($branch);
        $item     = $this->item('0.00');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '60.00', '0', '0', PERCENT, $location)]));

        $all     = $this->built($this->report, $this->thisDay());
        $second  = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));
        $first   = $this->built($this->report, $this->thisDay(), $this->limitedTo(1));
        $nothing = $this->built($this->report, $this->thisDay(), $this->limitedTo());

        $this->assertAmount('455.00', $this->grandOf($all, 'net'));
        $this->assertAmount('60.00', $this->grandOf($second, 'net'));
        $this->assertAmount('395.00', $this->grandOf($first, 'net'));
        $this->assertSame([], $this->rowsOf($nothing));
        $this->assertReportTies($second);
        $this->assertReportTies($first);
    }

    public function testBuild_ReadsOnlyThePurchasesOfAViewerWhoSeesTheirOwnRecords(): void
    {
        $this->market();

        $author = $this->employeeId;
        $item   = $this->item('0.00');

        $this->workInEveryBranch();

        $other = $this->employeeId;

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '1', '60.00')]));

        (new RoleService())->setRecordScope($author, 'own', null);

        $own      = $this->built($this->report, $this->thisDay(), null, $author);
        $everyone = $this->built($this->report, $this->thisDay(), null, $other);

        $this->assertAmount('395.00', $this->grandOf($own, 'net'));
        $this->assertAmount('455.00', $this->grandOf($everyone, 'net'));
        $this->assertReportTies($own);
        $this->assertReportTies($everyone);
    }

    public function testBuild_RefusesAPeriodThatEndsBeforeItStartsAndADayThatIsNotOne(): void
    {
        foreach ([['from' => '2026-02-01', 'to' => '2026-01-01', 'code' => AccountingError::ReportPeriodInvalid], ['from' => '2026-13-45', 'to' => '2026-12-01', 'code' => AccountingError::InvalidDate]] as $case) {
            try {
                $this->built($this->report, ['from' => $case['from'], 'to' => $case['to']]);
                $this->fail('The period was accepted');
            } catch (AccountingException $refusal) {
                $this->assertSame($case['code'], $refusal->error);
            }
        }
    }

    public function testBuild_RefusesARangeWithMoreDaysThanAScreenCanHold(): void
    {
        $this->market();

        $this->expectException(AccountingException::class);

        $this->built($this->report, ['group' => 'day', 'from' => '2000-01-01', 'to' => $this->today()]);
    }

    public function testBuild_NamesThePurchaseInDollarsPostedByTheModelWhenTheCheckIsNotRun(): void
    {
        $this->enableDollars();
        $id = $this->foreignPurchase();

        $this->assertGreaterThan(0, $id);

        $table = $this->built($this->report, ['from' => $this->ago(30), 'to' => $this->today()]);

        $this->assertCount(1, $table->ties);
        $this->assertTrue($table->ties[0]->isRefused());
        $this->assertFalse($table->ties[0]->ties());
        $this->assertSame(lang('Accounting_family_reports.tie_foreign_currency', ['RECV ' . $id . ' (USD)']), $table->ties[0]->refusal);
    }

    public function testBuild_RunsTheCheckWhenTheDaysHoldNoPurchaseInDollars(): void
    {
        $this->enableDollars();
        $this->assertGreaterThan(0, $this->foreignPurchase());
        $this->assertGreaterThan(0, $this->purchaseOf('100.00'));

        $table = $this->built($this->report, ['from' => $this->ago(5), 'to' => $this->today()]);

        $this->assertCount(1, $table->ties);
        $this->assertFalse($table->ties[0]->isRefused());
        $this->assertTrue($table->ties[0]->ties());
    }
}
