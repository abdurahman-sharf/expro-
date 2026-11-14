<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\JournalLines;
use App\Libraries\Accounting\FamilyReports\JournalsReport;
use App\Libraries\Accounting\FamilyReports\ReportCsv;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use Tests\Support\FamilyReportTestCase;
use Tests\Support\LegacyFamilyReports\LegacyJournalsReport;

/**
 * The journals of a period, each once, with its date, number, words, source and what it debits, the
 * total beside the movement of the trial balance, and the branches and the journals an employee may read.
 *
 * @internal
 */
final class JournalsReportTest extends FamilyReportTestCase
{
    private JournalsReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new JournalsReport();
    }

    private function manual(int $branchId, string $amount, string $description, ?int $employeeId = null, string $source = JournalDraft::SOURCE_MANUAL, ?int $sourceId = null): int
    {
        return (new PostingService())->post(new JournalDraft(
            date: $this->today(),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: $source,
            sourceId: $sourceId,
            description: $description,
            currencyCode: 'SAR',
            branchId: $branchId,
            employeeId: $employeeId,
        ))->journalId;
    }

    public function testBuild_ListsEachJournalOnceWithItsDebitTotalAndTheTotalsTieToTheTrialBalance(): void
    {
        $this->purchaseOf('230.00');
        $this->manual(1, '40.00', 'Opening cash');

        $table = $this->built($this->report, $this->thisDay());
        $rows  = $this->rowsOf($table);

        $this->assertGreaterThanOrEqual(2, count($rows));
        $this->assertCount(count($rows), array_unique(array_map(static fn ($row): string => $row->cell('number') . '|' . $row->cell('date') . '|' . $row->cell('description'), $rows)));
        $this->assertAmount('270.00', $this->grandOf($table, 'debit'));
        $this->assertReportTies($table);
        $this->assertStringContainsString('/accounting_journals/view/', (string) $rows[0]->url);
    }

    public function testBuild_NamesTheSourceOfEachJournalAndFiltersBySourceAndWords(): void
    {
        $this->purchaseOf('230.00');
        $this->manual(1, '40.00', 'Opening cash');

        $manual = $this->built($this->report, [...$this->thisDay(), 'source' => 'manual']);
        $words  = $this->built($this->report, [...$this->thisDay(), 'search' => 'Opening']);

        $this->assertCount(1, $this->rowsOf($manual));
        $this->assertSame(lang('Accounting.source_manual'), $this->rowsOf($manual)[0]->cell('source'));
        $this->assertAmount('40.00', $this->grandOf($manual, 'debit'));
        $this->assertCount(1, $this->rowsOf($words));
        $this->assertCount(1, $manual->ties === [] ? [1] : []);
    }

    public function testBuild_NarrowsToEachSourceOfTheStockDocumentsAndTheExchangeDifferences(): void
    {
        $this->manual(1, '40.00', 'By hand');

        foreach (['stock_permit' => '11.00', 'stock_transfer_permit' => '12.00', 'stock_transfer_permit_receipt' => '13.00', 'stocktake' => '14.00', 'sale_payment_fx' => '15.00', 'supplier_payment_fx' => '16.00'] as $source => $amount) {
            $this->manual(1, $amount, 'Of ' . $source, source: $source, sourceId: 900);

            $narrowed = $this->built($this->report, [...$this->thisDay(), 'source' => $source]);

            $this->assertCount(1, $this->rowsOf($narrowed), $source);
            $this->assertAmount($amount, $this->grandOf($narrowed, 'debit'));
            $this->assertSame(lang('Accounting.source_' . $source), $this->rowsOf($narrowed)[0]->cell('source'));
        }
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndTheJournalsOfTheOwnerWhenGivenOne(): void
    {
        $owner  = $this->employeeWithGrants([]);
        $branch = $this->createBranch('JR2', 'Journals branch');

        $this->manual(1, '40.00', 'First branch', $owner);
        $this->manual($branch, '15.00', 'Second branch');

        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));
        $mine    = $this->built($this->report, $this->thisDay(), null, null, $owner);
        $nobody  = $this->built($this->report, $this->thisDay(), null, null, 999999);

        $this->assertAmount('15.00', $this->grandOf($limited, 'debit'));
        $this->assertReportTies($limited);
        $this->assertAmount('40.00', $this->grandOf($mine, 'debit'));
        $this->assertSame([], $mine->ties);
        $this->assertSame([], $this->rowsOf($nobody));
    }

    /**
     * @return array{from: string, to: string, branch: int, owner: int}
     */
    private function variedJournals(): array
    {
        $this->purchaseOf('230.00');

        $branch = $this->createBranch('JR3', 'Journals branch');
        $owner  = $this->employeeWithGrants(['accounting_journals']);
        $days   = [date('Y-m-d', strtotime('-40 days')), date('Y-m-d', strtotime('-1 day')), $this->today()];

        foreach ($days as $index => $day) {
            foreach ([1, $branch] as $branchId) {
                (new PostingService())->post(new JournalDraft(
                    date: $day,
                    lines: [new JournalLineDraft($this->accountId('cash'), debit: (string) (10 + $index) . '.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: (string) (10 + $index) . '.00')],
                    sourceType: $index === 1 ? 'stock_permit' : JournalDraft::SOURCE_MANUAL,
                    sourceId: $index === 1 ? 900 + $branchId : null,
                    description: 'Varied ' . $index . ' in ' . $branchId . ($index === 2 ? ' opening' : ''),
                    currencyCode: 'SAR',
                    branchId: $branchId,
                    employeeId: $index === 2 ? $owner : null,
                ));
            }
        }

        return ['from' => $days[0], 'to' => $this->today(), 'branch' => $branch, 'owner' => $owner];
    }

    public function testBuild_GivesTheTableTheReportGaveWhenItReadEveryJournalAtOnceWhateverTheSizeOfTheChunks(): void
    {
        $varied = $this->variedJournals();
        $legacy = new LegacyJournalsReport();
        $period = ['from' => $varied['from'], 'to' => $varied['to']];
        $narrow = [
            'all'    => [[], null, null],
            'source' => [['source' => 'stock_permit'], null, null],
            'manual' => [['source' => 'manual'], null, null],
            'words'  => [['search' => 'opening'], null, null],
            'branch' => [[], $this->limitedTo($varied['branch']), null],
            'owner'  => [[], null, $varied['owner']],
        ];

        foreach ([1, 2, 5, JournalLines::CHUNK] as $chunk) {
            $report = new JournalsReport(new JournalLines(null, $chunk));

            foreach ($narrow as $name => [$query, $scope, $owner]) {
                $input = $this->input([...$period, ...$query], $scope, null, $owner);
                $label = "chunks of {$chunk}, {$name}";
                $old   = $legacy->build($input);
                $new   = $report->build($input);

                $this->assertGreaterThan(0, $old->rowCount(), $label);
                $this->assertEquals($old, $new, $label);
                $this->assertSame(iterator_to_array(ReportCsv::rows($old, null), false), iterator_to_array(ReportCsv::rows($report->stream($input), null), false), $label);
                $this->assertSame($old->rowCount(), $report->stream($input)->rowCount(), $label);

                foreach ([1, 2, 3] as $page) {
                    $this->assertEquals($old->page($page, 4), $report->stream($input)->page($page, 4), "{$label}, page {$page}");
                }
            }
        }
    }

    public function testBuild_ShowsTheCurrentMonthAndSaysSoWhenNoPeriodIsChosen(): void
    {
        $this->manual(1, '40.00', 'This month');
        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d', strtotime('-70 days')),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '999.00'), new JournalLineDraft($this->accountId('opening_balance'), credit: '999.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            description: 'Long ago',
            currencyCode: 'SAR',
            branchId: 1,
        ));

        $table = $this->built($this->report);

        $this->assertAmount('40.00', $this->grandOf($table, 'debit'));
        $this->assertStringContainsString(lang('Accounting_family_reports.note_default_month', [date('Y-m-01')]), implode(' ', $table->notes));
        $this->assertReportTies($table);
        $this->assertAmount('1039.00', $this->grandOf($this->built($this->report, ['from' => date('Y-m-d', strtotime('-80 days')), 'to' => $this->today()]), 'debit'));
    }
}
