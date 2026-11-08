<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\FamilyReports\CostCenterReport;
use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use App\Libraries\Accounting\FamilyReports\ReportTable;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalSettings;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\YearEndEntries;
use Tests\Support\FamilyReportTestCase;

/**
 * What the lines of the books come to for each cost center: by center, main center, account, day,
 * week, month and year, the centers and the lines with none adding up to the trial balance, a
 * distribution over 100 percent shown as a row of its own, and the branches and the journals an
 * employee may read.
 *
 * @internal
 */
final class CostCenterReportTest extends FamilyReportTestCase
{
    private CostCenterReport $report;
    private CostCenterService $centers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resetJournalSettings();
        $this->report  = new CostCenterReport();
        $this->centers = new CostCenterService();
    }

    /**
     * @param list<array<string, mixed>>|null $distribution
     */
    private function journal(string $amount = '100.00', ?array $distribution = null, ?int $center = null, ?string $date = null, ?int $branchId = null, ?int $employee = null): int
    {
        $input = ['date' => $date ?? $this->today(), 'description' => 'Cost center journal', 'lines' => [
            ['account_id' => $this->accountId('cash'), 'debit' => $amount, 'credit' => ''],
            ['account_id' => $this->accountId('sales_revenue'), 'debit' => '', 'credit' => $amount],
        ]];

        if ($distribution !== null) {
            $input['lines'][1]['distribution'] = $distribution;
        }

        if ($center !== null) {
            $input['lines'][1]['cost_center_id'] = $center;
        }

        if ($branchId !== null) {
            $input['branch_id'] = $branchId;
        }

        return (new JournalService())->postNew($input, $employee);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function twoCenters(): array
    {
        return [$this->centers->create(['code' => 'A', 'name' => 'Alpha']), $this->centers->create(['code' => 'B', 'name' => 'Beta'])];
    }

    /**
     * @return array<string, string> The credit of each cost center row by the label of its center.
     */
    private function creditsByCenter(ReportTable $table): array
    {
        $found = [];

        foreach ($this->rowsOf($table) as $row) {
            $found[$row->cell('center')] = bcadd($found[$row->cell('center')] ?? '0', $row->cell('credit'), 4);
        }

        return $found;
    }

    public function testBuild_AddsUpTheSharesOfADistributionForEachCenter(): void
    {
        [$a, $b] = $this->twoCenters();

        $this->journal('100.00', [['cost_center_id' => $a, 'percent' => '60'], ['cost_center_id' => $b, 'percent' => '40']]);

        $credits = $this->creditsByCenter($this->built($this->report, $this->thisDay()));

        $this->assertAmount('60.00', $credits['A - Alpha']);
        $this->assertAmount('40.00', $credits['B - Beta']);
        $this->assertAmount('0.00', $credits[lang('Accounting_family_reports.cost_center_none')]);
    }

    public function testBuild_CountsALineWithOnlyASingleCenterInFullAndALineWithNoneUnderNoCostCenter(): void
    {
        [$a] = $this->twoCenters();

        $this->journal('50.00', null, $a);
        $this->journal('20.00');

        $table   = $this->built($this->report, $this->thisDay());
        $credits = $this->creditsByCenter($table);
        $none    = lang('Accounting_family_reports.cost_center_none');

        $this->assertAmount('50.00', $credits['A - Alpha']);
        $this->assertAmount('20.00', $credits[$none]);
        $this->assertSame($none, $table->blocks[array_key_last($table->blocks)]->title);
    }

    public function testBuild_PutsTheLinesWithNoCostCenterLastWhateverTheCodeOfACenter(): void
    {
        $late  = $this->centers->create(['code' => 'zzzzzz', 'name' => 'Late']);
        $early = $this->centers->create(['code' => '0', 'name' => 'Early']);

        $this->journal('30.00', null, $late);
        $this->journal('20.00', null, $early);
        $this->journal('10.00');

        $titles = array_map(static fn (object $block): string => $block->title, $this->built($this->report, $this->thisDay())->blocks);

        $this->assertSame(['0 - Early', 'zzzzzz - Late', lang('Accounting_family_reports.cost_center_none')], $titles);
    }

    public function testBuild_TiesTheCentersAndTheLinesWithNoneToTheTrialBalance(): void
    {
        [$a, $b] = $this->twoCenters();

        $this->journal('99.99', [['cost_center_id' => $a, 'percent' => '33.3333'], ['cost_center_id' => $b, 'percent' => '66.6667']]);
        $this->journal('10.01', null, $a);
        $this->journal('7.00');

        $table = $this->built($this->report, $this->thisDay());

        $this->assertCount(2, $table->ties);
        $this->assertSame(lang('Accounting_family_reports.tie_cc_debit'), $table->ties[0]->label);
        $this->assertSame(lang('Accounting_family_reports.tie_cc_credit'), $table->ties[1]->label);
        $this->assertAmount('117.00', $table->ties[0]->book);
        $this->assertAmount('117.00', $table->ties[1]->book);
        $this->assertReportTies($table);
    }

    public function testBuild_SumsACenterToTheDistributedLinesWhateverTheRounding(): void
    {
        [$a, $b] = $this->twoCenters();
        $c       = $this->centers->create(['code' => 'C', 'name' => 'Gamma']);

        $this->journal('100.00', [['cost_center_id' => $a, 'percent' => '33.3333'], ['cost_center_id' => $b, 'percent' => '33.3333'], ['cost_center_id' => $c, 'percent' => '33.3334']]);

        $credits = $this->creditsByCenter($this->built($this->report, $this->thisDay()));

        $this->assertAmount('33.33', $credits['A - Alpha']);
        $this->assertAmount('33.33', $credits['B - Beta']);
        $this->assertAmount('33.34', $credits['C - Gamma']);
    }

    public function testBuild_ShowsTheExcessOfADistributionOverOneHundredAsARowOfItsOwnAndStillTies(): void
    {
        [$a, $b] = $this->twoCenters();
        $this->setConfigValue(JournalSettings::OVER_100, '1');

        $this->journal('100.00', [['cost_center_id' => $a, 'percent' => '100'], ['cost_center_id' => $b, 'percent' => '50']]);

        $table  = $this->built($this->report, $this->thisDay());
        $credit = $this->creditsByCenter($table);
        $excess = lang('Accounting_family_reports.cost_center_excess');

        $this->assertAmount('100.00', $credit['A - Alpha']);
        $this->assertAmount('50.00', $credit['B - Beta']);
        $this->assertAmount('-50.00', $credit[$excess]);
        $this->assertAmount('100.00', $this->grandOf($table, 'credit'));
        $this->assertReportTies($table);
        $this->assertStringContainsString(lang('Accounting_family_reports.note_cc_excess'), implode(' ', $table->notes));
    }

    public function testBuild_GroupsASubCenterUnderItsMainCenter(): void
    {
        $main = $this->centers->create(['code' => 'M', 'name' => 'Main']);
        $one  = $this->centers->create(['code' => 'M1', 'name' => 'First sub', 'parent_id' => $main]);
        $two  = $this->centers->create(['code' => 'M2', 'name' => 'Second sub', 'parent_id' => $main]);
        $solo = $this->centers->create(['code' => 'S', 'name' => 'Solo']);

        $this->journal('100.00', [['cost_center_id' => $one, 'percent' => '25'], ['cost_center_id' => $two, 'percent' => '25'], ['cost_center_id' => $solo, 'percent' => '50']]);

        $byMain = $this->built($this->report, [...$this->thisDay(), 'group' => 'main']);
        $titles = array_map(static fn ($block): string => (string) $block->title, $byMain->blocks);

        $this->assertSame(['M - Main', 'S - Solo'], array_slice($titles, 0, 2));
        $this->assertAmount('50.00', $byMain->blocks[0]->total->cell('credit'));
        $this->assertAmount('50.00', $byMain->blocks[1]->total->cell('credit'));
        $this->assertReportTies($byMain);

        $byCenter = $this->built($this->report, [...$this->thisDay(), 'group' => 'center']);
        $sub      = $this->rowWhere($byCenter, 'center', 'M1 - First sub');

        $this->assertNotNull($sub);
        $this->assertSame('M - Main', $sub->cell('main'));
        $this->assertSame('', $this->rowWhere($byCenter, 'center', 'S - Solo')->cell('main'));
    }

    public function testBuild_GroupsByAccountDayWeekMonthAndYearWithTheSameGrandTotals(): void
    {
        [$a, $b] = $this->twoCenters();
        $first   = date('Y-m-d', strtotime('-9 days'));

        $this->journal('100.00', [['cost_center_id' => $a, 'percent' => '70'], ['cost_center_id' => $b, 'percent' => '30']], null, $first);
        $this->journal('40.00', null, $a, $this->today());

        $totals = [];

        foreach (CostCenterReport::GROUPS as $group) {
            $table = $this->built($this->report, ['group' => $group, 'from' => $first, 'to' => $this->today()]);

            $totals[$group] = $this->grandOf($table, 'credit');

            $this->assertAmount('140.00', $totals[$group], $group);
            $this->assertReportTies($table);
        }

        $byDay = $this->built($this->report, ['group' => 'day', 'from' => $first, 'to' => $this->today()]);

        $this->assertSame([$first, $this->today()], array_map(static fn ($block): string => (string) $block->title, $byDay->blocks));

        $byAccount = $this->built($this->report, ['group' => 'account', 'from' => $first, 'to' => $this->today()]);

        $this->assertCount(2, $byAccount->blocks);
    }

    public function testBuild_StartsAWeekOnMondayAndAMonthOnItsFirstDay(): void
    {
        [$a] = $this->twoCenters();

        $this->journal('10.00', null, $a, '2026-10-21');
        $this->journal('20.00', null, $a, '2026-10-26');
        $this->journal('30.00', null, $a, '2026-11-02');

        $weeks  = $this->built($this->report, ['group' => 'week', 'from' => '2026-10-01', 'to' => '2026-11-30']);
        $months = $this->built($this->report, ['group' => 'month', 'from' => '2026-10-01', 'to' => '2026-11-30']);
        $years  = $this->built($this->report, ['group' => 'year', 'from' => '2026-10-01', 'to' => '2026-11-30']);

        $this->assertSame(['2026-10-19 - 2026-10-25', '2026-10-26 - 2026-11-01', '2026-11-02 - 2026-11-08'], array_map(static fn ($block): string => (string) $block->title, $weeks->blocks));
        $this->assertSame(['10.0000', '20.0000', '30.0000'], array_map(static fn ($block): string => $block->total->cell('credit'), $weeks->blocks));
        $this->assertCount(2, $months->blocks);
        $this->assertAmount('30.00', $months->blocks[0]->total->cell('credit'));
        $this->assertCount(1, $years->blocks);
        $this->assertSame('2026', $years->blocks[0]->title);
    }

    public function testBuild_ReadsOnlyTheDaysOfThePeriod(): void
    {
        [$a] = $this->twoCenters();

        $this->journal('10.00', null, $a, '2026-10-01');
        $this->journal('20.00', null, $a, '2026-10-15');
        $this->journal('40.00', null, $a, '2026-10-31');

        $inside = $this->built($this->report, ['from' => '2026-10-10', 'to' => '2026-10-20']);
        $untilThen = $this->built($this->report, ['to' => '2026-10-20']);

        $this->assertAmount('20.00', $this->grandOf($inside, 'credit'));
        $this->assertAmount('30.00', $this->grandOf($untilThen, 'credit'));
        $this->assertReportTies($inside);
        $this->assertReportTies($untilThen);
    }

    public function testBuild_CutsDownToACenterWithItsSubCentersAndLeavesTheTieOut(): void
    {
        $main = $this->centers->create(['code' => 'M', 'name' => 'Main']);
        $sub  = $this->centers->create(['code' => 'M1', 'name' => 'Sub', 'parent_id' => $main]);
        $solo = $this->centers->create(['code' => 'S', 'name' => 'Solo']);

        $this->journal('100.00', [['cost_center_id' => $sub, 'percent' => '40'], ['cost_center_id' => $solo, 'percent' => '60']]);
        $this->journal('10.00', null, $main);

        $table = $this->built($this->report, [...$this->thisDay(), 'cost_center_id' => (string) $main]);

        $this->assertAmount('50.00', $this->grandOf($table, 'credit'));
        $this->assertSame([], $table->ties);
        $this->assertStringContainsString(lang('Accounting_family_reports.note_cc_narrowed'), implode(' ', $table->notes));
        $this->assertAmount('60.00', $this->grandOf($this->built($this->report, [...$this->thisDay(), 'cost_center_id' => (string) $solo]), 'credit'));
    }

    public function testBuild_ReadsOnlyTheLinesWithoutCostCentersWhenAsked(): void
    {
        [$a] = $this->twoCenters();

        $this->journal('100.00', null, $a);
        $this->journal('25.00');

        $table = $this->built($this->report, [...$this->thisDay(), 'without_cost_center' => '1']);

        $this->assertAmount('25.00', $this->grandOf($table, 'credit'));
        $this->assertAmount('125.00', $this->grandOf($table, 'debit'));
        $this->assertCount(1, $table->blocks);
        $this->assertSame([], $table->ties);
    }

    public function testBuild_CutsDownToAnAccountAndTiesItToWhatTheAccountHolds(): void
    {
        [$a, $b] = $this->twoCenters();

        $this->journal('100.00', [['cost_center_id' => $a, 'percent' => '60'], ['cost_center_id' => $b, 'percent' => '40']]);

        $table = $this->built($this->report, [...$this->thisDay(), 'account_id' => (string) $this->accountId('sales_revenue')]);

        $this->assertAmount('100.00', $this->grandOf($table, 'credit'));
        $this->assertCount(1, $table->ties);
        $this->assertSame(lang('Accounting_family_reports.tie_cc_account'), $table->ties[0]->label);
        $this->assertAmount('-100.00', $table->ties[0]->book);
        $this->assertReportTies($table);
    }

    public function testBuild_LeavesTheJournalsThatCloseAYearOutAsTheTrialBalanceDoesAndStillTies(): void
    {
        [$a] = $this->twoCenters();

        $this->journal('100.00', null, $a);

        $closing = (new PostingService())->postYearEnd(new JournalDraft(
            date: $this->today(),
            lines: [new JournalLineDraft($this->accountId('sales_revenue'), debit: '100.00'), new JournalLineDraft($this->accountId('retained_earnings'), credit: '100.00')],
            sourceType: YearEndEntries::SOURCE_TYPE,
            sourceId: 900001,
            currencyCode: 'SAR',
            entryType: JournalEntryType::YearEnd,
        ))->journalId;

        (new PostingService())->postYearEnd(new JournalDraft(
            date: $this->today(),
            lines: [new JournalLineDraft($this->accountId('sales_revenue'), credit: '100.00'), new JournalLineDraft($this->accountId('retained_earnings'), debit: '100.00')],
            sourceType: JournalDraft::SOURCE_REVERSAL,
            sourceId: $closing,
            currencyCode: 'SAR',
            reversesJournalId: $closing,
            entryType: JournalEntryType::YearEnd,
        ));

        $whole   = $this->built($this->report, $this->thisDay());
        $revenue = $this->built($this->report, [...$this->thisDay(), 'account_id' => (string) $this->accountId('sales_revenue')]);

        $this->assertAmount('100.00', $this->grandOf($whole, 'credit'));
        $this->assertAmount('100.00', $this->grandOf($whole, 'debit'));
        $this->assertAmount('100.00', $this->grandOf($revenue, 'credit'));
        $this->assertAmount('0.00', $this->grandOf($revenue, 'debit'));
        $this->assertReportTies($whole);
        $this->assertReportTies($revenue);
    }

    public function testBuild_TakesTheReversalOfADistributedJournalBackToZeroForEveryCenter(): void
    {
        [$a, $b] = $this->twoCenters();
        $id      = $this->journal('100.00', [['cost_center_id' => $a, 'percent' => '60'], ['cost_center_id' => $b, 'percent' => '40']]);
        $this->journal('10.00', null, $a);

        (new JournalService())->reverse($id, $this->today(), 'Mistake', $this->employeeWithGrants([AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL]));

        $table = $this->built($this->report, $this->thisDay());

        $this->assertAmount('-10.00', $this->balanceOf($table, 'A - Alpha'));
        $this->assertAmount('0.00', $this->balanceOf($table, 'B - Beta'));
        $this->assertReportTies($table);
    }

    public function testBuild_LeavesADraftOut(): void
    {
        [$a] = $this->twoCenters();

        $input                                = ['date' => $this->today(), 'description' => 'Draft', 'lines' => [
            ['account_id' => $this->accountId('cash'), 'debit' => '10.00'],
            ['account_id' => $this->accountId('sales_revenue'), 'credit' => '10.00', 'cost_center_id' => $a],
        ]];

        (new JournalService())->saveDraft($input);

        $this->assertSame([], $this->rowsOf($this->built($this->report, $this->thisDay())));
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        [$a]    = $this->twoCenters();
        $branch = $this->createBranch('CC2', 'Second branch');

        $this->journal('100.00', null, $a);
        $this->journal('30.00', null, $a, null, $branch);

        $whole   = $this->built($this->report, $this->thisDay());
        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo(1));
        $second  = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));
        $nobody  = $this->built($this->report, $this->thisDay(), $this->limitedTo());

        $this->assertAmount('130.00', $this->grandOf($whole, 'credit'));
        $this->assertAmount('100.00', $this->grandOf($limited, 'credit'));
        $this->assertAmount('30.00', $this->grandOf($second, 'credit'));
        $this->assertSame([], $this->rowsOf($nobody));
        $this->assertReportTies($limited);
        $this->assertReportTies($second);
    }

    public function testBuild_ReadsOnlyTheJournalsOfTheOwnerWhenGivenOneAndLeavesTheTieOut(): void
    {
        [$a]  = $this->twoCenters();
        $mine = $this->employeeWithGrants([AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_POST]);

        $this->journal('100.00', null, $a);
        $this->journal('15.00', null, $a, null, null, $mine);

        $table = $this->built($this->report, $this->thisDay(), null, $mine, $mine);

        $this->assertAmount('15.00', $this->grandOf($table, 'credit'));
        $this->assertSame([], $table->ties);
    }

    public function testBuild_RejectsAPeriodThatEndsBeforeItStarts(): void
    {
        try {
            $this->built($this->report, ['from' => '2026-10-10', 'to' => '2026-10-01']);
            $this->fail('A period that ends before it starts was accepted.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::ReportPeriodInvalid, $exception->error);
        }
    }

    public function testBuild_IsListedUnderTheBooksWithThePermissionOfTheCostCenters(): void
    {
        $definition = FamilyReportCatalog::find('cost_centers');

        $this->assertSame('accounting_cost_centers', $definition['permission']);
        $this->assertSame('books', $definition['family']);
        $this->assertSame(CostCenterReport::class, $definition['class']);
    }

    private function balanceOf(ReportTable $table, string $center): string
    {
        $net = '0';

        foreach ($this->rowsOf($table) as $row) {
            if ($row->cell('center') === $center) {
                $net = bcadd($net, $row->cell('balance'), 4);
            }
        }

        return $net;
    }
}
