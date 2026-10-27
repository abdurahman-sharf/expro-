<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReceivingLine;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * A line of the cart of the purchase screen turned into a line of a purchase, at the precision the
 * purchase keeps it in.
 */
class ReceivingLineTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function cartLine(): array
    {
        return [
            'item_id'            => 7,
            'line'               => 3,
            'quantity'           => -2.5,
            'receiving_quantity' => 12.0,
            'price'              => 65.704,
            'discount'           => 12.345,
            'discount_type'      => FIXED,
            'tax_percent'        => '15',
            'item_location'      => 2,
        ];
    }

    public function testOfCart_KeepsPricesAndDiscountsToTheCentAndQuantitiesAndRatesToTheThousandth(): void
    {
        $line = ReceivingLine::ofCart(self::cartLine());

        $this->assertSame([7, 3, '-2.500', '12.000', '65.70', '12.35', FIXED, '15.000', 2], [$line->itemId, $line->line, $line->quantity, $line->unitsPerPack, $line->unitPrice, $line->discount, $line->discountType, $line->taxPercent, $line->locationId]);
        $this->assertSame('0', $line->invoiceDiscount);
    }

    public function testOfCart_TakesTheShareItIsGiven(): void
    {
        $this->assertSame('4.50', ReceivingLine::ofCart(self::cartLine(), '4.50')->invoiceDiscount);
    }

    public function testOfCart_FillsWhatTheCartLeavesOut(): void
    {
        $line = ReceivingLine::ofCart(['item_id' => 1, 'quantity' => 3, 'price' => 10]);

        $this->assertSame([0, '1.000', '0.00', PERCENT, '0.000', 0], [$line->line, $line->unitsPerPack, $line->discount, $line->discountType, $line->taxPercent, $line->locationId]);
    }

    public function testUnits_CountThePacksOfALineAsTheUnitsInThemWithTheSignOfItsQuantity(): void
    {
        $this->assertSame('-30.000', bcadd(ReceivingLine::ofCart(self::cartLine())->units(), '0', 3));
    }
}
