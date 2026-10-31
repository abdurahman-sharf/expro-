<?php

namespace Tests\Libraries;

use App\Libraries\HeaderCharges;
use App\Libraries\HeaderChargesProblem;
use App\Libraries\ShippingTaxOption;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class HeaderChargesTest extends CIUnitTestCase
{
    public function testResolve_AddsTheAdjustmentOnlyWhileTheTotalBeforeItIsAboveZero(): void
    {
        $charges = new HeaderCharges('Fee', '50');

        $this->assertSame('50.0000', $charges->resolve('100')->adjustment);
        $this->assertSame('0.0000', $charges->resolve('0')->adjustment);
        $this->assertSame('50.0000', (new HeaderCharges('Fee', '50', '10'))->resolve('0')->adjustment, 'the shipping makes the total positive');
    }

    public function testResolve_KeepsTheSignOfTheAdjustment(): void
    {
        $this->assertSame('-12.5000', (new HeaderCharges('Discount', '-12.5'))->resolve('100')->adjustment);
    }

    public function testResolve_WorksTheTaxOnShippingOnTopOfTheAmountWhenItIsNotIncluded(): void
    {
        $resolved = (new HeaderCharges(shipping: '100', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'))->resolve('50');

        $this->assertSame('15.0000', $resolved->shippingTax);
        $this->assertFalse($resolved->shippingTaxIncluded);
        $this->assertSame('115.0000', $resolved->addedToTotal());
        $this->assertSame('100.0000', $resolved->shippingNet());
    }

    public function testResolve_TakesTheTaxOutOfTheShippingWhenItIsIncluded(): void
    {
        $resolved = (new HeaderCharges(shipping: '100', shippingTaxOption: ShippingTaxOption::Included, shippingTaxPercent: '15'))->resolve('50');

        $this->assertSame('13.0435', $resolved->shippingTax);
        $this->assertTrue($resolved->shippingTaxIncluded);
        $this->assertSame('100.0000', $resolved->addedToTotal());
        $this->assertSame('86.9565', $resolved->shippingNet());
    }

    public function testResolve_RoundsTheTaxOnShippingHalfUpToFourDecimalsAndNotToTheCent(): void
    {
        $this->assertSame('0.0105', (new HeaderCharges(shipping: '0.07', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'))->resolve('1')->shippingTax);
        $this->assertSame('0.0500', (new HeaderCharges(shipping: '0.333', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'))->resolve('1')->shippingTax);
    }

    public function testResolve_TaxesNothingWhenThereIsNoShippingOrNoRateOrTheOptionIsNone(): void
    {
        $this->assertSame('0.0000', (new HeaderCharges(shipping: '0', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'))->resolve('10')->shippingTax);
        $this->assertSame('0.0000', (new HeaderCharges(shipping: '100', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '0'))->resolve('10')->shippingTax);
        $this->assertSame('0.0000', (new HeaderCharges(shipping: '100', shippingTaxOption: ShippingTaxOption::None, shippingTaxPercent: '15'))->resolve('10')->shippingTax);
    }

    public function testResolve_WorksOnTheSizeOfTheRunningTotalSoAReturnCountsLikeASale(): void
    {
        $charges = new HeaderCharges('Fee', '5', '10');

        $this->assertEquals($charges->resolve('100'), $charges->resolve('-100'));
    }

    public function testResolve_TrimsTheLabel(): void
    {
        $this->assertSame('Fee', (new HeaderCharges('  Fee ', '5'))->resolve('10')->adjustmentLabel);
    }

    /**
     * @return array<string, array{HeaderCharges, HeaderChargesProblem}>
     */
    public static function refusedProvider(): array
    {
        return [
            'adjustment is text'      => [new HeaderCharges('', 'abc'), HeaderChargesProblem::NotANumber],
            'shipping is text'        => [new HeaderCharges('', '0', '1e3'), HeaderChargesProblem::NotANumber],
            'too many decimals'       => [new HeaderCharges('', '1.23456'), HeaderChargesProblem::NotANumber],
            'too many digits'         => [new HeaderCharges('', '123456789012'), HeaderChargesProblem::NotANumber],
            'shipping is negative'    => [new HeaderCharges('', '0', '-1'), HeaderChargesProblem::NegativeShipping],
            'tax rate is above 100'   => [new HeaderCharges('', '0', '5', ShippingTaxOption::Added, '100.5'), HeaderChargesProblem::TaxPercentInvalid],
            'tax rate is text'        => [new HeaderCharges('', '0', '5', ShippingTaxOption::Added, 'x'), HeaderChargesProblem::TaxPercentInvalid],
            'unknown shipping option' => [new HeaderCharges('', '0', '5', ShippingTaxOption::None, '0', 7), HeaderChargesProblem::UnknownOption],
            'label too long'          => [new HeaderCharges(str_repeat('a', 101), '5'), HeaderChargesProblem::LabelTooLong],
            'adjustment below zero'   => [new HeaderCharges('', '-100.01'), HeaderChargesProblem::ExceedsTotal],
        ];
    }

    #[DataProvider('refusedProvider')]
    public function testProblem_RejectsWhatCannotBeAddedToTheDocument(HeaderCharges $charges, HeaderChargesProblem $expected): void
    {
        $this->assertSame($expected, $charges->problem('100'));
    }

    public function testProblem_AcceptsAnAdjustmentThatTakesTheTotalToExactlyZeroAndANegativeOne(): void
    {
        $this->assertNull((new HeaderCharges('Fee', '-100'))->problem('100'));
        $this->assertNull((new HeaderCharges('Fee', '-99.9999'))->problem('100'));
        $this->assertNull((new HeaderCharges('Fee', '-100'))->problem('-100'), 'a return is judged on its size');
    }

    public function testProblem_AcceptsTheAllowedShippingChoices(): void
    {
        foreach ([null, 1, 2, 3] as $option) {
            $this->assertNull((new HeaderCharges('', '0', '5', ShippingTaxOption::None, '0', $option))->problem('10'));
        }
    }

    public function testIsEmpty_IsTrueOnlyWhenNothingIsTyped(): void
    {
        $this->assertTrue(HeaderCharges::none()->isEmpty());
        $this->assertTrue((new HeaderCharges('', '0.0000', '0'))->isEmpty());
        $this->assertFalse((new HeaderCharges('', '-1'))->isEmpty());
        $this->assertFalse((new HeaderCharges('', '0', '0.01'))->isEmpty());
        $this->assertFalse((new HeaderCharges('', '0', '0', ShippingTaxOption::None, '0', 2))->isEmpty());
    }

    public function testHasAdjustmentAndHasShipping_SeeWhatIsThere(): void
    {
        $charges = new HeaderCharges('Fee', '-5', '0', ShippingTaxOption::None, '0', 2);

        $this->assertTrue($charges->hasAdjustment());
        $this->assertTrue($charges->hasShipping(), 'an address choice is part of the shipping block');
        $this->assertFalse(HeaderCharges::none()->hasAdjustment());
        $this->assertFalse(HeaderCharges::none()->hasShipping());
    }
}
