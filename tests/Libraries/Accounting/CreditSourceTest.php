<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\CreditSource;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * What is left to spend of a credit: what it came to less what was applied to invoices and less what
 * was paid back in cash, and never less than nothing.
 */
class CreditSourceTest extends CIUnitTestCase
{
    public function testAvailable_IsWhatIsLeftAfterWhatWasAppliedAndWhatWasPaidBack(): void
    {
        $source = new CreditSource(CreditSource::CREDIT_NOTE, 7, 3, '2026-10-01', '100.0000', '30.0000', '20.0000');

        $this->assertSame('50.0000', $source->available());
    }

    public function testAvailable_TakesWhatWasPaidBackOffEvenWhenNothingWasApplied(): void
    {
        $source = new CreditSource(CreditSource::CUSTOMER_ADVANCE, 7, null, '2026-10-01', '100.0000', '0.0000', '70.0000');

        $this->assertSame('30.0000', $source->available());
    }

    public function testAvailable_TakesNothingOffWhenNothingWasPaidBack(): void
    {
        $source = new CreditSource(CreditSource::INVOICE_EXCESS, 7, null, '2026-10-01', '100.0000', '30.0000');

        $this->assertSame('70.0000', $source->available());
    }

    public function testAvailable_IsNeverLessThanNothing(): void
    {
        $this->assertSame('0.0000', (new CreditSource(CreditSource::CREDIT_NOTE, 7, null, '2026-10-01', '100.0000', '80.0000', '50.0000'))->available());
        $this->assertSame('0.0000', (new CreditSource(CreditSource::CREDIT_NOTE, 7, null, '2026-10-01', '100.0000', '100.0000', '0.0000'))->available());
    }
}
