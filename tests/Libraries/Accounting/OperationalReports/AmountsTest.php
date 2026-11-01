<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\Amounts;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The arithmetic of the figures of a report: four decimals, negatives, sums, and the share of a whole as a
 * percentage with two decimals.
 */
class AmountsTest extends CIUnitTestCase
{
    public function testAddAndSub_KeepFourDecimalsAndHandleNegatives(): void
    {
        $this->assertSame('0.3000', Amounts::add('0.1', '0.2'));
        $this->assertSame('-0.1000', Amounts::sub('0.1', '0.2'));
        $this->assertSame('0.0000', Amounts::sub('5', '5'));
    }

    public function testNegateAndSigned_FollowTheDirectionOfTheDocument(): void
    {
        $this->assertSame('-5.0000', Amounts::negate('5'));
        $this->assertSame('5.0000', Amounts::negate('-5'));
        $this->assertSame('0.0000', Amounts::negate('0'));
        $this->assertSame('5.0000', Amounts::signed('5', 1));
        $this->assertSame('-5.0000', Amounts::signed('5', -1));
    }

    public function testSum_AddsAListAndGivesZeroForNone(): void
    {
        $this->assertSame('6.0000', Amounts::sum(['1', '2.5', '2.5']));
        $this->assertSame('0.0000', Amounts::sum([]));
        $this->assertTrue(Amounts::isZero(Amounts::sum(['1', '-1'])));
        $this->assertSame(1, Amounts::compare('1.0001', '1'));
    }

    public function testPercent_RoundsHalfAwayFromZeroAndGivesNothingForAWholeOfNothing(): void
    {
        $this->assertSame('76.19', Amounts::percent('480', '630'));
        $this->assertSame('23.81', Amounts::percent('150', '630'));
        $this->assertSame('-50.00', Amounts::percent('-1', '2'));
        $this->assertSame('0.00', Amounts::percent('5', '0'));
        $this->assertSame('0.01', Amounts::percent('1', '10000'));
        $this->assertSame('0.00', Amounts::percent('0', '5'));
    }
}
