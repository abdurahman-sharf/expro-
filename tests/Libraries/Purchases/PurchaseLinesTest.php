<?php

namespace Tests\Libraries\Purchases;

use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Purchases\PurchaseLines;
use Tests\Support\FamilyReportTestCase;

/**
 * The lines of saved purchases and what each purchase comes to with its tax.
 *
 * @internal
 */
final class PurchaseLinesTest extends FamilyReportTestCase
{
    public function testTotalsOf_MatchesReceivingAmountsOnLinesWithDiscountsAndTax(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '3', '33.33', '15'), $this->purchaseLine($item, 1, '1', '10.01', '15', '10')]);

        $lines  = (new PurchaseLines())->of([$id]);
        $totals = (new PurchaseLines())->totalsOf([$id]);

        $this->assertCount(2, $lines[$id]['purchase']);
        $this->assertSame(ReceivingAmounts::totals($lines[$id]['purchase']), $totals[$id]);
        $this->assertSame(1, bccomp('0.005', ltrim(bcsub($totals[$id]['total'], bcadd($totals[$id]['net'], $totals[$id]['tax'], 4), 4), '-'), 4), 'the total is the net and the tax to the cent');
    }

    public function testTotalsOf_ReadsInChunksAndGivesZeroToAPurchaseWithoutLines(): void
    {
        $item = $this->item('0.00');
        $ids  = [];

        for ($i = 0; $i < 3; ++$i) {
            $ids[] = $this->buy([$this->purchaseLine($item, 0, '1', '10.00')]);
        }

        $totals = (new PurchaseLines())->totalsOf([...$ids, 987654]);

        $this->assertSame([...$ids, 987654], array_keys($totals));

        foreach ($ids as $id) {
            $this->assertAmount('10.00', $totals[$id]['total']);
        }

        $this->assertAmount('0.00', $totals[987654]['total']);
    }

    public function testTotalsOf_KeepsAForeignPurchaseInTheDecimalsOfItsCurrency(): void
    {
        $item = $this->item('0.00');
        $id   = $this->buy([$this->purchaseLine($item, 0, '1', '10.005')]);

        $two   = (new PurchaseLines())->totalsOf([$id], [$id => 2])[$id]['total'];
        $three = (new PurchaseLines())->totalsOf([$id], [$id => 3])[$id]['total'];

        $this->assertSame(ReceivingAmounts::totals((new PurchaseLines())->of([$id])[$id]['purchase'], 2)['total'], $two);
        $this->assertSame(ReceivingAmounts::totals((new PurchaseLines())->of([$id])[$id]['purchase'], 3)['total'], $three);
    }
}
