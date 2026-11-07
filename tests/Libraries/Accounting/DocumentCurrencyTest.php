<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\DocumentCurrency;
use CodeIgniter\Test\CIUnitTestCase;

class DocumentCurrencyTest extends CIUnitTestCase
{
    private function dollars(): DocumentCurrency
    {
        return new DocumentCurrency('USD', '3.75000000', 2);
    }

    public function testToBase_RoundsWhatTheForeignAmountIsWorthToTheDecimalsOfTheBase(): void
    {
        $this->assertSame('1006.24', $this->dollars()->toBase('268.33', 2));
        $this->assertSame('1006.238', $this->dollars()->toBase('268.33', 3));
        $this->assertSame('1006', $this->dollars()->toBase('268.33', 0));
    }

    public function testUnchanged_AcceptsNoChangeAtAll(): void
    {
        $this->assertTrue(DocumentCurrency::unchanged($this->dollars(), []));
        $this->assertTrue(DocumentCurrency::unchanged(null, []));
    }

    public function testUnchanged_AcceptsTheSameCurrencyAndTheSameRateWrittenWithOtherDecimals(): void
    {
        $this->assertTrue(DocumentCurrency::unchanged($this->dollars(), ['currency_code' => 'USD', 'currency_rate' => '3.75']));
        $this->assertTrue(DocumentCurrency::unchanged($this->dollars(), ['currency_rate' => '3.750000000']));
        $this->assertTrue(DocumentCurrency::unchanged($this->dollars(), ['currency_code' => 'USD']));
    }

    public function testUnchanged_RejectsAnotherRateOfTheSameCurrencyEvenByOneStoredDecimal(): void
    {
        $this->assertFalse(DocumentCurrency::unchanged($this->dollars(), ['currency_rate' => '3.76']));
        $this->assertFalse(DocumentCurrency::unchanged($this->dollars(), ['currency_rate' => '3.75000001']));
        $this->assertFalse(DocumentCurrency::unchanged($this->dollars(), ['currency_code' => 'USD', 'currency_rate' => '3.60']));
    }

    public function testUnchanged_RejectsAnotherCurrencyAndTheBaseForADocumentInAForeignCurrency(): void
    {
        $this->assertFalse(DocumentCurrency::unchanged($this->dollars(), ['currency_code' => 'EUR']));
        $this->assertFalse(DocumentCurrency::unchanged($this->dollars(), ['currency_code' => null, 'currency_rate' => null]));
        $this->assertFalse(DocumentCurrency::unchanged($this->dollars(), ['currency_code' => null]));
    }

    public function testUnchanged_RejectsAForeignCurrencyForADocumentInTheBase(): void
    {
        $this->assertFalse(DocumentCurrency::unchanged(null, ['currency_code' => 'USD', 'currency_rate' => '3.75']));
        $this->assertFalse(DocumentCurrency::unchanged(null, ['currency_rate' => '1']));
        $this->assertTrue(DocumentCurrency::unchanged(null, ['currency_code' => null, 'currency_rate' => null]));
    }
}
