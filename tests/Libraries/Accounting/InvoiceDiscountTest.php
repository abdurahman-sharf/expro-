<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\InvoiceDiscount;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The discount on the whole of a purchase as it is entered: what is accepted as one, whether it fits
 * the lines it is taken from, and what it comes to, to the cent.
 */
class InvoiceDiscountTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{int, string, string}>
     */
    public static function accepted(): array
    {
        return [
            'a percentage'                  => [PERCENT, '10', '10.0000'],
            'a percentage with decimals'    => [PERCENT, '12.34567', '12.3457'],
            'the whole as a percentage'     => [PERCENT, '100', '100.0000'],
            'nothing as a percentage'       => [PERCENT, '0', '0.0000'],
            'an amount'                     => [FIXED, '100', '100.00'],
            'an amount kept to the cent'    => [FIXED, '10.005', '10.01'],
            'an amount with padding spaces' => [FIXED, ' 7.5 ', '7.50'],
        ];
    }

    #[DataProvider('accepted')]
    public function testOf_AcceptsAPlainNumberOfNothingOrMore(int $type, string $value, string $kept): void
    {
        $discount = InvoiceDiscount::of($type, $value);

        $this->assertNotNull($discount);
        $this->assertSame([$type, $kept], [$discount->type, $discount->value]);
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function rejected(): array
    {
        return [
            'a negative number'         => [PERCENT, '-1'],
            'text'                      => [FIXED, 'abc'],
            'an exponent'               => [FIXED, '1e3'],
            'nothing'                   => [PERCENT, ''],
            'a comma for the decimals'  => [FIXED, '10,5'],
            'a sign'                    => [FIXED, '+5'],
            'a type that is not one'    => [FIXED_LINE, '5'],
            'an unknown type'           => [7, '5'],
            'more digits than a column' => [FIXED, '123456789012'],
        ];
    }

    #[DataProvider('rejected')]
    public function testOf_RejectsWhatIsNotAPlainNumberOfNothingOrMoreAndTypesThatAreNotAPercentageOrAnAmount(int $type, string $value): void
    {
        $this->assertNull(InvoiceDiscount::of($type, $value));
    }

    public function testNone_IsNothing(): void
    {
        $this->assertTrue(InvoiceDiscount::none()->isNone());
        $this->assertSame('0.0000', InvoiceDiscount::none()->amountOn('500.0000'));
    }

    public function testIsNone_SeesAnAmountThatIsNothingOnceKeptToTheCent(): void
    {
        $this->assertTrue(InvoiceDiscount::of(FIXED, '0.004')->isNone());
        $this->assertFalse(InvoiceDiscount::of(FIXED, '0.005')->isNone());
        $this->assertFalse(InvoiceDiscount::of(PERCENT, '0.0001')->isNone());
    }

    public function testStored_IsNothingForAPurchaseSavedWithoutOneAndWhateverWasKeptOtherwise(): void
    {
        $this->assertTrue(InvoiceDiscount::stored(null, null)->isNone());
        $this->assertTrue(InvoiceDiscount::stored(null, '10.0000')->isNone());
        $this->assertTrue(InvoiceDiscount::stored(PERCENT, null)->isNone());
        $this->assertTrue(InvoiceDiscount::stored(9, '10.0000')->isNone());

        $discount = InvoiceDiscount::stored(PERCENT, '10.0000');

        $this->assertSame([PERCENT, '10.0000'], [$discount->type, $discount->value]);
    }

    /**
     * @return array<string, array{int, string, string, bool}>
     */
    public static function fits(): array
    {
        return [
            'the whole as a percentage'               => [PERCENT, '100', '500.0000', true],
            'a hair above the whole'                  => [PERCENT, '100.0001', '500.0000', false],
            'an amount equal to the lines'            => [FIXED, '500', '500.0000', true],
            'an amount a cent above the lines'        => [FIXED, '500.01', '500.0000', false],
            'a percentage on lines that cost nothing' => [PERCENT, '10', '0.0000', true],
        ];
    }

    #[DataProvider('fits')]
    public function testFitsOn_AcceptsAPercentageUpToTheWholeAndAnAmountUpToWhatTheLinesCome(int $type, string $value, string $base, bool $fits): void
    {
        $this->assertSame($fits, InvoiceDiscount::of($type, $value)->fitsOn($base));
    }

    /**
     * @return array<string, array{int, string, string, string}>
     */
    public static function amounts(): array
    {
        return [
            'a tenth of 900'                                      => [PERCENT, '10', '900.0000', '90.0000'],
            'half a cent rounds up'                               => [PERCENT, '10', '810.0500', '81.0100'],
            'a cent of a fraction rounds down'                    => [PERCENT, '10', '810.0400', '81.0000'],
            'an amount is what it is'                             => [FIXED, '50', '900.0000', '50.0000'],
            'the whole as a percentage is the whole of the lines' => [PERCENT, '100', '123.4567', '123.4567'],
            'an amount above the lines is held to them'           => [FIXED, '1000', '999.9999', '999.9999'],
            'lines that cost nothing'                             => [PERCENT, '10', '0.0000', '0.0000'],
        ];
    }

    #[DataProvider('amounts')]
    public function testAmountOn_KeepsWhatTheDiscountComesToToTheCentAndNeverAboveTheLines(int $type, string $value, string $base, string $amount): void
    {
        $this->assertSame($amount, InvoiceDiscount::of($type, $value)->amountOn($base));
    }
}
