<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\GoodsSplit;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class GoodsSplitTest extends CIUnitTestCase
{
    public function testParts_GivesASinglePartTheWholeTotalWhateverItsShareWas(): void
    {
        $this->assertSame(['a' => '10.0050'], GoodsSplit::parts(['a' => '10.00000000'], '10.0050'));
    }

    public function testParts_ReturnsNothingForNoShares(): void
    {
        $this->assertSame([], GoodsSplit::parts([], '10.0000'));
    }

    public function testParts_RoundsEachShareToFourDecimalsHalfUp(): void
    {
        $this->assertSame(['a' => '1.2346', 'b' => '2.0000'], GoodsSplit::parts(['a' => '1.23455000', 'b' => '2.00004999'], '3.2346'));
    }

    public function testParts_PutsWhatIsLeftOnTheLargestPart(): void
    {
        $this->assertSame(['a' => '100.0050', 'b' => '40.0000'], GoodsSplit::parts(['a' => '100.00000000', 'b' => '40.00000000'], '140.0050'));
    }

    public function testParts_PutsWhatIsLeftOnTheFirstOfTwoEqualLargestParts(): void
    {
        $this->assertSame(['a' => '0.3550', 'b' => '0.3500'], GoodsSplit::parts(['a' => '0.35000000', 'b' => '0.35000000'], '0.7050'));
    }

    public function testParts_TakesWhatIsOverOffTheLargestPart(): void
    {
        $this->assertSame(['a' => '99.9950', 'b' => '40.0000'], GoodsSplit::parts(['a' => '100.00000000', 'b' => '40.00000000'], '139.9950'));
    }

    public function testParts_CountsAShareThatIsNotPositiveAsNothing(): void
    {
        $this->assertSame(['a' => '10.0000', 'b' => '0.0000', 'c' => '0.0000'], GoodsSplit::parts(['a' => '10.00000000', 'b' => '-5.00000000', 'c' => '0.00000000'], '10.0000'));
    }

    public function testParts_ReturnsNothingWhenTheLargestPartCannotCarryWhatIsOver(): void
    {
        $this->assertNull(GoodsSplit::parts(['a' => '100.00000000', 'b' => '100.00000000'], '10.0000'));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function totalsProvider(): array
    {
        return [
            'thirds'      => [['a' => '33.33333333', 'b' => '33.33333333', 'c' => '33.33333333'], '100.0000'],
            'rounded up'  => [['a' => '0.00005000', 'b' => '0.00005000', 'c' => '0.00005000'], '0.0002'],
            'one cent'    => [['a' => '19.99500000', 'b' => '5.00500000'], '25.0100'],
            'nothing'     => [['a' => '0.00000000', 'b' => '0.00000000'], '0.0000'],
        ];
    }

    /**
     * @param array<string, numeric-string> $shares
     */
    #[DataProvider('totalsProvider')]
    public function testParts_AlwaysAddUpToTheTotal(array $shares, string $total): void
    {
        $parts = GoodsSplit::parts($shares, $total);

        $this->assertNotNull($parts);
        $this->assertSame(array_keys($shares), array_keys($parts));
        $this->assertSame($total, bcadd((string) array_reduce($parts, static fn (string $sum, string $part): string => bcadd($sum, $part, 4), '0'), '0', 4));
    }
}
