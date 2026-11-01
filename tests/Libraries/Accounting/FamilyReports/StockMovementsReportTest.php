<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\StockMovementsReport;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\StockLedger;
use Tests\Support\FamilyReportTestCase;

/**
 * The movements of each item in each location, in order, after the quantity it held before the first
 * day, and as valued: what each was worth when it reached the books, the quantity and value after it and
 * the average cost, the value beside the accounts of the locations.
 *
 * @internal
 */
final class StockMovementsReportTest extends FamilyReportTestCase
{
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
}
