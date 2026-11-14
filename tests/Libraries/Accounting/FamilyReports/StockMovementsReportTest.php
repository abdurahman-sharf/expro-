<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\ReportCsv;
use App\Libraries\Accounting\FamilyReports\StockEntries;
use App\Libraries\Accounting\FamilyReports\StockMovementsReport;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\StockLedger;
use Tests\Support\FamilyReportTestCase;
use Tests\Support\LegacyFamilyReports\LegacyStockMovementsReport;
use Tests\Support\StockHistoryFixtureTrait;

/**
 * The movements of each item in each location, in order, after the quantity it held before the first
 * day, and as valued: what each was worth when it reached the books, the quantity and value after it and
 * the average cost, the value beside the accounts of the locations.
 *
 * @internal
 */
final class StockMovementsReportTest extends FamilyReportTestCase
{
    use StockHistoryFixtureTrait;

    private StockMovementsReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyShelves();

        $this->report = new StockMovementsReport();
    }

    protected function tearDown(): void
    {
        $this->restoreShelves();

        parent::tearDown();
    }

    /**
     * Ten bought at 50, ten at 70, five sold: the average cost is 50, then 60, and the sale takes out
     * 300.
     */
    private function sequence(): int
    {
        $item = $this->named('Cream');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50.00')]));
        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '70.00')]));
        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '5', '100.00', '60.00')], [], [$this->payment(lang('Sales.cash'), '500')]));

        return $item;
    }

    public function testBuild_ShowsWhatEachMovementWasWorthAndTheAverageCostThatFollows(): void
    {
        $this->sequence();

        $table = $this->built($this->report, [...$this->thisDay(), 'view' => 'valued']);
        $rows  = $this->rowsOf($table);

        $this->assertCount(3, $rows);
        $this->assertAmount('500.00', $rows[0]->cell('value'));
        $this->assertAmount('50.00', $rows[0]->cell('average_cost'));
        $this->assertAmount('700.00', $rows[1]->cell('value'));
        $this->assertAmount('1200.00', $rows[1]->cell('value_after'));
        $this->assertAmount('60.00', $rows[1]->cell('average_cost'));
        $this->assertAmount('-300.00', $rows[2]->cell('value'));
        $this->assertAmount('60.00', $rows[2]->cell('unit_cost'));
        $this->assertAmount('15', $rows[2]->cell('quantity_after'));
        $this->assertAmount('900.00', $rows[2]->cell('value_after'));
    }

    public function testBuild_TiesTheValueOfTheMovementsToTheAccountsOfTheLocations(): void
    {
        $this->sequence();

        $this->assertReportTies($this->built($this->report, [...$this->thisDay(), 'view' => 'valued']));
    }

    public function testBuild_ListsQuantitiesInAndOutAfterAnOpeningQuantityRow(): void
    {
        $this->sequence();

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $later    = $this->built($this->report, ['from' => $tomorrow, 'to' => date('Y-m-d', strtotime('+2 days'))]);
        $plain    = $this->built($this->report, $this->thisDay());

        $this->assertCount(1, $this->rowsOf($later));
        $this->assertStringContainsString($tomorrow, $this->rowsOf($later)[0]->cell('kind'));
        $this->assertAmount('15', $this->rowsOf($later)[0]->cell('quantity_after'));
        $this->assertSame('10.000', $this->rowsOf($plain)[0]->cell('in'));
        $this->assertSame('', $this->rowsOf($plain)[0]->cell('out'));
        $this->assertSame('5.000', $this->rowsOf($plain)[2]->cell('out'));
    }

    public function testBuild_ShowsAReversalAsAMovementOfItsOwn(): void
    {
        $item = $this->named('Soap');

        $this->bookStock($this->today(), 1, $item, '4', '40.00');
        (new StockLedger())->reverseSource('purchase', $this->lastBookedSource(), JournalEntryType::General, $this->today(), null);

        $rows = $this->rowsOf($this->built($this->report, $this->thisDay()));

        $this->assertCount(2, $rows);
        $this->assertSame('4.000', $rows[0]->cell('in'));
        $this->assertSame('4.000', $rows[1]->cell('out'));
        $this->assertAmount('0', $rows[1]->cell('quantity_after'));
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScopeAndDoesNotCompareAnItem(): void
    {
        $item   = $this->sequence();
        $branch = $this->createBranch('MV2', 'Movement branch');
        $depot  = $this->locationOfBranch($branch, 'Movement depot');
        $other  = $this->named('Depot item');

        $this->bookStock($this->today(), $branch, $other, '3', '30.00', $depot);

        $limited = $this->built($this->report, [...$this->thisDay(), 'view' => 'valued'], $this->limitedTo($branch));
        $narrow  = $this->built($this->report, [...$this->thisDay(), 'view' => 'valued', 'item_id' => (string) $item]);

        $this->assertCount(1, $this->rowsOf($limited));
        $this->assertReportTies($limited);
        $this->assertSame([], $narrow->ties);
    }

    public function testBuild_GivesTheTableTheReportGaveWhenItReadEveryMovementAtOnceWhateverTheSizeOfTheChunks(): void
    {
        $history = $this->stockHistory();
        $legacy  = new LegacyStockMovementsReport();
        $periods = [
            'whole'   => ['from' => $history['from'], 'to' => $history['to']],
            'month'   => ['from' => $this->ago(30), 'to' => $history['to']],
            'middle'  => ['from' => $this->ago(50), 'to' => $this->ago(10)],
            'day'     => $this->thisDay(),
            'to only' => ['to' => $this->ago(10)],
        ];
        $filters = [
            'none'     => [],
            'location' => ['location_id' => (string) $history['depot']],
            'item'     => ['item_id' => (string) $history['items']['soap']],
            'category' => ['category' => 'Care'],
        ];

        foreach ([1, 2, 3, StockEntries::CHUNK] as $chunk) {
            $report = new StockMovementsReport(new StockEntries(null, $chunk));

            foreach (['plain', 'valued'] as $view) {
                foreach ($periods as $periodName => $period) {
                    foreach ($filters as $filterName => $filter) {
                        $input = $this->input([...$period, ...$filter, 'view' => $view]);
                        $label = "chunks of {$chunk}, {$view}, {$periodName}, {$filterName}";
                        $old   = $legacy->build($input);
                        $new   = $report->build($input);

                        $this->assertEquals($old, $new, $label);
                        $this->assertSame(iterator_to_array(ReportCsv::rows($old, null), false), iterator_to_array(ReportCsv::rows($report->stream($input), null), false), $label);
                        $this->assertSame($old->rowCount(), $report->stream($input)->rowCount(), $label);
                    }
                }
            }
        }

        $this->assertGreaterThan(8, $legacy->build($this->input([...$periods['whole'], 'view' => 'valued']))->rowCount());
    }

    public function testBuild_GivesEveryPageTheRowsOfThePageOfTheTableThatHoldsThem(): void
    {
        $history = $this->stockHistory();
        $legacy  = new LegacyStockMovementsReport();
        $report  = new StockMovementsReport(new StockEntries(null, 2));
        $input   = $this->input(['from' => $this->ago(50), 'to' => $history['to'], 'view' => 'valued']);
        $old     = $legacy->build($input);

        foreach ([1, 2, 3, 5, 9] as $page) {
            $this->assertEquals($old->page($page, 3), $report->stream($input)->page($page, 3), "page {$page}");
        }
    }

    public function testBuild_ShowsTheCurrentMonthAndSaysSoWhenNoPeriodIsChosen(): void
    {
        $item = $this->named('Dated');

        $this->bookStock($this->ago(70), 1, $item, '4', '40.00');
        $this->bookStock($this->today(), 1, $item, '2', '20.00');

        $table = $this->built($this->report);
        $rows  = $this->rowsOf($table);

        $this->assertCount(2, $rows);
        $this->assertStringContainsString(date('Y-m-01'), $rows[0]->cell('kind'));
        $this->assertSame('4.000', $rows[0]->cell('quantity_after'));
        $this->assertSame('6.000', $rows[1]->cell('quantity_after'));
        $this->assertStringContainsString(lang('Accounting_family_reports.note_default_month', [date('Y-m-01')]), implode(' ', $table->notes));
    }
}
