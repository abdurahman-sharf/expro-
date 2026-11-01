<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\ProductPurchasesReport;
use Tests\Support\FamilyReportTestCase;

/**
 * What was bought of each item: the lines of the purchases and returns, a return as a line with a
 * negative quantity and value, the value of a line with its tax, and the lines adding up to the net
 * purchases of the other reports.
 *
 * @internal
 */
final class ProductPurchasesReportTest extends FamilyReportTestCase
{
    private ProductPurchasesReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new ProductPurchasesReport();
    }

    private function named(string $name, string $category = ''): int
    {
        $item = $this->item('0.00');

        $this->accountingDb()->table('items')->where('item_id', $item)->update(['name' => $name, 'category' => $category, 'item_number' => 'N-' . $item]);

        return $item;
    }

    public function testBuild_SumsTheQuantityAndTheValuePerItemNetOfReturns(): void
    {
        $cream   = $this->named('Cream', 'Care');
        $perfume = $this->named('Perfume', 'Scent');
        $big     = $this->buy([$this->purchaseLine($cream, 0, '3', '100.00', '15'), $this->purchaseLine($perfume, 1, '2', '50.00')]);
        $this->debitNoteOf($big, '100.00');

        $table = $this->built($this->report, $this->thisDay());

        $creamRow = $this->rowWhere($table, 'group', 'Cream');

        $this->assertNotNull($creamRow);
        $this->assertAmount('2.000', $creamRow->cell('quantity'));
        $this->assertAmount('245.00', $creamRow->cell('total'));
        $this->assertAmount('100.00', $this->rowWhere($table, 'group', 'Perfume')->cell('total'));
        $this->assertAmount('345.00', $this->grandOf($table, 'total'));
    }

    public function testBuild_ListsAReturnAsNegativeLinesAndTheValueOfALineIncludesItsTax(): void
    {
        $cream = $this->named('Cream');
        $id    = $this->buy([$this->purchaseLine($cream, 0, '3', '100.00', '15')]);
        $this->debitNoteOf($id, '100.00');

        $table = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail']);
        $rows  = $this->rowsOf($table);

        $this->assertCount(2, $rows);
        $this->assertAmount('345.00', $rows[0]->cell('total'));
        $this->assertAmount('45.00', $rows[0]->cell('tax'));
        $this->assertAmount('3.000', $rows[0]->cell('quantity'));
        $this->assertAmount('-1.000', $rows[1]->cell('quantity'));
        $this->assertAmount('-100.00', $rows[1]->cell('total'));
    }

    public function testBuild_AddsUpToTheNetPurchasesInEveryGrouping(): void
    {
        $this->market();

        foreach (ProductPurchasesReport::GROUPS as $group) {
            foreach (['summary', 'detail'] as $view) {
                $table = $this->built($this->report, [...$this->thisDay(), 'group' => $group, 'view' => $view]);

                $this->assertAmount('395.00', $this->grandOf($table, 'total'), "{$view} by {$group}");
            }
        }
    }

    public function testBuild_PutsTheRoundingOfADocumentOnItsLastLineSoTheLinesAddUpToItsTotal(): void
    {
        $first = $this->named('First');
        $this->buy([$this->purchaseLine($first, 0, '3', '33.33', '15'), $this->purchaseLine($first, 1, '1', '10.03', '15')]);

        $table = $this->built($this->report, [...$this->thisDay(), 'view' => 'detail']);

        $this->assertAmount('126.52', $this->grandOf($table, 'total'));
        $this->assertReportTies($table);
    }

    public function testBuild_TiesToTheNetPurchasesAndSaysSoWhenAFilterNarrowsIt(): void
    {
        $cream = $this->named('Cream', 'Care');
        $this->buy([$this->purchaseLine($cream, 0, '1', '100.00')]);
        $this->buy([$this->purchaseLine($this->named('Soap', 'Home'), 0, '1', '10.00')]);

        $whole  = $this->built($this->report, $this->thisDay());
        $narrow = $this->built($this->report, [...$this->thisDay(), 'category' => 'Care']);

        $this->assertReportTies($whole);
        $this->assertSame([], $narrow->ties);
        $this->assertAmount('100.00', $this->grandOf($narrow, 'total'));
        $this->assertCount(1, $this->rowsOf($narrow));
    }

    public function testBuild_ReadsOnlyTheBranchesOfTheScope(): void
    {
        $cream  = $this->named('Cream');
        $branch = $this->createBranch('PP2', 'Product branch');

        $this->buy([$this->purchaseLine($cream, 0, '1', '100.00')]);
        $this->buy([$this->purchaseLine($cream, 0, '2', '30.00', '0', '0', PERCENT, $this->locationOfBranch($branch))]);

        $this->assertAmount('160.00', $this->grandOf($this->built($this->report, $this->thisDay()), 'total'));
        $this->assertAmount('60.00', $this->grandOf($this->built($this->report, $this->thisDay(), $this->limitedTo($branch)), 'total'));
        $this->assertSame([], $this->rowsOf($this->built($this->report, $this->thisDay(), $this->limitedTo())));
    }
}
