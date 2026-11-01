<?php

namespace Tests\Libraries;

use App\Libraries\ResolvedCharges;
use CodeIgniter\Test\CIUnitTestCase;

class ResolvedChargesTest extends CIUnitTestCase
{
    public function testShippingNet_TakesTheTaxOutOnlyWhenItIsIncluded(): void
    {
        $this->assertSame('86.9565', (new ResolvedCharges(shipping: '100.0000', shippingTax: '13.0435', shippingTaxIncluded: true))->shippingNet());
        $this->assertSame('100.0000', (new ResolvedCharges(shipping: '100.0000', shippingTax: '15.0000'))->shippingNet());
    }

    public function testAddedToTotal_CountsTheTaxOnTopAndTheSignedAdjustment(): void
    {
        $this->assertSame('105.0000', (new ResolvedCharges('Fee', '-10.0000', '100.0000', '15.0000'))->addedToTotal());
        $this->assertSame('90.0000', (new ResolvedCharges('Fee', '-10.0000', '100.0000', '13.0435', true))->addedToTotal());
    }

    public function testIsEmpty_IsTrueForNothingToAdd(): void
    {
        $this->assertTrue((new ResolvedCharges())->isEmpty());
        $this->assertFalse((new ResolvedCharges(adjustment: '0.0001'))->isEmpty());
        $this->assertFalse((new ResolvedCharges(shipping: '1.0000'))->isEmpty());
    }
}
