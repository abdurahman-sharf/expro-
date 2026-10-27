<?php

namespace Tests\Libraries;

use App\Libraries\Accounting\InvoiceRounding;
use App\Libraries\InvoiceDiscount;
use App\Libraries\InvoiceDiscountPlan;
use App\Libraries\InvoiceDiscountProblem;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ParityFixture;

class InvoiceDiscountTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{int, string, string, ?InvoiceDiscountProblem}>
     */
    public static function problemProvider(): array
    {
        return [
            'ten percent of 355'                 => [PERCENT, '10', '355.0000', null],
            'a fixed 20 on 355'                  => [FIXED, '20', '355.0000', null],
            'nothing at all'                     => [PERCENT, '0', '355.0000', null],
            'a hundred percent'                  => [PERCENT, '100', '355.0000', null],
            'the whole total as an amount'       => [FIXED, '355', '355.0000', null],
            'a negative percentage'              => [PERCENT, '-1', '355.0000', InvoiceDiscountProblem::Negative],
            'a negative amount'                  => [FIXED, '-0.01', '355.0000', InvoiceDiscountProblem::Negative],
            'more than a hundred percent'        => [PERCENT, '100.01', '355.0000', InvoiceDiscountProblem::PercentOver100],
            'a cent more than the total'         => [FIXED, '355.01', '355.0000', InvoiceDiscountProblem::ExceedsTotal],
            'something off a sale of nothing'    => [FIXED, '0.01', '0.0000', InvoiceDiscountProblem::ExceedsTotal],
            'text instead of a number'           => [FIXED, '1e3', '355.0000', InvoiceDiscountProblem::NotANumber],
            'a way of discounting nobody knows'  => [7, '10', '355.0000', InvoiceDiscountProblem::UnknownType],
        ];
    }

    #[DataProvider('problemProvider')]
    public function testProblem_JudgesTheDiscountAgainstWhatTheLinesComeTo(int $type, string $value, string $base, ?InvoiceDiscountProblem $expected): void
    {
        $this->assertSame($expected, InvoiceDiscount::problem($type, $value, $base, 2));
    }

    public function testProblem_JudgesAPercentageByWhatItComesToAfterRounding(): void
    {
        $this->assertNull(InvoiceDiscount::problem(PERCENT, '100', '0.0100', 2));
        $this->assertSame(InvoiceDiscountProblem::ExceedsTotal, InvoiceDiscount::problem(FIXED, '0.01', '0.0049', 2));
    }

    public function testAmount_TakesTheReferencePercentageOffWhatTheLinesComeTo(): void
    {
        $this->assertSame('35.5000', InvoiceDiscount::amount(PERCENT, '10', '355.0000', 2));
        $this->assertSame('20.0000', InvoiceDiscount::amount(FIXED, '20', '355.0000', 2));
    }

    public function testAmount_RoundsHalfUpToTheDecimalsTheShopWorksIn(): void
    {
        $this->assertSame('33.3300', InvoiceDiscount::amount(PERCENT, '10', '333.3300', 2));
        $this->assertSame('0.0100', InvoiceDiscount::amount(PERCENT, '10', '0.1000', 2));
        $this->assertSame('0.0000', InvoiceDiscount::amount(PERCENT, '10', '0.0400', 2));
        $this->assertSame('33.3330', InvoiceDiscount::amount(PERCENT, '10', '333.3300', 3));
        $this->assertSame('20.0100', InvoiceDiscount::amount(FIXED, '20.005', '355.0000', 2));
    }

    public function testLineBase_TakesTheDiscountOfTheLineOffWhatItSellsFor(): void
    {
        $this->assertSame('135.0000', InvoiceDiscount::lineBase('2', '75', '10', PERCENT, 2));
        $this->assertSame('195.0000', InvoiceDiscount::lineBase('3', '75', '10', FIXED, 2));
        $this->assertSame('220.0000', InvoiceDiscount::lineBase('1', '220', '0', PERCENT, 2));
    }

    public function testLineBase_ComesToNothingForAReturnAFreeItemOrAFullDiscount(): void
    {
        $this->assertSame('0.0000', InvoiceDiscount::lineBase('-1', '220', '0', PERCENT, 2));
        $this->assertSame('0.0000', InvoiceDiscount::lineBase('1', '0', '0', PERCENT, 2));
        $this->assertSame('0.0000', InvoiceDiscount::lineBase('1', '50', '100', PERCENT, 2));
        $this->assertSame('0.0000', InvoiceDiscount::lineBase('1', '50', '60', FIXED, 2));
    }

    public function testAllocate_SharesTheReferenceFixedDiscountInProportionToTheLines(): void
    {
        $shares = InvoiceDiscount::allocate(['a' => '135.0000', 'b' => '220.0000'], '20.0000', 2);

        $this->assertSame(['a' => '7.6100', 'b' => '12.3900'], $shares);
    }

    public function testAllocate_SharesThePercentageDiscountExactlyInProportion(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '135.0000', 2 => '220.0000'], '35.5000', 2);

        $this->assertSame([1 => '13.5000', 2 => '22.0000'], $shares);
    }

    public function testAllocate_LeavesWhatRoundingLeavesOverOnTheLastLine(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '10.0000', 2 => '10.0000', 3 => '10.0000'], '10.0000', 2);

        $this->assertSame(['3.3300', '3.3300', '3.3400'], array_values($shares));
        $this->assertSame('10.0000', $this->sum($shares));
    }

    public function testAllocate_GivesNothingToALineThatComesToNothing(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '50.0000', 2 => '0.0000', 3 => '50.0000', 4 => '0.0000'], '10.0000', 2);

        $this->assertSame([1 => '5.0000', 2 => '0.0000', 3 => '5.0000', 4 => '0.0000'], $shares);
    }

    public function testAllocate_GivesNothingWhenThereIsNoDiscountOrNothingToTakeItFrom(): void
    {
        $this->assertSame([1 => '0.0000', 2 => '0.0000'], InvoiceDiscount::allocate([1 => '5.0000', 2 => '5.0000'], '0.0000', 2));
        $this->assertSame([1 => '0.0000'], InvoiceDiscount::allocate([1 => '0.0000'], '5.0000', 2));
        $this->assertSame([], InvoiceDiscount::allocate([], '5.0000', 2));
    }

    public function testAllocate_TakesAWholeSaleBackToTheLastCent(): void
    {
        $bases  = [1 => '135.0000', 2 => '220.0000', 3 => '9.9900'];
        $shares = InvoiceDiscount::allocate($bases, '364.9900', 2);

        $this->assertSame($bases, $shares);
    }

    public function testAllocate_TakesNoMoreOffALineThanItComesToNorLessThanNothing(): void
    {
        mt_srand(20261240);

        for ($round = 0; $round < 400; $round++) {
            $bases = [];

            for ($line = 0, $count = mt_rand(1, 7); $line < $count; $line++) {
                $bases[$line] = mt_rand(0, 5) === 0 ? '0.0000' : number_format(mt_rand(1, 2000000) / 10000, 4, '.', '');
            }

            $total = '0';

            foreach ($bases as $base) {
                $total = bcadd($total, $base, 4);
            }

            $amount = bcmul($total, number_format(mt_rand(0, 10000) / 10000, 4, '.', ''), 2);
            $shares = InvoiceDiscount::allocate($bases, $amount, 2);

            foreach ($bases as $key => $base) {
                $this->assertGreaterThanOrEqual(0, bccomp($shares[$key], '0', 4), "round {$round}");
                $this->assertLessThanOrEqual(0, bccomp($shares[$key], $base, 4), "round {$round}");
            }

            $this->assertSame(bccomp($total, '0', 4) === 0 ? '0.0000' : bcadd($amount, '0', 4), $this->sum($shares), "round {$round}");
        }
    }

    public function testAllocate_MovesAHairOfRoundingOffALineThatWouldGoBelowNothing(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '0.0001', 2 => '0.0001', 3 => '0.0001', 4 => '0.0001'], '0.0003', 4);

        $this->assertSame('0.0003', $this->sum($shares));

        foreach ($shares as $share) {
            $this->assertGreaterThanOrEqual(0, bccomp($share, '0', 4));
            $this->assertLessThanOrEqual(0, bccomp($share, '0.0001', 4));
        }
    }

    public function testAllocate_MovesAHairOfRoundingOffALineThatWouldGoBelowNothingAtTheDecimalsOfTheShop(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '0.03', 2 => '0.03', 3 => '0.03', 4 => '0.01'], '0.05', 2);

        $this->assertSame('0.0500', $this->sum($shares));

        foreach ([1 => '0.03', 2 => '0.03', 3 => '0.03', 4 => '0.01'] as $key => $base) {
            $this->assertGreaterThanOrEqual(0, bccomp($shares[$key], '0', 4));
            $this->assertLessThanOrEqual(0, bccomp($shares[$key], $base, 4));
        }
    }

    public function testAllocate_RoundsEveryShareToTheDecimalsTheShopWorksIn(): void
    {
        mt_srand(20261241);

        foreach ([0, 1, 2] as $decimals) {
            for ($round = 0; $round < 200; $round++) {
                $bases = [];

                for ($line = 0, $count = mt_rand(2, 6); $line < $count; $line++) {
                    $bases[$line] = number_format(mt_rand(1, 200000) / (10 ** $decimals), 4, '.', '');
                }

                $total  = array_reduce($bases, static fn (string $sum, string $base): string => bcadd($sum, $base, 4), '0');
                $amount = InvoiceDiscount::amount(PERCENT, (string) mt_rand(1, 99), $total, $decimals);

                foreach (InvoiceDiscount::allocate($bases, $amount, $decimals) as $key => $share) {
                    $this->assertSame(0, bccomp($share, bcadd($share, '0', $decimals), 4), "{$decimals} decimals, round {$round}, line {$key}: {$share}");
                }
            }
        }
    }

    public function testReturnShares_TakesBackTheShareOfTheUnitsReturned(): void
    {
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $this->assertSame([1 => '2.0000'], InvoiceDiscount::returnShares([1 => [10, '1']], $origin, 2));
        $this->assertSame([1 => '6.0000'], InvoiceDiscount::returnShares([1 => [10, '3']], $origin, 2));
    }

    public function testReturnShares_GivesTheLastReturnOfAnItemAllThatIsLeftOfItsShare(): void
    {
        $origin = [10 => ['sold' => '3', 'share' => '10.0000', 'returned' => '2', 'returnedShare' => '6.6600']];

        $this->assertSame([1 => '3.3400'], InvoiceDiscount::returnShares([1 => [10, '1']], $origin, 2));
    }

    public function testReturnShares_AddsUpToExactlyTheShareOfTheSaleAcrossAllItsReturns(): void
    {
        $given    = '0.0000';
        $returned = '0';
        $taken    = [];

        foreach (['1', '1', '1'] as $units) {
            $origin   = [10 => ['sold' => '3', 'share' => '10.0000', 'returned' => $returned, 'returnedShare' => $given]];
            $share    = InvoiceDiscount::returnShares([1 => [10, $units]], $origin, 2)[1];
            $taken[]  = $share;
            $given    = bcadd($given, $share, 4);
            $returned = bcadd($returned, $units, 3);
        }

        $this->assertSame(['3.3300', '3.3300', '3.3400'], $taken);
        $this->assertSame('10.0000', $given);
    }

    public function testReturnShares_TakesBackNoMoreThanIsLeft(): void
    {
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '1', 'returnedShare' => '7.9000']];

        $this->assertSame([1 => '0.1000'], InvoiceDiscount::returnShares([1 => [10, '2']], $origin, 2));
    }

    public function testReturnShares_GivesNothingBackForAnItemTheSaleNeverHad(): void
    {
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $this->assertSame([1 => '0.0000', 2 => '2.0000'], InvoiceDiscount::returnShares([1 => [99, '1'], 2 => [10, '1']], $origin, 2));
    }

    public function testReturnShares_SharesAnItemAcrossTheLinesItIsReturnedOnInProportionToTheirUnits(): void
    {
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $this->assertSame([1 => '2.0000', 2 => '4.0000'], InvoiceDiscount::returnShares([1 => [10, '1'], 2 => [10, '2']], $origin, 2));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceDiscountProvider(): array
    {
        return ParityFixture::cases('T-04', 'invoice_discount');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceDiscountProvider')]
    public function testForSale_GivesTheTaxAndTheRevenueTheReferenceSystemPostedForTheInvoice(array $case): void
    {
        $rows = [];

        foreach ($case['inputs']['lines'] as $index => $line) {
            $rows[$index + 1] = ['item_id' => $index + 1, 'quantity' => $line['quantity'], 'price' => $line['price'], 'discount' => $line['discount'], 'discount_type' => $line['discount_type'] === 'fixed' ? FIXED : PERCENT];
        }

        $discount = $case['inputs']['invoice_discount'];
        $plan     = InvoiceDiscount::forSale($rows, $discount['type'] === 'fixed' ? FIXED : PERCENT, $discount['value'], 2);

        $this->assertInstanceOf(InvoiceDiscountPlan::class, $plan);

        $bases = InvoiceDiscount::basesOf($rows, 2, false);
        $tax   = '0';

        foreach ($bases as $key => $base) {
            $tax = bcadd($tax, bcmul(bcsub($base, $plan->share($key), 4), bcdiv($case['inputs']['tax_rate'], '100', 8), 8), 8);
        }

        $net   = bcsub(InvoiceDiscount::baseOf($rows, 2), $plan->amount, 4);
        $split = InvoiceRounding::split($net, $tax);
        $left  = $case['expected'];

        foreach (['net' => $net, 'tax' => $split['tax'], 'total' => $split['total'], 'revenue' => $split['revenue']] as $name => $actual) {
            $this->assertSame(0, bccomp($actual, $left[$name], 4), "{$case['id']} {$name}");
        }

        $this->assertSame($case['classification'] === 'exact' ? '0.00' : '0.01', bcsub($split['total'], $case['reference']['total'], 2), $case['id']);
    }

    public function testForSale_TakesNothingOffForADiscountOfNothingAndSavesNoShares(): void
    {
        $rows = [1 => ['item_id' => 1, 'quantity' => '2', 'price' => '50', 'discount' => '0', 'discount_type' => PERCENT]];

        $plan = InvoiceDiscount::forSale($rows, PERCENT, '0', 2);

        $this->assertInstanceOf(InvoiceDiscountPlan::class, $plan);
        $this->assertTrue($plan->isEmpty());
        $this->assertNull($plan->type);
        $this->assertSame($rows, $plan->applyTo($rows));
    }

    public function testForSale_RefusesADiscountThatCannotBeTakenOffWhatTheLinesComeTo(): void
    {
        $rows = [1 => ['item_id' => 1, 'quantity' => '1', 'price' => '100', 'discount' => '10', 'discount_type' => FIXED]];

        $this->assertSame(InvoiceDiscountProblem::ExceedsTotal, InvoiceDiscount::forSale($rows, FIXED, '90.01', 2));
        $this->assertInstanceOf(InvoiceDiscountPlan::class, InvoiceDiscount::forSale($rows, FIXED, '90', 2));
        $this->assertSame(InvoiceDiscountProblem::Negative, InvoiceDiscount::forSale($rows, PERCENT, '-5', 2));
    }

    public function testForSale_TakesTheDiscountsOfTheLinesOffBeforeItWorksTheDiscountOut(): void
    {
        $rows = [
            1 => ['item_id' => 1, 'quantity' => '2', 'price' => '75', 'discount' => '10', 'discount_type' => PERCENT],
            2 => ['item_id' => 2, 'quantity' => '1', 'price' => '220', 'discount' => '0', 'discount_type' => PERCENT],
        ];

        $plan = InvoiceDiscount::forSale($rows, PERCENT, '10', 2);

        $this->assertSame('35.5000', $plan->amount);
        $this->assertSame([1 => '13.5000', 2 => '22.0000'], $plan->shares);
    }

    public function testForReturn_TakesBackTheShareOfTheUnitsWithTheSignOfTheReturn(): void
    {
        $rows   = [1 => ['item_id' => 10, 'quantity' => '-1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT]];
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $plan = InvoiceDiscount::forReturn($rows, $origin, 2);

        $this->assertSame(['1' => '-2.0000'], array_combine(array_map('strval', array_keys($plan->shares)), $plan->shares));
        $this->assertSame('2.0000', $plan->amount);
        $this->assertSame(FIXED, $plan->type);
        $this->assertSame('2.0000', $plan->value);
    }

    public function testForReturn_ComesToNothingWhenTheSaleItComesFromWasGivenNoDiscount(): void
    {
        $rows   = [1 => ['item_id' => 10, 'quantity' => '-1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT]];
        $origin = [10 => ['sold' => '4', 'share' => '0.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $this->assertTrue(InvoiceDiscount::forReturn($rows, $origin, 2)->isEmpty());
        $this->assertTrue(InvoiceDiscount::forReturn($rows, [], 2)->isEmpty());
    }

    public function testForReturn_TakesBackNoMoreThanTheLineItselfComesToWhenItsPriceWasLowered(): void
    {
        $rows   = [1 => ['item_id' => 10, 'quantity' => '-1', 'price' => '1', 'discount' => '0', 'discount_type' => PERCENT]];
        $origin = [10 => ['sold' => '1', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $this->assertSame('1.0000', InvoiceDiscount::forReturn($rows, $origin, 2)->amount);
    }

    public function testNormalise_DropsTheZerosStoredAfterThePoint(): void
    {
        $this->assertSame('10', InvoiceDiscount::normalise('10.0000'));
        $this->assertSame('20.5', InvoiceDiscount::normalise('20.5000'));
        $this->assertSame('100', InvoiceDiscount::normalise('100'));
        $this->assertSame('0', InvoiceDiscount::normalise('0.0000'));
        $this->assertSame('0.05', InvoiceDiscount::normalise('0.0500'));
    }

    public function testSqlShare_TreatsASaleWithoutADiscountAsCarryingNone(): void
    {
        $this->assertSame('IFNULL(sales_items.invoice_discount_share, 0)', InvoiceDiscount::sqlShare('sales_items'));
        $this->assertSame('IFNULL(`ospos_sales_items`.invoice_discount_share, 0)', InvoiceDiscount::sqlShare('`ospos_sales_items`'));
    }

    /**
     * @param array<int|string, string> $shares
     */
    private function sum(array $shares): string
    {
        $sum = '0';

        foreach ($shares as $share) {
            $sum = bcadd($sum, $share, 4);
        }

        return $sum;
    }
}
