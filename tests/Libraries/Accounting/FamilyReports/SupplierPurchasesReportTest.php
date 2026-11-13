<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\FamilyReports\SupplierPurchasesReport;
use Tests\Support\FamilyReportTestCase;

/**
 * Every purchase and return of a period with its value, discounts, tax and total, supplier by supplier,
 * a return with every figure negative, the discounts negative, and the total the net purchases.
 *
 * @internal
 */
final class SupplierPurchasesReportTest extends FamilyReportTestCase
{
    private SupplierPurchasesReport $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->report = new SupplierPurchasesReport();
    }

    public function testBuild_RejectsTheCheckAgainstTheBooksByNameWhenAPurchaseIsInAForeignCurrency(): void
    {
        $facts = $this->market();

        (new Currencies())->setActive('USD', true);
        $this->accountingDb()->table('receivings')->where('receiving_id', $facts['other'])->update(['currency_code' => 'USD', 'currency_rate' => '3.75000000']);

        $table = $this->built($this->report, $this->thisDay());

        $this->assertCount(1, $table->ties);
        $this->assertTrue($table->ties[0]->isRefused());
        $this->assertSame(lang('Accounting_family_reports.tie_foreign_currency', ['USD']), $table->ties[0]->refusal);
    }

    public function testBuild_ShowsTheValueTheDiscountAndTheTaxOfEachDocumentWithReturnsAsNegativeLines(): void
    {
        $item = $this->item('0.00');
        $this->buy([$this->purchaseLine($item, 0, '2', '100.00', '15', '10')]);
        $small = $this->buy([$this->purchaseLine($item, 0, '1', '100.00', '15')]);
        $this->debitNoteOf($small, '40.00');

        $table = $this->built($this->report, $this->thisDay());
        $rows  = $this->rowsOf($table);

        $this->assertCount(3, $rows);
        $this->assertAmount('200.00', $rows[0]->cell('value'));
        $this->assertAmount('-20.00', $rows[0]->cell('discount'));
        $this->assertAmount('27.00', $rows[0]->cell('tax'));
        $this->assertAmount('207.00', $rows[0]->cell('total'));
        $this->assertAmount('-40.00', $rows[2]->cell('value'));
        $this->assertAmount('-40.00', $rows[2]->cell('total'));
        $this->assertAmount('282.00', $this->grandOf($table, 'total'));
    }

    public function testBuild_AddsTheValueTheDiscountAndTheTaxUpToTheTotalOfEverySupplier(): void
    {
        $this->market();

        $table = $this->built($this->report, $this->thisDay());

        foreach ($table->blocks as $block) {
            $this->assertNotNull($block->total);
            $sum = bcadd(bcadd($block->total->cell('value'), $block->total->cell('discount'), 4), $block->total->cell('tax'), 4);

            $this->assertAmount($block->total->cell('total'), $sum);
        }

        $this->assertCount(2, $table->blocks);
        $this->assertAmount('395.00', $this->grandOf($table, 'total'));
        $this->assertAmount('295.00', $table->blocks[0]->total->cell('total'));
    }

    public function testBuild_TiesTheTotalToTheJournalsOfThePurchases(): void
    {
        $this->market();

        $this->assertReportTies($this->built($this->report, $this->thisDay()));
    }

    public function testBuild_CutsDownToOneSupplierAndToTheBranchesOfTheScope(): void
    {
        $ids    = $this->market();
        $branch = $this->createBranch('S2', 'Branch for suppliers');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($ids['item'], 0, '1', '70.00', '0', '0', PERCENT, $this->locationOfBranch($branch))], $ids['second']));

        $one     = $this->built($this->report, [...$this->thisDay(), 'supplier_id' => (string) $ids['second']]);
        $limited = $this->built($this->report, $this->thisDay(), $this->limitedTo($branch));

        $this->assertAmount('170.00', $this->grandOf($one, 'total'));
        $this->assertAmount('70.00', $this->grandOf($limited, 'total'));
        $this->assertCount(1, $this->rowsOf($limited));
        $this->assertReportTies($limited);
    }
}
