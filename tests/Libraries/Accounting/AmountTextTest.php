<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AmountText;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * An amount as a form field holds it: at least two decimals, and every other decimal the books keep,
 * so that saving a form again hands back the amount it was filled with.
 */
class AmountTextTest extends CIUnitTestCase
{
    public function testForField_KeepsTwoDecimalsForAnAmountThatNeedsNoMore(): void
    {
        $this->assertSame('100.00', AmountText::forField('100'));
        $this->assertSame('100.00', AmountText::forField('100.0000'));
        $this->assertSame('100.10', AmountText::forField('100.1'));
        $this->assertSame('0.00', AmountText::forField('0'));
    }

    public function testForField_KeepsEveryDecimalTheBooksKeepWithoutRounding(): void
    {
        $this->assertSame('100.125', AmountText::forField('100.1250'));
        $this->assertSame('5.1234', AmountText::forField('5.1234'));
        $this->assertSame('0.0005', AmountText::forField('0.0005'));
        $this->assertSame('10.999', AmountText::forField('10.9990'));
    }

    public function testForField_WritesTheSameAmountAsTheOneItWasGiven(): void
    {
        foreach (['100.125', '1234.5678', '7.10', '99999999999.9999'] as $amount) {
            $this->assertSame(0, bccomp($amount, AmountText::forField($amount), 4), $amount);
        }
    }
}
