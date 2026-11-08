<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\UnitLine;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class UnitLineTest extends CIUnitTestCase
{
    private function refusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The conversion should have been refused with ' . $error->value . '.');
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    public function testConvert_MultipliesTheQuantityAndDividesThePriceByTheFactor(): void
    {
        $line = UnitLine::convert(7, '12', '3', '120', 1);

        $this->assertSame('36.000', $line->baseQuantity);
        $this->assertSame('10.00', $line->basePrice);
        $this->assertSame('12.0000', $line->factor);
        $this->assertSame(7, $line->unitId);
    }

    public function testConvert_AcceptsAFractionalQuantityThatConvertsToWholeThousandths(): void
    {
        $line = UnitLine::convert(7, '12.5', '2.4', '125', 1);

        $this->assertSame('30.000', $line->baseQuantity);
        $this->assertSame('10.00', $line->basePrice);
    }

    public function testConvert_AcceptsTheFinestQuantityThatStillConvertsExactly(): void
    {
        $line = UnitLine::convert(7, '0.001', '1', '0.01', 1);

        $this->assertSame('0.001', $line->baseQuantity);
        $this->assertSame('10.00', $line->basePrice);
    }

    public function testConvert_RejectsAQuantityThatDoesNotConvertToWholeThousandths(): void
    {
        $this->refusedWith(AccountingError::UnitQuantityInexact, static fn (): UnitLine => UnitLine::convert(7, '0.0001', '1', '1', 4));
    }

    public function testConvert_RejectsAPriceThatDoesNotDivideToTheCent(): void
    {
        try {
            UnitLine::convert(7, '12', '1', '100', 3);
            $this->fail('A carton at 100 is 8.3333 a piece and must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::UnitPriceInexact, $exception->error);
            $this->assertSame(3, $exception->context['line']);
        }
    }

    public function testConvert_AcceptsAPriceOfZero(): void
    {
        $this->assertSame('0.00', UnitLine::convert(7, '12', '1', '0', 1)->basePrice);
    }

    public function testConvert_KeepsTheSignOfAReturn(): void
    {
        $line = UnitLine::convert(7, '12', '-2', '120', 1);

        $this->assertSame('-24.000', $line->baseQuantity);
        $this->assertSame('10.00', $line->basePrice);
    }

    public function testConvert_DividesACostToSixDecimalsWhenTheScaleIsGiven(): void
    {
        $line = UnitLine::convert(7, '8', '1', '1', 1, UnitLine::COST_SCALE);

        $this->assertSame('0.125000', $line->basePrice);
    }

    public function testConvert_RejectsACostThatDoesNotDivideToSixDecimals(): void
    {
        $this->refusedWith(AccountingError::UnitPriceInexact, static fn (): UnitLine => UnitLine::convert(7, '3', '1', '1', 1, UnitLine::COST_SCALE));
    }

    public function testBase_HasNoUnitAndAFactorOfOne(): void
    {
        $line = UnitLine::base('5', '2.50');

        $this->assertNull($line->unitId);
        $this->assertSame('1.0000', $line->factor);
        $this->assertSame('5', $line->baseQuantity);
        $this->assertSame(['unit_id' => null, 'unit_factor' => null, 'unit_quantity' => null, 'unit_price_entered' => null], $line->columns('unit_price_entered'));
    }

    public function testColumns_RemembersTheUnitTheFactorAndWhatWasEntered(): void
    {
        $columns = UnitLine::convert(7, '12', '2', '120', 1)->columns('unit_price_entered');

        $this->assertSame(['unit_id' => 7, 'unit_factor' => '12.0000', 'unit_quantity' => '2.000', 'unit_price_entered' => '120.000000'], $columns);
    }
}
