<?php

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

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
}
