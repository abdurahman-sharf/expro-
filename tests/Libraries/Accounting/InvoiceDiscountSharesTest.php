<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\InvoiceDiscountShares;
use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLine;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * How the discount on the whole of a purchase is shared out over its lines: in proportion to what
 * each comes to after its own discount, to the cent, with what is left on the last line that comes to
 * something, so the shares add up to the discount exactly and no line takes more than it costs.
 */
class InvoiceDiscountSharesTest extends CIUnitTestCase
{
    /**
     * @param list<string> $nets
     * @param list<string> $shares
     */
    #[DataProvider('splits')]
    public function testSplit_SharesInProportionToTheCostOfTheLinesAndPutsTheRemainderOnTheLast(array $nets, string $amount, array $shares): void
    {
        $this->assertSame($shares, InvoiceDiscountShares::split($nets, $amount));
    }

    /**
     * @return array<string, array{list<string>, string, list<string>}>
     */
    public static function splits(): array
    {
        return [
            'one line takes it all'                                         => [['900.0000'], '90.00', ['90.0000']],
            'two equal lines take half each'                                => [['100.0000', '100.0000'], '10.00', ['5.0000', '5.0000']],
            'the last line takes the cent that is left'                     => [['100.0000', '100.0000', '100.0000'], '10.00', ['3.3300', '3.3300', '3.3400']],
            'three lines of different cost'                                 => [['99.9900', '40.0200', '10.0000'], '15.00', ['10.0000', '4.0000', '1.0000']],
            'a line that costs nothing takes nothing'                       => [['0.0000', '50.0000', '50.0000'], '10.00', ['0.0000', '5.0000', '5.0000']],
            'a last line that costs nothing takes nothing'                  => [['50.0000', '50.0000', '0.0000'], '10.00', ['5.0000', '5.0000', '0.0000']],
            'nothing to share'                                              => [['50.0000', '50.0000'], '0.0000', ['0.0000', '0.0000']],
            'no lines'                                                      => [[], '10.00', []],
            'lines that cost nothing'                                       => [['0.0000', '0.0000'], '10.00', ['0.0000', '0.0000']],
            'a discount equal to the lines takes every line whole'          => [['33.3333', '66.6667'], '100.00', ['33.3333', '66.6667']],
            'a discount above the lines is held to them'                    => [['33.3333', '66.6667'], '500.00', ['33.3333', '66.6667']],
            'what the last line cannot give back comes off the line before' => [['0.4500', '35.4100', '38.6800', '46.1100', '0.0008'], '82.18', ['0.3100', '24.1200', '26.3500', '31.4000', '0.0000']],
            'what the last line cannot take goes to the line before'        => [['34.0700', '47.8700', '5.9100', '17.5400', '0.0027'], '23.39', ['7.5600', '10.6200', '1.3100', '3.8973', '0.0027']],
        ];
    }

    public function testSplit_AddsUpToTheDiscountExactlyAndNeverTakesMoreThanALineCostsWhateverTheLines(): void
    {
        mt_srand(20261220);

        for ($round = 0; $round < 400; $round++) {
            $nets = [];

            for ($line = 0, $count = mt_rand(1, 6); $line < $count; $line++) {
                $nets[] = number_format(mt_rand(0, 4) === 0 ? mt_rand(0, 40) / 10000 : mt_rand(0, 900000) / 100, 4, '.', '');
            }

            $total  = array_reduce($nets, static fn (string $sum, string $net): string => bcadd($sum, $net, 4), '0');
            $amount = number_format(mt_rand(0, max(0, (int) round((float) $total * 100))) / 100, 2, '.', '');
            $shares = InvoiceDiscountShares::split($nets, $amount);
            $given  = array_reduce($shares, static fn (string $sum, string $share): string => bcadd($sum, $share, 4), '0');
            $wanted = bccomp($amount, $total, 4) > 0 ? $total : $amount;

            $this->assertCount(count($nets), $shares);

            if (bccomp($total, '0', 4) > 0) {
                $this->assertSame(bcadd($wanted, '0', 4), $given, 'Nets ' . implode(',', $nets) . ' discount ' . $amount);
            }

            foreach ($shares as $index => $share) {
                $this->assertGreaterThanOrEqual(0, bccomp($share, '0', 4), 'A share is never negative');
                $this->assertLessThanOrEqual(0, bccomp($share, $nets[$index], 4), 'A line never takes more than it costs');
            }
        }
    }

    public function testForPurchase_SharesInProportionToWhatTheLinesCostAfterTheirOwnDiscounts(): void
    {
        $lines = [
            new ReceivingLine(1, 0, '10', '1', '100', '10', PERCENT, '15', 1, true, '0'),
            new ReceivingLine(2, 1, '5', '1', '20', '4', FIXED, '15', 1, true, '0'),
        ];

        $this->assertSame(['900.0000', '80.0000'], array_map(static fn ($line): string => ReceivingAmounts::lineNet($line), $lines));
        $this->assertSame(['9.1800', '0.8200'], InvoiceDiscountShares::forPurchase($lines, InvoiceDiscount::of(FIXED, '10')));
    }

    public function testForPurchase_GivesNothingWhenThereIsNoDiscount(): void
    {
        $lines = [new ReceivingLine(1, 0, '10', '1', '100', '0', PERCENT, '15', 1, true, '0')];

        $this->assertSame(['0.0000'], InvoiceDiscountShares::forPurchase($lines, InvoiceDiscount::none()));
    }
}
