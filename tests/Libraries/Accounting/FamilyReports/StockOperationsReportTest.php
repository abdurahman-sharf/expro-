<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\StockOperationsReport;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\StockLedger;
use Tests\Support\FamilyReportTestCase;
use Tests\Support\LegacyFamilyReports\LegacyStockOperationsReport;
use Tests\Support\StockHistoryFixtureTrait;

/**
 * For each item what came in and went out in a period by kind of operation, an operation that was
 * reversed counting for nothing, outflows negative, and the movement beside the quantity of the
 * movements.
 *
 * @internal
 */
final class StockOperationsReportTest extends FamilyReportTestCase
{
    use StockHistoryFixtureTrait;

    private StockOperationsReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyShelves();

        $this->report = new StockOperationsReport();
    }

    protected function tearDown(): void
    {
        $this->restoreShelves();

        parent::tearDown();
    }

    /**
     * Ten bought at 50, three sold, two taken back to the supplier.
     *
     * @return array{item: int, sale: int}
     */
    private function trade(): array
    {
        $item     = $this->named('Cream');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '10', '50.00')]);
        $sale     = $this->save([$this->line($item, 0, '3', '100.00', '50.00')], [], [$this->payment(lang('Sales.cash'), '300')]);

        $this->assertGreaterThan(0, $sale);
        $this->assertGreaterThan(0, $this->debitNoteOf($purchase, '100.00'));

        return ['item' => $item, 'sale' => $sale];
    }

    public function testBuild_SplitsTheInflowAndTheOutflowBySourceKindPerItem(): void
    {
        $this->trade();

        $table = $this->built($this->report, $this->thisDay());
        $row   = $this->rowWhere($table, 'name', 'Cream');

        $this->assertNotNull($row);
        $this->assertAmount('10', $row->cell('in_purchase'));
        $this->assertAmount('-3', $row->cell('out_sale'));
        $this->assertAmount('-2', $row->cell('out_purchase_return'));
        $this->assertAmount('10', $row->cell('in_total'));
        $this->assertAmount('-5', $row->cell('out_total'));
        $this->assertAmount('5', $row->cell('movement'));
        $this->assertAmount('0', $row->cell('in_manual'));
    }

    public function testBuild_CountsAReversedOperationForNothing(): void
    {
        $facts = $this->trade();

        (new StockLedger())->reverseSource('sale', $facts['sale'], JournalEntryType::Cogs, $this->today(), null);

        $row = $this->rowWhere($this->built($this->report, $this->thisDay()), 'name', 'Cream');

        $this->assertAmount('0', $row->cell('out_sale'));
        $this->assertAmount('8', $row->cell('movement'));
    }

    public function testBuild_AddsUpTheMovementOfTheOperationsToTheQuantityOfTheMovements(): void
    {
        $facts = $this->trade();

        $this->bookStock($this->today(), 1, $facts['item'], '4', '40.00');
        $this->bookStock($this->today(), 1, $facts['item'], '-1', '-10.00');

        $table = $this->built($this->report, $this->thisDay());

        $this->assertReportTies($table);
        $this->assertAmount('8', $this->grandOf($table, 'movement'));
        $this->assertAmount('14', $this->rowWhere($table, 'name', 'Cream')->cell('in_purchase'));
        $this->assertAmount('-3', $this->rowWhere($table, 'name', 'Cream')->cell('out_purchase_return'));
    }

    public function testBuild_CutsDownToTheDaysAndToTheBranchesOfTheScope(): void
    {
        $this->trade();

        $branch = $this->createBranch('OP2', 'Operations branch');
        $depot  = $this->locationOfBranch($branch, 'Operations depot');
        $other  = $this->named('Depot item');

        $this->bookStock($this->today(), $branch, $other, '6', '60.00', $depot);

        $old     = $this->built($this->report, ['from' => $this->ago(30), 'to' => $this->ago(10)]);
        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));
        $one     = $this->built($this->report, [...$this->thisDay(), 'location_id' => (string) $depot]);

        $this->assertSame([], $this->rowsOf($old));
        $this->assertSame(['Depot item'], $this->columnOf($limited, 'name'));
        $this->assertSame(['Depot item'], $this->columnOf($one, 'name'));
    }

    public function testBuild_GivesTheTableTheReportGaveWhenItReadEveryMovementAtOnce(): void
    {
        $history = $this->stockHistory();
        $legacy  = new LegacyStockOperationsReport();
        $periods = [
            'default' => [],
            'whole'   => ['from' => $history['from'], 'to' => $history['to']],
            'month'   => ['from' => $this->ago(30), 'to' => $history['to']],
            'middle'  => ['from' => $this->ago(50), 'to' => $this->ago(10)],
            'day'     => $this->thisDay(),
        ];
        $filters = [
            'none'     => [],
            'location' => ['location_id' => (string) $history['depot']],
            'item'     => ['item_id' => (string) $history['items']['soap']],
            'category' => ['category' => 'Care'],
        ];

        foreach ($periods as $periodName => $period) {
            foreach ($filters as $filterName => $filter) {
                $input = $this->input([...$period, ...$filter]);

                $this->assertEquals($legacy->build($input), $this->report->build($input), "{$periodName}, {$filterName}");
            }
        }

        $this->assertGreaterThan(3, $legacy->build($this->input($periods['whole']))->rowCount());
    }
}
