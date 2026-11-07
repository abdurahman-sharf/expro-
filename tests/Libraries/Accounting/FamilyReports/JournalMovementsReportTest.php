<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\JournalMovementsReport;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingService;
use Tests\Support\FamilyReportTestCase;
use Tests\Support\ReportFixtureTrait;

/**
 * The lines of the journals of a period with the account, the words, the source, the debit and the
 * credit, grouped by journal, day, month, year or account, the debit and the credit coming to the same
 * and to the movement of the trial balance, a source filter that counts a reversal as the source it
 * reverses, and the branches and the journals an employee may read.
 *
 * @internal
 */
final class JournalMovementsReportTest extends FamilyReportTestCase
{
    use ReportFixtureTrait;

    private JournalMovementsReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new JournalMovementsReport();
    }

    private function manual(string $date, int $branchId, string $amount, string $description = 'Manual movement'): int
    {
        return (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [new JournalLineDraft($this->accountId('cash'), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: JournalDraft::SOURCE_MANUAL,
            description: $description,
            currencyCode: 'SAR',
            branchId: $branchId,
        ))->journalId;
    }

    /**
     * A purchase on credit of 230 and a manual journal of 40 between two accounts.
     *
     * @return array{purchase: int, manual: int}
     */
    private function entries(): array
    {
        return ['purchase' => $this->purchaseOf('230.00'), 'manual' => $this->manual($this->today(), 1, '40.00')];
    }

    public function testBuild_GroupsByJournalDayMonthYearAndAccountWithTheTotalsEqualOnBothSides(): void
    {
        $this->entries();

        $totals = [];

        foreach (JournalMovementsReport::GROUPS as $group) {
            $table = $this->built($this->report, [...$this->thisDay(), 'group' => $group]);

            $totals[$group] = $this->grandOf($table, 'debit');

            $this->assertAmount($this->grandOf($table, 'debit'), $this->grandOf($table, 'credit'), "by {$group}");
            $this->assertGreaterThan(0, count($table->blocks), "by {$group}");
        }

        $this->assertCount(1, array_unique($totals));
        $this->assertAmount('270.00', array_values($totals)[0]);
    }

    public function testBuild_GivesOneBlockForEachJournalWithItsLinesAndTotal(): void
    {
        $this->entries();

        $table = $this->built($this->report, $this->thisDay());

        foreach ($table->blocks as $block) {
            $this->assertNotNull($block->total);
            $this->assertAmount($block->total->cell('debit'), $block->total->cell('credit'));
        }

        $this->assertGreaterThanOrEqual(2, count($table->blocks));
    }

    public function testBuild_TiesTheDebitToTheMovementOfTheTrialBalance(): void
    {
        $this->entries();

        $table = $this->built($this->report, $this->thisDay());

        $this->assertCount(2, $table->ties);
        $this->assertSame(lang('Accounting_family_reports.tie_debit_credit'), $table->ties[0]->label);
        $this->assertSame(lang('Accounting_family_reports.tie_trial_balance'), $table->ties[1]->label);
        $this->assertAmount('270.00', $table->ties[1]->book);
        $this->assertReportTies($table);
    }

    public function testBuild_KeepsTheJournalsOfTheChosenSourceAndCountsAReversalAsTheSourceItReverses(): void
    {
        $ids = $this->entries();

        $only = $this->built($this->report, [...$this->thisDay(), 'source' => 'manual']);

        $this->assertAmount('40.00', $this->grandOf($only, 'debit'));
        $this->assertCount(1, $only->ties);

        (new JournalService())->reverse($ids['manual'], $this->today(), 'Mistake', $this->employeeWithGrants([AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL]));

        $reversed = $this->built($this->report, [...$this->thisDay(), 'source' => 'manual']);

        $this->assertAmount('80.00', $this->grandOf($reversed, 'debit'));
        $this->assertAmount('0.00', $this->grandOf($this->built($this->report, [...$this->thisDay(), 'source' => 'expense']), 'debit'));
        $this->assertAmount('40.00', $this->grandOf($this->built($this->report, [...$this->thisDay(), 'source' => 'reversal']), 'debit'));
    }

    public function testBuild_CutsDownToAnAccountAndTheAccountsUnderIt(): void
    {
        $this->entries();

        $account = $this->built($this->report, [...$this->thisDay(), 'account_id' => (string) $this->accountId('opening_balance')]);

        $this->assertAmount('0.00', $this->grandOf($account, 'debit'));
        $this->assertAmount('40.00', $this->grandOf($account, 'credit'));
        $this->assertCount(1, $this->rowsOf($account));
    }

    public function testBuild_TiesTheLinesOfAnAccountToWhatTheAccountHoldsInsteadOfAskingThemToBalance(): void
    {
        $ids = $this->entries();

        $table = $this->built($this->report, [...$this->thisDay(), 'account_id' => (string) $this->accountId('cash')]);

        $this->assertAmount('40.00', $this->grandOf($table, 'debit'));
        $this->assertAmount('0.00', $this->grandOf($table, 'credit'));
        $this->assertCount(1, $table->ties);
        $this->assertSame(lang('Accounting_family_reports.tie_account_movement'), $table->ties[0]->label);
        $this->assertReportTies($table);
        $this->assertAmount('40.00', $table->ties[0]->book);

        (new JournalService())->reverse($ids['manual'], $this->today(), 'Mistake', $this->employeeWithGrants([AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL]));

        $reversed = $this->built($this->report, [...$this->thisDay(), 'account_id' => (string) $this->accountId('cash')]);

        $this->assertAmount('0.00', $reversed->ties[0]->book);
        $this->assertReportTies($reversed);
    }

    public function testBuild_LeavesTheTieOutWhenTheAccountIsNarrowedBySourceToo(): void
    {
        $this->entries();

        $table = $this->built($this->report, [...$this->thisDay(), 'account_id' => (string) $this->accountId('cash'), 'source' => 'manual']);

        $this->assertAmount('40.00', $this->grandOf($table, 'debit'));
        $this->assertSame([], $table->ties);
        $this->assertStringContainsString(lang('Accounting_family_reports.note_journal_narrowed'), implode(' ', $table->notes));
    }

    public function testBuild_ListsTheJournalsInTheOrderOfTheirDayAndNumberNotOfTheTextOfTheirNumber(): void
    {
        $later   = $this->manual($this->today(), 1, '10.00', 'Booked first, dated later');
        $earlier = $this->manual(date('Y-m-d', strtotime('-1 day')), 1, '20.00', 'Booked second, dated earlier');

        $this->assertGreaterThan($later, $earlier);

        $table  = $this->built($this->report, ['from' => date('Y-m-d', strtotime('-1 day')), 'to' => $this->today()]);
        $titles = array_map(static fn ($block): string => (string) $block->title, $table->blocks);

        $this->assertCount(2, $titles);
        $this->assertStringContainsString('Booked second, dated earlier', $titles[0]);
        $this->assertStringContainsString('Booked first, dated later', $titles[1]);
    }

    public function testBuild_ListsTheAccountsByTheirCode(): void
    {
        $this->entries();

        $codes  = array_map(static fn ($block): string => (string) $block->title, $this->built($this->report, [...$this->thisDay(), 'group' => 'account'])->blocks);
        $sorted = $codes;

        sort($sorted, SORT_NATURAL);

        $this->assertGreaterThan(1, count($codes));
        $this->assertSame($sorted, $codes);
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndTheJournalsOfTheOwnerWhenGivenOne(): void
    {
        $this->entries();

        $branch = $this->createBranch('JM2', 'Journal branch');

        $this->manual($this->today(), $branch, '15.00', 'Branch movement');

        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));
        $none    = $this->built($this->report, $this->thisDay(), null, null, 999999);

        $this->assertAmount('15.00', $this->grandOf($limited, 'debit'));
        $this->assertReportTies($limited);
        $this->assertSame([], $this->rowsOf($none));
        $this->assertCount(1, $none->ties);
    }
}
