<?php

namespace Tests\Libraries;

use App\Libraries\InvoiceDiscount;
use App\Libraries\InvoiceDiscountProblem;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
        $shares = InvoiceDiscount::allocate(['a' => '135.0000', 'b' => '220.0000'], '20.0000');

        $this->assertSame(['a' => '7.6056', 'b' => '12.3944'], $shares);
    }

    public function testAllocate_SharesThePercentageDiscountExactlyInProportion(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '135.0000', 2 => '220.0000'], '35.5000');

        $this->assertSame([1 => '13.5000', 2 => '22.0000'], $shares);
    }

    public function testAllocate_LeavesWhatRoundingLeavesOverOnTheLastLine(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '10.0000', 2 => '10.0000', 3 => '10.0000'], '10.0000');

        $this->assertSame(['3.3333', '3.3333', '3.3334'], array_values($shares));
        $this->assertSame('10.0000', $this->sum($shares));
    }

    public function testAllocate_GivesNothingToALineThatComesToNothing(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '50.0000', 2 => '0.0000', 3 => '50.0000', 4 => '0.0000'], '10.0000');

        $this->assertSame([1 => '5.0000', 2 => '0.0000', 3 => '5.0000', 4 => '0.0000'], $shares);
    }

    public function testAllocate_GivesNothingWhenThereIsNoDiscountOrNothingToTakeItFrom(): void
    {
        $this->assertSame([1 => '0.0000', 2 => '0.0000'], InvoiceDiscount::allocate([1 => '5.0000', 2 => '5.0000'], '0.0000'));
        $this->assertSame([1 => '0.0000'], InvoiceDiscount::allocate([1 => '0.0000'], '5.0000'));
        $this->assertSame([], InvoiceDiscount::allocate([], '5.0000'));
    }

    public function testAllocate_TakesAWholeSaleBackToTheLastCent(): void
    {
        $bases  = [1 => '135.0000', 2 => '220.0000', 3 => '9.9900'];
        $shares = InvoiceDiscount::allocate($bases, '364.9900');

        $this->assertSame($bases, $shares);
    }

    public function testAllocate_NeverTakesMoreOffALineThanItComesToNorLessThanNothing(): void
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
            $shares = InvoiceDiscount::allocate($bases, $amount);

            foreach ($bases as $key => $base) {
                $this->assertGreaterThanOrEqual(0, bccomp($shares[$key], '0', 4), "round {$round}");
                $this->assertLessThanOrEqual(0, bccomp($shares[$key], $base, 4), "round {$round}");
            }

            $this->assertSame(bccomp($total, '0', 4) === 0 ? '0.0000' : bcadd($amount, '0', 4), $this->sum($shares), "round {$round}");
        }
    }

    public function testAllocate_MovesAHairOfRoundingOffALineThatWouldGoBelowNothing(): void
    {
        $shares = InvoiceDiscount::allocate([1 => '0.0001', 2 => '0.0001', 3 => '0.0001', 4 => '0.0001'], '0.0003');

        $this->assertSame('0.0003', $this->sum($shares));

        foreach ($shares as $share) {
            $this->assertGreaterThanOrEqual(0, bccomp($share, '0', 4));
            $this->assertLessThanOrEqual(0, bccomp($share, '0.0001', 4));
        }
    }

    public function testReturnShares_TakesBackTheShareOfTheUnitsReturned(): void
    {
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $this->assertSame([1 => '2.0000'], InvoiceDiscount::returnShares([1 => [10, '1']], $origin));
        $this->assertSame([1 => '6.0000'], InvoiceDiscount::returnShares([1 => [10, '3']], $origin));
    }

    public function testReturnShares_GivesTheLastReturnOfAnItemAllThatIsLeftOfItsShare(): void
    {
        $origin = [10 => ['sold' => '3', 'share' => '10.0000', 'returned' => '2', 'returnedShare' => '6.6666']];

        $this->assertSame([1 => '3.3334'], InvoiceDiscount::returnShares([1 => [10, '1']], $origin));
    }

    public function testReturnShares_AddsUpToExactlyTheShareOfTheSaleAcrossAllItsReturns(): void
    {
        $given   = '0.0000';
        $returned = '0';

        foreach (['1', '1', '1'] as $units) {
            $origin = [10 => ['sold' => '3', 'share' => '10.0000', 'returned' => $returned, 'returnedShare' => $given]];
            $share  = InvoiceDiscount::returnShares([1 => [10, $units]], $origin)[1];
            $given    = bcadd($given, $share, 4);
            $returned = bcadd($returned, $units, 3);
        }

        $this->assertSame('10.0000', $given);
    }

    public function testReturnShares_NeverTakesBackMoreThanIsLeft(): void
    {
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '1', 'returnedShare' => '7.9000']];

        $this->assertSame([1 => '0.1000'], InvoiceDiscount::returnShares([1 => [10, '2']], $origin));
    }

    public function testReturnShares_GivesNothingBackForAnItemTheSaleNeverHad(): void
    {
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $this->assertSame([1 => '0.0000', 2 => '2.0000'], InvoiceDiscount::returnShares([1 => [99, '1'], 2 => [10, '1']], $origin));
    }

    public function testReturnShares_SharesAnItemAcrossTheLinesItIsReturnedOnInProportionToTheirUnits(): void
    {
        $origin = [10 => ['sold' => '4', 'share' => '8.0000', 'returned' => '0', 'returnedShare' => '0.0000']];

        $this->assertSame([1 => '2.0000', 2 => '4.0000'], InvoiceDiscount::returnShares([1 => [10, '1'], 2 => [10, '2']], $origin));
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
