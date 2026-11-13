<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\JournalsReport;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use Tests\Support\FamilyReportTestCase;

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
}
