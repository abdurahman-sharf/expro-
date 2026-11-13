<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceRules;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class EInvoiceRulesTest extends CIUnitTestCase
{
    #[DataProvider('vatNumbers')]
    public function testVatNumber_AcceptsFifteenDigitsThatBeginAndEndWithThree(string $value, bool $valid): void
    {
        $this->assertSame($valid, EInvoiceRules::vatNumber($value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function vatNumbers(): array
    {
        return [
            'valid'                    => ['399999999900003', true],
            'another valid one'        => ['311111111111113', true],
            'fourteen digits'          => ['39999999990003', false],
            'sixteen digits'           => ['3999999999000033', false],
            'does not begin with 3'    => ['499999999900003', false],
            'does not end with 3'      => ['399999999900004', false],
            'letters'                  => ['3999999999A0003', false],
            'empty'                    => ['', false],
            'with a space'             => ['3999999999 0003', false],
            'arabic indic digits'      => ['٣٩٩٩٩٩٩٩٩٩٠٠٠٠٣', false],
        ];
    }

    #[DataProvider('postalCodes')]
    public function testPostalCode_AcceptsFiveDigitsOnly(string $value, bool $valid): void
    {
        $this->assertSame($valid, EInvoiceRules::postalCode($value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function postalCodes(): array
    {
        return ['five' => ['12345', true], 'four' => ['1234', false], 'six' => ['123456', false], 'letters' => ['12A45', false], 'empty' => ['', false]];
    }

    #[DataProvider('buildingNumbers')]
    public function testBuildingNumber_AcceptsFourDigitsOnly(string $value, bool $valid): void
    {
        $this->assertSame($valid, EInvoiceRules::buildingNumber($value));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function buildingNumbers(): array
    {
        return ['four' => ['1234', true], 'three' => ['123', false], 'five' => ['12345', false], 'letters' => ['12B4', false]];
    }

    public function testIdentificationNumber_AcceptsLettersAndDigitsOnly(): void
    {
        $this->assertTrue(EInvoiceRules::identificationNumber('1010010000'));
        $this->assertTrue(EInvoiceRules::identificationNumber('AB12cd'));
        $this->assertFalse(EInvoiceRules::identificationNumber('10-10'));
        $this->assertFalse(EInvoiceRules::identificationNumber(''));
        $this->assertFalse(EInvoiceRules::identificationNumber('10 10'));
    }

    public function testCountryCode_AcceptsTwoCapitalLetters(): void
    {
        $this->assertTrue(EInvoiceRules::countryCode('SA'));
        $this->assertFalse(EInvoiceRules::countryCode('sa'));
        $this->assertFalse(EInvoiceRules::countryCode('SAU'));
    }

    public function testWithin_CountsCharactersNotBytes(): void
    {
        $this->assertTrue(EInvoiceRules::within(str_repeat('ش', 127), 127));
        $this->assertFalse(EInvoiceRules::within(str_repeat('ش', 128), 127));
    }
}
