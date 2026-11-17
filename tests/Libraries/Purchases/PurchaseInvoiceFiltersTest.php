<?php

namespace Tests\Libraries\Purchases;

use App\Libraries\Purchases\PurchaseInvoiceFilters;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * What a request may ask of the list of purchase invoices, and what is left out of it.
 *
 * @internal
 */
final class PurchaseInvoiceFiltersTest extends CIUnitTestCase
{
    public function testFromInput_ReadsTheDaysTheNumbersAndTheKnownValues(): void
    {
        $filters = PurchaseInvoiceFilters::fromInput(['date_from' => '2026-01-31', 'date_to' => ' 2026-02-28 ', 'supplier_id' => '12', 'status' => 'has_returns', 'payment' => 'overdue', 'search' => '  RECV 5 ']);

        $this->assertSame('2026-01-31', $filters->dateFrom);
        $this->assertSame('2026-02-28', $filters->dateTo);
        $this->assertSame(12, $filters->supplierId);
        $this->assertSame('has_returns', $filters->status);
        $this->assertSame('overdue', $filters->payment);
        $this->assertSame('RECV 5', $filters->search);
    }

    public function testFromInput_RejectsAnImpossibleDayAndAnUnknownValue(): void
    {
        $filters = PurchaseInvoiceFilters::fromInput(['date_from' => '2026-02-30', 'date_to' => '31/01/2026', 'supplier_id' => '-3', 'status' => 'cancelled', 'payment' => '1 OR 1=1']);

        $this->assertNull($filters->dateFrom);
        $this->assertNull($filters->dateTo);
        $this->assertSame(0, $filters->supplierId);
        $this->assertSame('', $filters->status);
        $this->assertSame('', $filters->payment);
    }

    public function testFromInput_ReadsOnlyTextWhenARequestGivesAnArray(): void
    {
        $filters = PurchaseInvoiceFilters::fromInput(['status' => ['received'], 'payment' => ['paid'], 'supplier_id' => ['5'], 'search' => ['x'], 'date_from' => ['2026-01-01']]);

        $this->assertEquals(new PurchaseInvoiceFilters(), $filters);
        $this->assertEquals(new PurchaseInvoiceFilters(), PurchaseInvoiceFilters::fromInput('nothing'));
    }

    public function testNeedsFigures_IsTrueOnlyForWhatIsPaidOrReturnedByAmount(): void
    {
        $this->assertTrue((new PurchaseInvoiceFilters(payment: 'paid'))->needsFigures());
        $this->assertTrue((new PurchaseInvoiceFilters(status: 'partly_returned'))->needsFigures());
        $this->assertTrue((new PurchaseInvoiceFilters(status: 'fully_returned'))->needsFigures());
        $this->assertFalse((new PurchaseInvoiceFilters(status: 'has_returns', supplierId: 3, search: 'x'))->needsFigures());
        $this->assertFalse((new PurchaseInvoiceFilters())->needsFigures());
    }

    public function testActive_CountsTheFiltersOfTheCardThatAreSet(): void
    {
        $this->assertSame(0, (new PurchaseInvoiceFilters(search: 'x'))->active());
        $this->assertSame(1, (new PurchaseInvoiceFilters(dateFrom: '2026-01-01', dateTo: '2026-01-31'))->active());
        $this->assertSame(4, (new PurchaseInvoiceFilters('2026-01-01', null, 2, 'return', 'paid'))->active());
    }
}
