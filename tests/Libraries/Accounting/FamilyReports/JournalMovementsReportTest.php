<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\JournalMovementsReport;
use App\Libraries\Accounting\FamilyReports\JournalSources;
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

    public function testSources_ListsThoseOfTheBooksWithAName(): void
    {
        $sources = JournalSources::all();

        $this->assertContains('manual', $sources);
        $this->assertContains('purchase', $sources);
        $this->assertContains('expense', $sources);
        $this->assertContains('reversal', $sources);
        $this->assertSame(lang('Accounting.source_expense'), JournalSources::label('expense'));
        $this->assertSame('made_up', JournalSources::label('made_up'));
    }
}
