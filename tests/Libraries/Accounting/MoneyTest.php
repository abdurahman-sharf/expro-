<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\Money;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MoneyTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{string, int, string}>
     */
    public static function roundings(): array
    {
        return [
            'two decimals half up'         => ['1.005', 2, '1.01'],
            'two decimals just below half' => ['1.0049', 2, '1.00'],
            'zero decimals half up'        => ['88.5', 0, '89'],
            'zero decimals below'          => ['88.49', 0, '88'],
            'three decimals half up'       => ['365.9925', 3, '365.993'],
            'three decimals below'         => ['365.9924', 3, '365.992'],
            'negative half away from zero' => ['-1.005', 2, '-1.01'],
            'negative zero stays unsigned' => ['-0.004', 2, '0.00'],
            'already exact'                => ['10', 2, '10.00'],
        ];
    }

    #[DataProvider('roundings')]
    public function testRound_RoundsAHalfAwayFromZeroToTheDecimalsOfTheCurrency(string $amount, int $decimals, string $expected): void
    {
        $this->assertSame($expected, Money::round($amount, $decimals));
    }

    /**
     * @return array<string, array{string, string, int, string}>
     */
    public static function conversions(): array
    {
        return [
            'observed invoice line, the customer'  => ['268.33', '3.75', 2, '1006.24'],
            'observed invoice line, the tax'       => ['35.00', '3.75', 2, '131.25'],
            'observed invoice line, the goods'     => ['233.33', '3.75', 2, '874.99'],
            'zero decimals base'                   => ['23.5', '3.75', 0, '88'],
            'three decimals base'                  => ['100', '12.04917', 3, '1204.917'],
            'a long rate keeps its precision'      => ['100', '3.74473700', 2, '374.47'],
        ];
    }

    #[DataProvider('conversions')]
    public function testToBase_RoundsWhatTheCurrencyIsWorthToTheDecimalsOfTheBase(string $amount, string $rate, int $decimals, string $expected): void
    {
        $this->assertSame($expected, Money::toBase($amount, $rate, $decimals));
    }

    public function testFromBase_DividesByTheRateAndRoundsToTheDecimalsOfTheCurrency(): void
    {
        $this->assertSame('100.00', Money::fromBase('375.00', '3.75', 2));
        $this->assertSame('100', Money::fromBase('37.5', '0.375', 0));
        $this->assertSame('30.375', Money::fromBase('365.99', '12.04917', 3));
    }

    public function testFormat_GroupsThousandsKeepsTheDecimalsAndAppendsTheCode(): void
    {
        $this->assertSame('1,234,567.80 USD', Money::format('1234567.8', 2, 'USD'));
        $this->assertSame('3,704 JPY', Money::format('3703.5', 0, 'JPY'));
        $this->assertSame('30.375 KWD', Money::format('30.375', 3, 'KWD'));
        $this->assertSame('-1,000.00', Money::format('-1000', 2));
        $this->assertSame('0.00', Money::format('0', 2));
    }

    public function testPlain_ShowsTheDecimalsOfTheCurrencyWithoutSeparators(): void
    {
        $this->assertSame('1234.50', Money::plain('1234.5', 2));
        $this->assertSame('1235', Money::plain('1234.5', 0));
    }

    public function testToBase_OfADocumentCurrencyUsesItsRate(): void
    {
        $currency = new DocumentCurrency('USD', '3.75000000', 2);

        $this->assertSame('375.00', $currency->toBase('100.00', 2));
        $this->assertSame('377', $currency->toBase('100.40', 0));
    }
}
