<?php

namespace Tests\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\FamilyReports\StockOperation;
use App\Libraries\Stock\StockPermitSources;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The operation a stock movement belongs to and the name it carries in the report of movements.
 *
 * @internal
 */
final class StockOperationTest extends CIUnitTestCase
{
    public function testOf_CountsTheTransferPermitAndItsReceiptAsTransfers(): void
    {
        foreach ([StockPermitSources::TRANSFER, StockPermitSources::TRANSFER_RECEIPT, 'stock_transfer', 'stock_transfer_receipt'] as $source) {
            $this->assertSame(['in_transfer', '4.000'], StockOperation::of($source, 'posting', '4.000'), $source);
            $this->assertSame(['out_transfer', '-4.000'], StockOperation::of($source, 'posting', '-4.000'), $source);
        }
    }

    public function testOf_CountsTheReversalOfATransferPermitAgainstTheSideItTakesBack(): void
    {
        $this->assertSame(['out_transfer', '4.000'], StockOperation::of(StockPermitSources::TRANSFER, 'reversal', '4.000'));
        $this->assertSame(['in_transfer', '-4.000'], StockOperation::of(StockPermitSources::TRANSFER_RECEIPT, 'reversal', '-4.000'));
    }

    public function testOf_KeepsThePermitsAndTheStocktakeAmongTheManualOperations(): void
    {
        foreach ([StockPermitSources::PERMIT, StockPermitSources::STOCKTAKE, 'stock_adjustment', 'opening_stock'] as $source) {
            $this->assertSame(['in_manual', '2.000'], StockOperation::of($source, 'posting', '2.000'), $source);
            $this->assertSame(['out_manual', '-2.000'], StockOperation::of($source, 'posting', '-2.000'), $source);
        }
    }

    public function testOf_KeepsASourceItDoesNotKnowAmongTheManualOperations(): void
    {
        $this->assertSame(['in_manual', '1.000'], StockOperation::of('made_up', 'posting', '1.000'));
    }

    public function testLabel_NamesEverySourceOfTheStockDocumentsInBothDirections(): void
    {
        $sources = ['purchase', 'sale', 'stock_adjustment', 'opening_stock', 'stock_transfer', 'stock_transfer_receipt', StockPermitSources::PERMIT, StockPermitSources::STOCKTAKE, StockPermitSources::TRANSFER, StockPermitSources::TRANSFER_RECEIPT];
        $names   = [];

        foreach ($sources as $source) {
            foreach (['1.000', '-1.000'] as $quantity) {
                $label = StockOperation::label($source, $quantity);

                $this->assertNotSame(lang('Accounting_family_reports.op_other_' . ($quantity === '1.000' ? 'in' : 'out')), $label, $source . ' is named other movement');
                $this->assertStringNotContainsString('Accounting_family_reports.', $label, $source . ' has no name');
                $names[] = $label;
            }
        }

        $this->assertSame($names, array_values(array_unique($names)), 'two sources share a name');
    }

    public function testLabel_NamesAnUnknownSourceOtherMovementAndAReversalByWhatItTakesBack(): void
    {
        $this->assertSame(lang('Accounting_family_reports.op_other_in'), StockOperation::label('made_up', '1.000'));
        $this->assertSame(lang('Accounting_family_reports.op_reversal_of', [lang('Accounting_family_reports.op_stocktake_out')]), StockOperation::label(StockPermitSources::STOCKTAKE, '-1.000', 'reversal'));
    }
}
