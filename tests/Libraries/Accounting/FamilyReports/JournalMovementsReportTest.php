<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\JournalMovementsReport;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CostCenterService;
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

    private function insertSingleLineJournals(int $from, int $count): void
    {
        $db      = $this->accountingDb();
        $account = $this->accountId('cash');
        $today   = $this->today();
        $stamp   = $today . ' 10:00:00';

        foreach (array_chunk(range($from + 1, $from + $count), 2000) as $chunk) {
            $db->table('accounting_journals')->insertBatch(array_map(static fn (int $number): array => [
                'series'        => 'bulk',
                'number'        => (string) $number,
                'journal_date'  => $today,
                'description'   => 'Bulk journal ' . $number,
                'status'        => 'posted',
                'source_type'   => 'manual',
                'entry_type'    => 'general',
                'origin'        => 'system',
                'currency_code' => 'SAR',
                'exchange_rate' => '1',
                'total_debit'   => '1',
                'total_credit'  => '1',
                'created_at'    => $stamp,
                'updated_at'    => $stamp,
                'company_id'    => 1,
                'branch_id'     => 1,
            ], $chunk), null, 2000);

            $firstId = (int) $db->insertID();

            $db->table('accounting_journal_lines')->insertBatch(array_map(static fn (int $offset): array => [
                'journal_id' => $firstId + $offset,
                'line_no'    => 1,
                'account_id' => $account,
                'debit'      => '1',
                'credit'     => '0',
            ], array_keys($chunk)), null, 2000);
        }
    }

    private function secondsToBuild(): float
    {
        $started = hrtime(true);

        $this->built($this->report, $this->thisDay());

        return (hrtime(true) - $started) / 1_000_000_000;
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

    public function testBuild_ReadsFourTimesTheJournalsInAboutFourTimesTheTime(): void
    {
        $this->insertSingleLineJournals(0, 10000);

        $first = $this->secondsToBuild();

        $this->insertSingleLineJournals(10000, 30000);

        $started = hrtime(true);
        $table   = $this->built($this->report, $this->thisDay());
        $second  = (hrtime(true) - $started) / 1_000_000_000;

        $this->assertGreaterThanOrEqual(40000, count($table->blocks));
        $this->assertLessThan(9.0, $second / max($first, 0.05), "Reading 40,000 journals took {$second} s against {$first} s for 10,000: the time grows faster than the journals do.");
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

    /**
     * A journal of 100 whose revenue line is split 60 and 40 between two centers, and one of 30 whose
     * revenue line is charged in full to the first.
     *
     * @return array{first: int, second: int, split: int, single: int}
     */
    private function chargedJournals(): array
    {
        $centers = new CostCenterService();
        $first   = $centers->create(['code' => 'A', 'name' => 'Alpha']);
        $second  = $centers->create(['code' => 'B', 'name' => 'Beta']);
        $service = new JournalService();
        $lines   = static fn (CostCenterService $unused, int $accountCash, int $accountRevenue, string $amount, array $charge): array => ['date' => date('Y-m-d'), 'description' => 'Charged', 'lines' => [
            ['account_id' => $accountCash, 'debit' => $amount],
            ['account_id' => $accountRevenue, 'credit' => $amount] + $charge,
        ]];
        $cash    = $this->accountId('cash');
        $revenue = $this->accountId('sales_revenue');
        $split   = $service->postNew($lines($centers, $cash, $revenue, '100.00', ['distribution' => [['cost_center_id' => $first, 'percent' => '60'], ['cost_center_id' => $second, 'percent' => '40']]]));
        $single  = $service->postNew($lines($centers, $cash, $revenue, '30.00', ['cost_center_id' => $first]));

        return ['first' => $first, 'second' => $second, 'split' => $split, 'single' => $single];
    }

    public function testBuild_SaysWhichCostCentersEachLineIsChargedTo(): void
    {
        $this->entries();
        $this->chargedJournals();

        $centers = array_values(array_filter($this->columnOf($this->built($this->report, $this->thisDay()), 'centers'), static fn (string $text): bool => $text !== ''));

        sort($centers);

        $this->assertSame(['A - Alpha', 'A - Alpha 60%; B - Beta 40%'], $centers);
    }

    public function testBuild_CutsDownToACostCenterWithItsSubCentersAndLeavesTheTieOut(): void
    {
        $ids = $this->chargedJournals();
        $sub = (new CostCenterService())->create(['code' => 'A1', 'name' => 'Alpha sub', 'parent_id' => $ids['first']]);

        (new JournalService())->postNew(['date' => date('Y-m-d'), 'description' => 'Sub', 'lines' => [
            ['account_id' => $this->accountId('cash'), 'debit' => '5.00'],
            ['account_id' => $this->accountId('sales_revenue'), 'credit' => '5.00', 'cost_center_id' => $sub],
        ]]);

        $first  = $this->built($this->report, [...$this->thisDay(), 'cost_center_id' => (string) $ids['first']]);
        $second = $this->built($this->report, [...$this->thisDay(), 'cost_center_id' => (string) $ids['second']]);

        $this->assertCount(3, $this->rowsOf($first));
        $this->assertAmount('135.00', $this->grandOf($first, 'credit'));
        $this->assertAmount('0.00', $this->grandOf($first, 'debit'));
        $this->assertSame([], $first->ties);
        $this->assertStringContainsString(lang('Accounting_family_reports.note_journal_narrowed'), implode(' ', $first->notes));
        $this->assertCount(1, $this->rowsOf($second));
        $this->assertAmount('100.00', $this->grandOf($second, 'credit'));
    }

    public function testBuild_CutsDownToTheLinesWithoutCostCentersAndLeavesTheTieOut(): void
    {
        $this->entries();
        $this->chargedJournals();

        $all   = $this->rowsOf($this->built($this->report, $this->thisDay()));
        $table = $this->built($this->report, [...$this->thisDay(), 'without_cost_center' => '1']);

        $this->assertCount(count($all) - 2, $this->rowsOf($table));
        $this->assertSame([], $table->ties);

        foreach ($this->columnOf($table, 'centers') as $text) {
            $this->assertSame('', $text);
        }
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
