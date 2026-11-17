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
        $id   = $this->buy([$this->purchaseLine($item, 0, '3', '40.00', '15'), $this->purchaseLine($item, 1, '1', '10.00', '15', '10')]);

        $lines  = (new PurchaseLines())->of([$id]);
        $totals = (new PurchaseLines())->totalsOf([$id]);

        $this->assertCount(2, $lines[$id]['purchase']);
        $this->assertSame(ReceivingAmounts::totals($lines[$id]['purchase']), $totals[$id]);
        $this->assertAmount('129.00', $totals[$id]['net']);
        $this->assertAmount('19.35', $totals[$id]['tax']);
        $this->assertAmount('148.35', $totals[$id]['total']);
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
        $id   = $this->buy([$this->purchaseLine($item, 0, '1', '10.00', '12.345')]);

        $two   = (new PurchaseLines())->totalsOf([$id], [$id => 2])[$id]['total'];
        $three = (new PurchaseLines())->totalsOf([$id], [$id => 3])[$id]['total'];

        $this->assertAmount('11.23', $two);
        $this->assertAmount('11.235', $three);
        $this->assertNotSame($two, $three);
    }
}
