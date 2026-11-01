<?php

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;
use PHPUnit\Framework\Attributes\DataProvider;

class LocaleHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../app/Helpers/locale_helper.php';

        $config           = new OSPOS();
        $config->settings = ['dateformat' => 'Y-m-d'];
        Factories::injectMock('config', OSPOS::class, $config);
    }

    public function testValidDateReturnsTrue(): void
    {
        $this->assertTrue(isValidDate('2024-06-10'));
    }

    public function testInvalidDateFormatReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('10/06/2024'));
    }

    public function testImpossibleDateReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2024-13-01'));
    }

    public function testPhpDateOverflowReturnsFalse(): void
    {
        // PHP silently overflows Feb 30 → Mar 1; the format()===candidate check catches this
        $this->assertFalse(isValidDate('2024-02-30'));
    }

    public function testEmptyStringReturnsFalse(): void
    {
        $this->assertFalse(isValidDate(''));
    }

    public function testLeapDayValidReturnsTrue(): void
    {
        $this->assertTrue(isValidDate('2024-02-29'));
    }

    public function testLeapDayInvalidYearReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2023-02-29'));
    }

    public function testPartialDateReturnsFalse(): void
    {
        $this->assertFalse(isValidDate('2024-06'));
    }

    private function useLanguage(string $languageCode): void
    {
        $config           = new OSPOS();
        $config->settings = ['dateformat' => 'Y-m-d', 'language_code' => $languageCode];
        Factories::injectMock('config', OSPOS::class, $config);
    }

    public function testIsRtl_ReturnsTrueForArabicVariants(): void
    {
        $this->useLanguage('ar-EG');
        $this->assertTrue(isRtl());

        $this->useLanguage('ar-LB');
        $this->assertTrue(isRtl());
    }

    public function testIsRtl_ReturnsTrueForOtherRightToLeftLanguages(): void
    {
        foreach (['fa', 'he', 'ur', 'ckb'] as $languageCode) {
            $this->useLanguage($languageCode);
            $this->assertTrue(isRtl(), $languageCode);
        }
    }

    public function testIsRtl_ReturnsFalseForLeftToRightLanguages(): void
    {
        foreach (['en', 'en-GB', 'de-DE', 'zh-Hans'] as $languageCode) {
            $this->useLanguage($languageCode);
            $this->assertFalse(isRtl(), $languageCode);
        }
    }

    public function testTextDirection_ReturnsRtlForArabic(): void
    {
        $this->useLanguage('ar-EG');

        $this->assertSame('rtl', textDirection());
    }

    public function testTextDirection_ReturnsLtrForEnglish(): void
    {
        $this->useLanguage('en');

        $this->assertSame('ltr', textDirection());
    }

    public function testNumberFormatterLocale_AppendsLatinNumberingSystem(): void
    {
        $this->assertSame('ar_EG@numbers=latn', numberFormatterLocale('ar_EG'));
    }

    public function testNumberFormatterLocale_KeepsExplicitNumberingSystem(): void
    {
        $this->assertSame('ar_EG@numbers=arab', numberFormatterLocale('ar_EG@numbers=arab'));
    }

    public function testNumberFormatterLocale_FormatsArabicNumbersWithLatinDigits(): void
    {
        $formatter = new NumberFormatter(numberFormatterLocale('ar_EG'), NumberFormatter::DECIMAL);
        $formatter->setAttribute(NumberFormatter::GROUPING_USED, 0);

        $this->assertMatchesRegularExpression('/^1234[.,]5$/', $formatter->format(1234.5));
    }

    public function testDateformatFlatpickr_ConvertsMeridiemAndSecondTokens(): void
    {
        $this->assertSame('d/m/Y G:i:S K', dateformatFlatpickr('d/m/Y h:i:s A'));
    }

    public function testDateformatFlatpickr_DropsOrdinalSuffixToken(): void
    {
        $this->assertSame('j M Y', dateformatFlatpickr('jS M Y'));
    }

    public function testDateformatFlatpickr_KeepsTwentyFourHourTokens(): void
    {
        $this->assertSame('Y-m-d H:i', dateformatFlatpickr('Y-m-d H:i'));
    }

    private function useNumbers(int $currencyDecimals): void
    {
        $config           = new OSPOS();
        $config->settings = ['dateformat' => 'Y-m-d', 'number_locale' => 'en_US', 'currency_symbol' => '$', 'currency_decimals' => $currencyDecimals, 'thousands_separator' => '1'];
        Factories::injectMock('config', OSPOS::class, $config);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function exactAmountProvider(): array
    {
        return [
            'whole price keeps the currency decimals'    => ['9.0000', 2, '9.00'],
            'one decimal is padded to the currency ones' => ['0.1000', 2, '0.10'],
            'a third decimal is kept'                    => ['0.1250', 2, '0.125'],
            'four decimals are kept'                     => ['12.3456', 2, '12.3456'],
            'more currency decimals than the price has'  => ['1.5', 3, '1.500'],
            'currency decimals above four are not cut'   => ['1.5', 5, '1.50000'],
            'thousands are grouped'                      => ['1234.5678', 2, '1,234.5678'],
        ];
    }

    #[DataProvider('exactAmountProvider')]
    public function testToCurrencyExact_ShowsTheDecimalsAPriceHasWithTheCurrencyDecimalsAsMinimum(string $amount, int $decimals, string $expected): void
    {
        $this->useNumbers($decimals);

        $this->assertSame($expected, to_currency_exact($amount));
    }

    public function testToCurrencyExact_ShowsNothingForNothing(): void
    {
        $this->useNumbers(2);

        $this->assertSame('', to_currency_exact(null));
    }

    public function testToCurrencyExact_GivesBackAPriceThatParseDecimalsReadsAsTheSamePrice(): void
    {
        $this->useNumbers(2);

        foreach (['0.1250', '9.0000', '12.3456', '1234.5000'] as $stored) {
            $this->assertSame($stored, number_format((float) parse_decimals(to_currency_exact($stored)), 4, '.', ''), $stored);
        }
    }
}
