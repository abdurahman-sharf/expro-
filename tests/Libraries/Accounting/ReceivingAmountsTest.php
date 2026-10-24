<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLine;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The amounts of a purchase line by the rules of the purchase screen: units times price, less a
 * discount that is a percentage of that or a fixed amount for the whole line, and the tax on what
 * is left. Every amount is positive, whichever way the goods go.
 */
class ReceivingAmountsTest extends CIUnitTestCase
{
    private static function line(string $quantity, string $price, string $discount = '0', int $discountType = PERCENT, string $tax = '0', string $perPack = '1'): ReceivingLine
    {
        return new ReceivingLine(1, 0, $quantity, $perPack, $price, $discount, $discountType, $tax, 1, true, '0');
    }

    /**
     * @return array<string, array{ReceivingLine, string, string, string, string}>
     */
    public static function lines(): array
    {
        return [
            'plain'                       => [self::line('20', '40'), '800.0000', '0.0000', '800.0000', '0.0000'],
            'with tax'                    => [self::line('10', '120', tax: '15'), '1200.0000', '0.0000', '1200.0000', '180.0000'],
            'percent discount'            => [self::line('10', '100', '10', tax: '15'), '1000.0000', '100.0000', '900.0000', '135.0000'],
            'fixed discount for the line' => [self::line('10', '100', '50', FIXED), '1000.0000', '50.0000', '950.0000', '0.0000'],
            'packs'                       => [self::line('3', '2.50', perPack: '12'), '90.0000', '0.0000', '90.0000', '0.0000'],
            'a return counts positive'    => [self::line('-2', '70', tax: '15'), '140.0000', '0.0000', '140.0000', '21.0000'],
            'tax kept unrounded'          => [self::line('1', '0.70', tax: '15'), '0.7000', '0.0000', '0.7000', '0.1050'],
            'discount above the line'     => [self::line('1', '10', '50', FIXED), '10.0000', '50.0000', '0.0000', '0.0000'],
            'a pack of zero is one unit'  => [self::line('4', '5', perPack: '0'), '20.0000', '0.0000', '20.0000', '0.0000'],
        ];
    }

    #[DataProvider('lines')]
    public function testAmounts_FollowTheRulesOfThePurchaseScreen(ReceivingLine $line, string $gross, string $discount, string $net, string $tax): void
    {
        $this->assertSame($gross, bcadd(ReceivingAmounts::gross($line), '0', 4), 'gross');
        $this->assertSame($discount, ReceivingAmounts::discount($line), 'discount');
        $this->assertSame($net, ReceivingAmounts::net($line), 'net');
        $this->assertSame($tax, ReceivingAmounts::tax($line), 'tax');
    }

    public function testUnits_AreAlwaysPositive(): void
    {
        $this->assertSame('24.000', bcadd(ReceivingAmounts::units(self::line('-2', '1', perPack: '12')), '0', 3));
        $this->assertSame('24.000', bcadd(ReceivingAmounts::units(self::line('2', '1', perPack: '12')), '0', 3));
    }
}
