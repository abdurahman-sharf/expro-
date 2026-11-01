<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\StockBalanceReport;
use Tests\Support\FamilyReportTestCase;

/**
 * The quantity of each stock item in each location with its total and its status, the total beside the
 * quantity of the movements of the books.
 *
 * @internal
 */
final class StockBalanceReportTest extends FamilyReportTestCase
{
    private StockBalanceReport $report;
    private int $depot;
    private int $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->emptyShelves();

        $this->second = $this->createBranch('SB2', 'Balance branch');
        $this->depot  = $this->locationOfBranch($this->second, 'Balance depot');
        $this->report = new StockBalanceReport();
    }

    protected function tearDown(): void
    {
        $this->restoreShelves();

        parent::tearDown();
    }

    private function stocked(string $name, string $quantity, int $locationId = 1, string $level = '0'): int
    {
        $item = $this->named($name);

        if (bccomp($quantity, '0', 3) !== 0) {
            $this->bookStock($this->today(), $locationId === 1 ? 1 : $this->second, $item, $quantity, bcmul($quantity, '10', 2), $locationId);
        }

        $this->putOnShelf($item, $quantity, $locationId);
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['reorder_level' => $level]);

        return $item;
    }

    public function testBuild_AddsAColumnForEachLocationAndATotalForEachItem(): void
    {
        $item = $this->stocked('Cream', '10');
        $this->bookStock($this->today(), $this->second, $item, '5', '50.00', $this->depot);
        $this->putOnShelf($item, '5', $this->depot);

        $table = $this->built($this->report);
        $row   = $this->rowWhere($table, 'name', 'Cream');

        $this->assertNotNull($row);
        $this->assertAmount('10', $row->cell('l1'));
        $this->assertAmount('5', $row->cell('l' . $this->depot));
        $this->assertAmount('15', $row->cell('total'));
        $this->assertContains('Balance depot', array_map(static fn ($column): string => $column->label, $table->columns));
        $this->assertReportTies($table);
    }

    public function testBuild_FiltersByTheStatusOfTheItems(): void
    {
        $this->stocked('Plenty', '20', 1, '5');
        $this->stocked('Few', '3', 1, '5');
        $this->stocked('Nothing', '0');
        $deleted = $this->stocked('Gone', '2');
        $this->accountingDb()->table('items')->where('item_id', $deleted)->update(['deleted' => 1]);

        $names = fn (string $status): array => $this->columnOf($this->built($this->report, ['status' => $status]), 'name');

        $this->assertSame(['Plenty'], $names('available'));
        $this->assertSame(['Few'], $names('low'));
        $this->assertSame(['Nothing'], $names('out'));
        $this->assertSame(['Gone'], $names('inactive'));
        $this->assertSame(['Few', 'Nothing', 'Plenty'], $names('all'));
    }

    public function testBuild_DoesNotCountAnItemWithoutALevelAsLowHoweverLittleIsLeft(): void
    {
        $this->stocked('Few', '1', 1, '0');

        $this->assertSame([], $this->columnOf($this->built($this->report, ['status' => 'low']), 'name'));
        $this->assertSame(['Few'], $this->columnOf($this->built($this->report, ['status' => 'available']), 'name'));
    }

    public function testBuild_SearchesTheNameAndDoesNotCompareANarrowedList(): void
    {
        $this->stocked('Rose cream', '4');
        $this->stocked('Soap', '4');

        $table = $this->built($this->report, ['search' => 'rose']);

        $this->assertSame(['Rose cream'], $this->columnOf($table, 'name'));
        $this->assertSame([], $table->ties);
    }

    public function testBuild_ReadsOnlyTheLocationsOfTheBranchesOfTheScope(): void
    {
        $this->stocked('First item', '7');
        $this->stocked('Depot item', '9', $this->depot);

        $limited = $this->built($this->report, [], $this->limitedTo($this->second));

        $this->assertSame(['Depot item'], $this->columnOf($limited, 'name'));
        $this->assertAmount('9', $this->grandOf($limited, 'total'));
        $this->assertReportTies($limited);
    }
}
