<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLine;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The amounts of a purchase line by the rules of the purchase screen: units times price, less a
 * discount that is a percentage of that or a fixed amount taken from every unit, and the tax on
 * what is left. Every amount is positive, whichever way the goods go.
 */
class ReceivingAmountsTest extends CIUnitTestCase
{
    private static function line(string $quantity, string $price, string $discount = '0', int $discountType = PERCENT, string $tax = '0', string $perPack = '1', string $share = '0', bool $saudi = false): ReceivingLine
    {
        return new ReceivingLine(1, 0, $quantity, $perPack, $price, $discount, $discountType, $tax, 1, true, '0', invoiceDiscount: $share, vatWorkedOnce: $saudi);
    }

    /**
     * @return array<string, array{ReceivingLine, string, string, string, string}>
     */
    public static function lines(): array
    {
        return [
            'plain'                      => [self::line('20', '40'), '800.0000', '0.0000', '800.0000', '0.0000'],
            'with tax'                   => [self::line('10', '120', tax: '15'), '1200.0000', '0.0000', '1200.0000', '180.0000'],
            'percent discount'           => [self::line('10', '100', '10', tax: '15'), '1000.0000', '100.0000', '900.0000', '135.0000'],
            'fixed discount per unit'    => [self::line('10', '100', '50', FIXED, tax: '15'), '1000.0000', '500.0000', '500.0000', '75.0000'],
            'fixed discount of packs'    => [self::line('2', '5', '1', FIXED, perPack: '12'), '120.0000', '24.0000', '96.0000', '0.0000'],
            'packs'                      => [self::line('3', '2.50', perPack: '12'), '90.0000', '0.0000', '90.0000', '0.0000'],
            'a return counts positive'   => [self::line('-2', '70', tax: '15'), '140.0000', '0.0000', '140.0000', '21.0000'],
            'tax kept unrounded'         => [self::line('1', '0.70', tax: '15'), '0.7000', '0.0000', '0.7000', '0.1050'],
            'discount above the line'    => [self::line('1', '10', '50', FIXED), '10.0000', '50.0000', '0.0000', '0.0000'],
            'a return takes the same'    => [self::line('-4', '10', '1', FIXED), '40.0000', '4.0000', '36.0000', '0.0000'],
            'a pack of zero is one unit' => [self::line('4', '5', perPack: '0'), '20.0000', '0.0000', '20.0000', '0.0000'],
            'fixed discount of a line saved before it was per unit' => [self::line('10', '100', '50', FIXED_LINE, tax: '15'), '1000.0000', '50.0000', '950.0000', '142.5000'],
            'the same line of packs'                                 => [self::line('2', '5', '1', FIXED_LINE, perPack: '12'), '120.0000', '1.0000', '119.0000', '0.0000'],
            'a return of such a line takes the same amount'          => [self::line('-4', '10', '5', FIXED_LINE), '40.0000', '5.0000', '35.0000', '0.0000'],
            'the share of the discount on the whole purchase comes off after the line discount' => [self::line('10', '100', '10', PERCENT, '15', share: '90'), '1000.0000', '100.0000', '810.0000', '121.5000'],
            'a share of nothing changes nothing'                     => [self::line('10', '100', '10', PERCENT, '15', share: '0.0000'), '1000.0000', '100.0000', '900.0000', '135.0000'],
            'a share above what the line costs is held to it'        => [self::line('1', '10', share: '50'), '10.0000', '0.0000', '0.0000', '0.0000'],
            'a share of the whole line leaves nothing to tax'        => [self::line('2', '50', tax: '15', share: '100'), '100.0000', '0.0000', '0.0000', '0.0000'],
            'a return takes its share as a positive amount'          => [self::line('-4', '100', '10', PERCENT, '15', share: '36'), '400.0000', '40.0000', '324.0000', '48.6000'],
            'a share given with a sign counts as an amount'          => [self::line('4', '10', share: '-5'), '40.0000', '0.0000', '35.0000', '0.0000'],
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

    public function testLineNet_StaysBeforeTheShareOfTheDiscountOnTheWholePurchase(): void
    {
        $line = self::line('10', '100', '10', PERCENT, '15', share: '90');

        $this->assertSame('900.0000', ReceivingAmounts::lineNet($line));
        $this->assertSame('90.0000', ReceivingAmounts::invoiceDiscount($line));
        $this->assertSame('810.0000', ReceivingAmounts::net($line));
    }

    public function testInvoiceDiscount_IsNothingForALineSavedWithoutOne(): void
    {
        $this->assertSame('0.0000', ReceivingAmounts::invoiceDiscount(self::line('10', '100')));
    }

    public function testTotals_AddTheLinesAfterTheirSharesAndKeepTheTotalToTwoDecimalsWithTheTaxUnrounded(): void
    {
        $lines = [
            self::line('3', '33.33', tax: '15', share: '10'),
            self::line('2', '20.01', tax: '5', share: '4'),
        ];

        $this->assertSame(['net' => '126.0100', 'tax' => '15.2995', 'total' => '141.31'], ReceivingAmounts::totals($lines));
    }

    public function testTotals_AreWhatTheyWereWhenNoLineTakesAShare(): void
    {
        $lines = [self::line('10', '100', '10', PERCENT, '15'), self::line('5', '20', '4', FIXED, '15')];

        $this->assertSame(['net' => '980.0000', 'tax' => '147.0000', 'total' => '1127.00'], ReceivingAmounts::totals($lines));
        $this->assertSame(ReceivingAmounts::totals($lines), ReceivingAmounts::totals(array_map(static fn (ReceivingLine $line): ReceivingLine => self::line($line->quantity, $line->unitPrice, $line->discount, $line->discountType, $line->taxPercent, share: '0'), $lines)));
    }

    public function testTotals_RoundTheTaxOfAPurchaseSavedUnderTheSaudiRulesToTheHalalaForEachRate(): void
    {
        $this->assertSame(['net' => '99.9990', 'tax' => '14.9999', 'total' => '115.00'], ReceivingAmounts::totals([self::line('3', '33.333', tax: '15')]));
        $this->assertSame(['net' => '99.9990', 'tax' => '15.0000', 'total' => '115.00'], ReceivingAmounts::totals([self::line('3', '33.333', tax: '15', saudi: true)]));

        $lines = [self::line('1', '0.10', tax: '15', saudi: true), self::line('1', '0.10', tax: '15', saudi: true), self::line('1', '0.10', tax: '15', saudi: true), self::line('1', '40', tax: '5', saudi: true)];

        $this->assertSame(['15.000' => ['net' => '0.3000', 'tax' => '0.0500'], '5.000' => ['net' => '40.0000', 'tax' => '2.0000']], ReceivingAmounts::byRate($lines));
        $this->assertSame('2.0500', ReceivingAmounts::totals($lines)['tax'], 'the tax of each rate on what its lines come to, not 0.0450 from the lines');
    }

    public function testByRate_SumsTheTaxOfTheLinesOfAPurchaseSavedBeforeTheSaudiRules(): void
    {
        $lines = [self::line('1', '0.10', tax: '15'), self::line('1', '0.10', tax: '15'), self::line('2', '5')];

        $this->assertSame(['15.000' => ['net' => '0.2000', 'tax' => '0.0300']], ReceivingAmounts::byRate($lines));
    }

    public function testUnits_AreAlwaysPositive(): void
    {
        $this->assertSame('24.000', bcadd(ReceivingAmounts::units(self::line('-2', '1', perPack: '12')), '0', 3));
        $this->assertSame('24.000', bcadd(ReceivingAmounts::units(self::line('2', '1', perPack: '12')), '0', 3));
    }
}
