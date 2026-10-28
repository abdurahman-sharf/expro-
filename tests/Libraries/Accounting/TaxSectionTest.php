<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\TaxSection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxSectionTest extends CIUnitTestCase
{
    public function testCases_AreInTheOrderTheReferenceSystemListsThem(): void
    {
        $this->assertSame(['sales', 'sales_returns', 'purchases', 'purchase_returns', 'expenses'], array_map(static fn (TaxSection $section): string => $section->value, TaxSection::cases()));
    }

    public function testIsOutput_IsTrueForSalesAndTheirReturnsAndForNothingElse(): void
    {
        $this->assertTrue(TaxSection::SalesInvoices->isOutput());
        $this->assertTrue(TaxSection::SalesReturns->isOutput());
        $this->assertFalse(TaxSection::Purchases->isOutput());
        $this->assertFalse(TaxSection::PurchaseReturns->isOutput());
        $this->assertFalse(TaxSection::Expenses->isOutput());
    }

    public function testHasLines_IsFalseForExpensesAloneBecauseTheyCarryNoLines(): void
    {
        $this->assertFalse(TaxSection::Expenses->hasLines());

        foreach ([TaxSection::SalesInvoices, TaxSection::SalesReturns, TaxSection::Purchases, TaxSection::PurchaseReturns] as $section) {
            $this->assertTrue($section->hasLines());
        }
    }
}
