<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalLineTax;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The tax a journal line notes, worked out as the journal form of the reference system works it out
 * (OBS-A FRM-R39). It is a note and posts nothing.
 *
 * @internal
 */
final class JournalLineTaxTest extends CIUnitTestCase
{
    public function testFigures_AddsTheTaxToAnAmountThatDoesNotHoldIt(): void
    {
        $this->assertSame(['net' => '100.00', 'tax' => '15.00', 'total' => '115.00'], JournalLineTax::figures('100', '15', false, 2));
    }

    public function testFigures_TakesTheTaxOutOfAnAmountThatHoldsIt(): void
    {
        $this->assertSame(['net' => '86.96', 'tax' => '13.04', 'total' => '100.00'], JournalLineTax::figures('100', '15', true, 2));
    }

    public function testFigures_FollowsTheDecimalsOfTheCurrency(): void
    {
        $this->assertSame(['net' => '86.957', 'tax' => '13.043', 'total' => '100.000'], JournalLineTax::figures('100', '15', true, 3));
        $this->assertSame(['net' => '87', 'tax' => '13', 'total' => '100'], JournalLineTax::figures('100', '15', true, 0));
        $this->assertSame(['net' => '100.000', 'tax' => '15.000', 'total' => '115.000'], JournalLineTax::figures('100', '15', false, 3));
    }

    public function testFigures_RoundsAHalfAwayFromZero(): void
    {
        $this->assertSame('0.01', JournalLineTax::figures('0.05', '10', false, 2)['tax']);
        $this->assertSame('0.02', JournalLineTax::figures('0.15', '10', false, 2)['tax']);
    }

    public function testFigures_NotesNoTaxAtAZeroRateAndHoldsTheAmountWhenTheTaxIsIncluded(): void
    {
        $this->assertSame(['net' => '100.00', 'tax' => '0.00', 'total' => '100.00'], JournalLineTax::figures('100', '0', false, 2));
        $this->assertSame(['net' => '100.00', 'tax' => '0.00', 'total' => '100.00'], JournalLineTax::figures('100', '0', true, 2));
    }

    public function testFigures_TakesTheWholeAmountAsTaxAtAHundredPercentWhenItIsIncluded(): void
    {
        $this->assertSame(['net' => '50.00', 'tax' => '50.00', 'total' => '100.00'], JournalLineTax::figures('100', '100', true, 2));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function codes(): array
    {
        return [
            'letters and digits' => ['VAT15', true],
            'a dash inside'      => ['VAT-15', true],
            'sixteen characters' => [str_repeat('V', 16), true],
            'one character'      => ['5', true],
            'seventeen'          => [str_repeat('V', 17), false],
            'a space'            => ['VAT 15', false],
            'a leading dash'     => ['-VAT', false],
            'empty'              => ['', false],
            'arabic letters'     => ['ضريبة', false],
            'markup'             => ['<b>', false],
        ];
    }

    #[DataProvider('codes')]
    public function testIsCode_AcceptsWhatTheCodeOfATaxMayBeAndNothingElse(string $code, bool $valid): void
    {
        $this->assertSame($valid, JournalLineTax::isCode($code));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function percents(): array
    {
        return [
            'zero'           => ['0', true],
            'a hundred'      => ['100', true],
            'four decimals'  => ['15.1234', true],
            'over a hundred' => ['100.0001', false],
            'five decimals'  => ['1.12345', false],
            'negative'       => ['-1', false],
            'text'           => ['abc', false],
            'empty'          => ['', false],
            'four digits'    => ['1000', false],
        ];
    }

    #[DataProvider('percents')]
    public function testIsPercent_AcceptsARateFromZeroToAHundredWithFourDecimals(string $percent, bool $valid): void
    {
        $this->assertSame($valid, JournalLineTax::isPercent($percent));
    }
}
