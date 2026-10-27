<?php

namespace Tests\Libraries;

use App\Libraries\InvoiceDiscount;
use App\Libraries\InvoiceDiscountPlan;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class InvoiceDiscountPlanTest extends CIUnitTestCase
{
    public function testApplyToPutsTheShareOnEachRowAndTakesItOffWhenThereIsNone(): void
    {
        $rows = [4 => ['item_id' => 1], 7 => ['item_id' => 2, 'invoice_discount_share' => '9.0000']];
        $plan = new InvoiceDiscountPlan(FIXED, '5', '5.0000', [4 => '2.0000', 7 => '3.0000']);

        $this->assertSame([4 => ['item_id' => 1, 'invoice_discount_share' => '2.0000'], 7 => ['item_id' => 2, 'invoice_discount_share' => '3.0000']], $plan->applyTo($rows));
        $this->assertSame([4 => ['item_id' => 1], 7 => ['item_id' => 2]], InvoiceDiscount::none()->applyTo($rows));
    }

    public function testIsEmptyIsTrueOnlyWhenTheDiscountComesToNothing(): void
    {
        $this->assertTrue(InvoiceDiscount::none()->isEmpty());
        $this->assertTrue((new InvoiceDiscountPlan(PERCENT, '0', '0.0000', []))->isEmpty());
        $this->assertFalse((new InvoiceDiscountPlan(FIXED, '0.01', '0.0100', [1 => '0.0100']))->isEmpty());
    }

    public function testShareGivesNothingForALineThePlanDoesNotKnow(): void
    {
        $plan = new InvoiceDiscountPlan(FIXED, '5', '5.0000', [4 => '5.0000']);

        $this->assertSame('5.0000', $plan->share(4));
        $this->assertSame('0.0000', $plan->share(9));
    }
}
