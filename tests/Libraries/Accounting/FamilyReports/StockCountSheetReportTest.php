<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\StockCountSheetReport;
use Tests\Support\FamilyReportTestCase;

/**
 * The sheet a count is written on: the stock items that hold stock with the quantity the system holds
 * and the count and note columns empty, the items with nothing too when asked, by category or not, and
 * the total beside the quantity of the movements of the books.
 *
 * @internal
 */
final class StockCountSheetReportTest extends FamilyReportTestCase
{
    private StockCountSheetReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyShelves();

        $this->report = new StockCountSheetReport();
    }

    protected function tearDown(): void
    {
        $this->restoreShelves();

        parent::tearDown();
    }

    /**
     * @return array{cream: int, soap: int, idle: int}
     */
    private function shelves(): array
    {
        $cream = $this->named('Cream', 'Care');
        $soap  = $this->named('Soap', 'Home');
        $idle  = $this->named('Idle laptop', 'Care');

        $this->bookStock($this->today(), 1, $cream, '10', '100.00');
        $this->putOnShelf($cream, '10');
        $this->bookStock($this->today(), 1, $soap, '4', '40.00');
        $this->putOnShelf($soap, '4');
        $this->putOnShelf($idle, '0');

        return ['cream' => $cream, 'soap' => $soap, 'idle' => $idle];
    }

    public function testBuild_ListsOnlyTheStockItemsThatHoldStockAndLeavesTheCountColumnsEmpty(): void
    {
        $this->shelves();
        $this->item('0.00', false);

        $table = $this->built($this->report);
        $rows  = $this->rowsOf($table);

        $this->assertSame(['Cream', 'Soap'], $this->columnOf($table, 'name'));
        $this->assertSame(['', ''], $this->columnOf($table, 'count'));
        $this->assertSame(['', ''], $this->columnOf($table, 'notes'));
        $this->assertAmount('10.000', $rows[0]->cell('system'));
        $this->assertAmount('14.000', $this->grandOf($table, 'system'));
    }

    public function testBuild_AddsTheItemsWithNothingWhenAskedAndHideZeroWins(): void
    {
        $this->shelves();

        $all  = $this->built($this->report, ['all' => '1']);
        $both = $this->built($this->report, ['all' => '1', 'hide_zero' => '1']);

        $this->assertContains('Idle laptop', $this->columnOf($all, 'name'));
        $this->assertNotContains('Idle laptop', $this->columnOf($both, 'name'));
    }

    public function testBuild_GroupsByCategoryAndOrdersByQuantity(): void
    {
        $this->shelves();

        $grouped = $this->built($this->report, ['group' => 'category', 'all' => '1']);
        $ordered = $this->built($this->report, ['order' => 'quantity_desc']);

        $this->assertSame(['Care', 'Home'], array_map(static fn ($block): ?string => $block->title, $grouped->blocks));
        $this->assertSame(['Cream', 'Soap'], $this->columnOf($ordered, 'name'));
        $this->assertSame(['Soap', 'Cream'], $this->columnOf($this->built($this->report, ['order' => 'quantity_asc']), 'name'));
    }

    public function testBuild_TiesTheShelvesToTheQuantityOfTheMovementsAndSaysWhenTheyDiffer(): void
    {
        $items = $this->shelves();

        $this->assertReportTies($this->built($this->report));

        $this->putOnShelf($items['cream'], '12');

        $table = $this->built($this->report);

        $this->assertFalse($table->ties());
        $this->assertAmount('2.00', $table->ties[0]->difference());
    }

    public function testBuild_ReadsOnlyTheLocationsOfTheBranchesOfTheScopeAndOneLocation(): void
    {
        $items  = $this->shelves();
        $branch = $this->createBranch('CS2', 'Count branch');
        $depot  = $this->locationOfBranch($branch, 'Count depot');
        $other  = $this->named('Depot item');

        $this->bookStock($this->today(), $branch, $other, '6', '60.00', $depot);
        $this->putOnShelf($other, '6', $depot);

        $limited = $this->built($this->report, [], $this->limitedTo($branch));
        $one     = $this->built($this->report, ['location_id' => (string) $depot]);

        $this->assertSame(['Depot item'], $this->columnOf($limited, 'name'));
        $this->assertSame(['Depot item'], $this->columnOf($one, 'name'));
        $this->assertReportTies($limited);
        $this->assertCount(3, $this->rowsOf($this->built($this->report)));
        $this->assertGreaterThan(0, $items['cream']);
    }

    public function testBuild_DoesNotCompareACategoryWithTheMovements(): void
    {
        $this->shelves();

        $table = $this->built($this->report, ['category' => 'Care']);

        $this->assertSame([], $table->ties);
        $this->assertSame(['Cream'], $this->columnOf($table, 'name'));
    }
}
