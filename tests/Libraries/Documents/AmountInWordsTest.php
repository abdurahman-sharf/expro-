<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\AmountInWords;
use App\Libraries\Documents\DocumentError;
use App\Libraries\Documents\DocumentException;
use App\Libraries\Documents\WordsCurrencies;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AmountInWordsTest extends CIUnitTestCase
{
    private AmountInWords $words;

    protected function setUp(): void
    {
        parent::setUp();

        $this->words = new AmountInWords();
    }

    private function refusal(callable $action): DocumentException
    {
        try {
            $action();
        } catch (DocumentException $exception) {
            return $exception;
        }

        $this->fail('The amount was not refused.');
    }

    public function testArabic_SpellsThreeThousandSaudiRiyalsAsTheReferenceSystemDoes(): void
    {
        $this->assertSame('ثلاثة آلاف ريال سعودي', $this->words->arabic('3000.00', 'SAR'));
        $this->assertSame('ثلاثة آلاف ريال سعودي', $this->words->arabic('3000', 'SAR'));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function boundaryProvider(): array
    {
        return [
            'zero'                    => ['0.00', 'صفر ريال سعودي', 'Zero Saudi Riyals'],
            'one'                     => ['1.00', 'ريال سعودي واحد', 'One Saudi Riyal'],
            'two'                     => ['2.00', 'ريالان سعوديان', 'Two Saudi Riyals'],
            'three'                   => ['3.00', 'ثلاثة ريالات سعودية', 'Three Saudi Riyals'],
            'ten'                     => ['10.00', 'عشرة ريالات سعودية', 'Ten Saudi Riyals'],
            'eleven'                  => ['11.00', 'أحد عشر ريالًا سعوديًا', 'Eleven Saudi Riyals'],
            'twelve'                  => ['12.00', 'اثنا عشر ريالًا سعوديًا', 'Twelve Saudi Riyals'],
            'twenty one'              => ['21.00', 'واحد وعشرون ريالًا سعوديًا', 'Twenty-one Saudi Riyals'],
            'ninety nine'             => ['99.00', 'تسعة وتسعون ريالًا سعوديًا', 'Ninety-nine Saudi Riyals'],
            'one hundred'             => ['100.00', 'مائة ريال سعودي', 'One hundred Saudi Riyals'],
            'one hundred and one'     => ['101.00', 'مائة وواحد ريال سعودي', 'One hundred one Saudi Riyals'],
            'one hundred and eleven'  => ['111.00', 'مائة وأحد عشر ريالًا سعوديًا', 'One hundred eleven Saudi Riyals'],
            'two hundred'             => ['200.00', 'مائتا ريال سعودي', 'Two hundred Saudi Riyals'],
            'nine hundred ninety nine' => ['999.00', 'تسعمائة وتسعة وتسعون ريالًا سعوديًا', 'Nine hundred ninety-nine Saudi Riyals'],
            'one thousand'            => ['1000.00', 'ألف ريال سعودي', 'One thousand Saudi Riyals'],
            'one thousand and one'    => ['1001.00', 'ألف وواحد ريال سعودي', 'One thousand one Saudi Riyals'],
            'two thousand'            => ['2000.00', 'ألفا ريال سعودي', 'Two thousand Saudi Riyals'],
            'two thousand and one'    => ['2001.00', 'ألفان وواحد ريال سعودي', 'Two thousand one Saudi Riyals'],
            'twelve thousand'         => ['12000.00', 'اثنا عشر ألف ريال سعودي', 'Twelve thousand Saudi Riyals'],
            'two hundred thousand'    => ['200000.00', 'مائتا ألف ريال سعودي', 'Two hundred thousand Saudi Riyals'],
            'one million'             => ['1000000.00', 'مليون ريال سعودي', 'One million Saudi Riyals'],
            'two million'             => ['2000000.00', 'مليونا ريال سعودي', 'Two million Saudi Riyals'],
            'a million and eleven thousand' => ['1011000.00', 'مليون وأحد عشر ألف ريال سعودي', 'One million eleven thousand Saudi Riyals'],
            'a thousand million'      => ['1000000000.00', 'مليار ريال سعودي', 'One billion Saudi Riyals'],
        ];
    }

    #[DataProvider('boundaryProvider')]
    public function testSpell_WritesTheBoundaryAmountsInBothLanguages(string $amount, string $arabic, string $english): void
    {
        $this->assertSame($arabic, $this->words->spell($amount, 'SAR', 'ar'));
        $this->assertSame($english, $this->words->spell($amount, 'SAR', 'en'));
    }

    public function testSpell_WritesTheFractionInHalalasAfterTheRiyals(): void
    {
        $this->assertSame('ثلاثة آلاف ريال سعودي وخمسون هللةً', $this->words->arabic('3000.50', 'SAR'));
        $this->assertSame('Three thousand Saudi Riyals and fifty Halalas', $this->words->english('3000.50', 'SAR'));
        $this->assertSame('مائة وثلاثة وعشرون ريالًا سعوديًا وخمس وأربعون هللةً', $this->words->arabic('123.45', 'SAR'));
    }

    public function testSpell_WritesOnlyTheFractionWhenTheWholePartIsZero(): void
    {
        $this->assertSame('هللة واحدة', $this->words->arabic('0.01', 'SAR'));
        $this->assertSame('هللتان', $this->words->arabic('0.02', 'SAR'));
        $this->assertSame('ثلاث هللات', $this->words->arabic('0.03', 'SAR'));
        $this->assertSame('One Halala', $this->words->english('0.01', 'SAR'));
        $this->assertSame('Ninety-nine Halalas', $this->words->english('0.99', 'SAR'));
    }

    public function testSpell_RoundsHalfUpToTheDecimalsOfTheCurrency(): void
    {
        $this->assertSame('ريال سعودي واحد', $this->words->arabic('0.995', 'SAR'));
        $this->assertSame('ريال سعودي واحد', $this->words->arabic('0.9950', 'SAR'));
        $this->assertSame('هللة واحدة', $this->words->arabic('0.0149', 'SAR'));
        $this->assertSame('صفر ريال سعودي', $this->words->arabic('0.0049', 'SAR'));
        $this->assertSame('هللة واحدة', $this->words->arabic('0.005', 'SAR'));
    }

    public function testSpell_CountsThreeDecimalsOfADinarAsFils(): void
    {
        $this->assertSame('دينار كويتي واحد ومائتان وأربعة وثلاثون فلسًا', $this->words->arabic('1.234', 'KWD'));
        $this->assertSame('One Kuwaiti Dinar and two hundred thirty-four Fils', $this->words->english('1.234', 'KWD'));
        $this->assertSame('بيسة واحدة', $this->words->arabic('0.001', 'OMR'));
    }

    public function testSpell_ScalesTheMinorUnitWhenTheDocumentKeepsFewerDecimalsThanTheCurrency(): void
    {
        $this->assertSame('One Kuwaiti Dinar and two hundred fifty Fils', $this->words->english('1.25', 'KWD', 2));
        $this->assertSame('دينار كويتي واحد ومائتان وخمسون فلسًا', $this->words->arabic('1.25', 'KWD', 2));
        $this->assertSame('One Kuwaiti Dinar and five hundred Fils', $this->words->english('1.5', 'KWD', 1));
        $this->assertSame('One Kuwaiti Dinar and ten Fils', $this->words->english('1.01', 'KWD', 2));
        $this->assertSame('One Kuwaiti Dinar and one Fils', $this->words->english('1.001', 'KWD', 3));
    }

    public function testSpell_DropsTheMinorUnitWhenTheDocumentShowsNoDecimals(): void
    {
        $this->assertSame('ريال سعودي واحد', $this->words->arabic('0.50', 'SAR', 0));
        $this->assertSame('ثلاثة آلاف ريال سعودي', $this->words->arabic('3000.49', 'SAR', 0));
    }

    public function testSpell_NeverCountsMoreMinorUnitsThanTheCurrencyHas(): void
    {
        $this->assertSame('ريال سعودي واحد', $this->words->arabic('0.9951', 'SAR', 4));
    }

    public function testSpell_GivesTheMinorUnitOfALiraItsOwnGender(): void
    {
        $this->assertSame('ليرة لبنانية واحدة وقرش واحد', $this->words->arabic('1.01', 'LBP'));
        $this->assertSame('ليرتان لبنانيتان', $this->words->arabic('2.00', 'LBP'));
    }

    public function testSpell_UsesTheFeminineFormsForAFeminineUnitAndTheOtherGenderForThreeToTen(): void
    {
        $this->assertSame('إحدى عشرة هللةً', $this->words->arabic('0.11', 'SAR'));
        $this->assertSame('اثنتا عشرة هللةً', $this->words->arabic('0.12', 'SAR'));
        $this->assertSame('ثلاث عشرة هللةً', $this->words->arabic('0.13', 'SAR'));
        $this->assertSame('ثماني عشرة هللةً', $this->words->arabic('0.18', 'SAR'));
        $this->assertSame('ثلاث وعشرون هللةً', $this->words->arabic('0.23', 'SAR'));
        $this->assertSame('ثلاثة وعشرون ريالًا سعوديًا', $this->words->arabic('23.00', 'SAR'));
    }

    public function testSpell_WritesTheLargestSupportedAmountInBothLanguages(): void
    {
        $arabic  = $this->words->arabic('999999999999.99', 'SAR');
        $english = $this->words->english('999999999999.99', 'SAR');

        $this->assertSame('تسعمائة وتسعة وتسعون مليارًا وتسعمائة وتسعة وتسعون مليونًا وتسعمائة وتسعة وتسعون ألفًا وتسعمائة وتسعة وتسعون ريالًا سعوديًا وتسع وتسعون هللةً', $arabic);
        $this->assertSame('Nine hundred ninety-nine billion nine hundred ninety-nine million nine hundred ninety-nine thousand nine hundred ninety-nine Saudi Riyals and ninety-nine Halalas', $english);
        $this->assertSame('999999999999', AmountInWords::MAX_WHOLE);
    }

    public function testSpell_RejectsAnAmountOfAThousandBillion(): void
    {
        $refusal = $this->refusal(fn () => $this->words->arabic('1000000000000.00', 'SAR'));

        $this->assertSame(DocumentError::AmountTooLarge, $refusal->error);
        $this->assertSame(DocumentError::AmountTooLarge, $this->refusal(fn () => $this->words->english('1000000000000', 'SAR'))->error);
    }

    public function testSpell_RejectsAnAmountThatRoundsUpToAThousandBillion(): void
    {
        $this->assertSame(DocumentError::AmountTooLarge, $this->refusal(fn () => $this->words->arabic('999999999999.999', 'SAR'))->error);
    }

    public function testSpell_RejectsANegativeAmountButAcceptsMinusZero(): void
    {
        $this->assertSame(DocumentError::AmountNegative, $this->refusal(fn () => $this->words->arabic('-0.01', 'SAR'))->error);
        $this->assertSame(DocumentError::AmountNegative, $this->refusal(fn () => $this->words->english('-5', 'SAR'))->error);
        $this->assertSame('صفر ريال سعودي', $this->words->arabic('-0.00', 'SAR'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedProvider(): array
    {
        return [
            'empty'             => [''],
            'letters'           => ['abc'],
            'a thousands comma' => ['1,000.00'],
            'two points'        => ['1.2.3'],
            'a trailing point'  => ['12.'],
            'an exponent'       => ['1e3'],
            'a plus sign'       => ['+5'],
        ];
    }

    #[DataProvider('malformedProvider')]
    public function testSpell_RejectsTextThatIsNotAnAmount(string $amount): void
    {
        $this->assertSame(DocumentError::AmountMalformed, $this->refusal(fn () => $this->words->arabic($amount, 'SAR'))->error);
    }

    public function testSpell_RejectsACurrencyItHasNoNamesFor(): void
    {
        $refusal = $this->refusal(fn () => $this->words->arabic('5.00', 'XYZ'));

        $this->assertSame(DocumentError::CurrencyUnsupported, $refusal->error);
        $this->assertSame('XYZ', $refusal->context['currency']);
        $this->assertFalse(AmountInWords::supports('XYZ'));
        $this->assertTrue(AmountInWords::supports('sar'));
    }

    public function testSpell_ChoosesTheLanguageByItsCode(): void
    {
        $this->assertSame('ألف ريال سعودي', $this->words->spell('1000', 'SAR', 'ar'));
        $this->assertSame('One thousand Saudi Riyals', $this->words->spell('1000', 'SAR', 'en'));
        $this->assertSame('One thousand Saudi Riyals', $this->words->spell('1000', 'SAR', 'fr'));
    }

    public function testSpell_TakesTheAmountAsATextSoNoFloatRoundsIt(): void
    {
        $this->assertSame('One hundred ten Saudi Riyals and thirty Halalas', $this->words->english('110.30', 'SAR'));
        $this->assertSame('هللة واحدة', $this->words->arabic('0.0100', 'SAR'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function currencyProvider(): array
    {
        return array_map(static fn (string $code): array => [$code], WordsCurrencies::codes());
    }

    #[DataProvider('currencyProvider')]
    public function testSpell_NamesEveryCurrencyInBothLanguagesWithoutAGap(string $code): void
    {
        foreach (['0', '1', '2', '3', '11', '100', '0.01', '0.02', '0.05', '0.11'] as $amount) {
            $arabic  = $this->words->arabic($amount, $code);
            $english = $this->words->english($amount, $code);

            $this->assertNotSame('', trim($arabic), "{$code} {$amount}");
            $this->assertStringNotContainsString('  ', $arabic, "{$code} {$amount}");
            $this->assertNotSame('', trim($english), "{$code} {$amount}");
            $this->assertStringNotContainsString('  ', $english, "{$code} {$amount}");
        }
    }

    public function testSpell_SpellsTheDualAndPluralOfEachMajorUnitOfTheGulfCurrencies(): void
    {
        $this->assertSame('درهمان إماراتيان', $this->words->arabic('2', 'AED'));
        $this->assertSame('خمسة دراهم إماراتية', $this->words->arabic('5', 'AED'));
        $this->assertSame('ثلاثة دنانير كويتية', $this->words->arabic('3', 'KWD'));
        $this->assertSame('ثلاثة وعشرون جنيهًا مصريًا', $this->words->arabic('23', 'EGP'));
        $this->assertSame('جنيهان إسترلينيان', $this->words->arabic('2', 'GBP'));
        $this->assertSame('مائة دولار أمريكي', $this->words->arabic('100', 'USD'));
    }
}
