<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\DocumentTerms;
use CodeIgniter\Test\CIUnitTestCase;

class DocumentTermsTest extends CIUnitTestCase
{
    public function testSettingKeys_ListsOneSettingForEachFamilyOnce(): void
    {
        $this->assertSame(['document_terms_invoice', 'document_terms_credit_note', 'document_terms_quote', 'document_terms_work_order', 'document_terms_purchase_invoice'], DocumentTerms::settingKeys());
    }

    public function testKeyFor_GivesInvoiceAndTaxInvoiceTheSameFamily(): void
    {
        $this->assertSame(DocumentTerms::keyFor('invoice'), DocumentTerms::keyFor('tax_invoice'));
        $this->assertNull(DocumentTerms::keyFor('receipt_voucher'));
        $this->assertNull(DocumentTerms::keyFor('journal'));
        $this->assertNull(DocumentTerms::keyFor('packing_slip'));
    }

    public function testForType_ReadsAndTrimsTheTextOfTheFamily(): void
    {
        $this->assertSame('Pay in 30 days.', DocumentTerms::forType('purchase_invoice', ['document_terms_purchase_invoice' => "  Pay in 30 days.\n"]));
        $this->assertSame('', DocumentTerms::forType('purchase_invoice', []));
        $this->assertSame('', DocumentTerms::forType('quote', ['document_terms_invoice' => 'Only for invoices.']));
    }

    public function testClean_CutsTheTextToTheLengthASettingHolds(): void
    {
        $this->assertSame(DocumentTerms::MAX_LENGTH, mb_strlen(DocumentTerms::clean(str_repeat('ش', DocumentTerms::MAX_LENGTH + 50))));
        $this->assertSame(DocumentTerms::MAX_LENGTH, mb_strlen(DocumentTerms::clean(str_repeat('a', DocumentTerms::MAX_LENGTH))));
    }

    public function testClean_TrimsTheTextAndUsesOneKindOfNewLine(): void
    {
        $this->assertSame("one\ntwo", DocumentTerms::clean("  one\r\ntwo \n"));
    }

    public function testClean_TakesNothingThatIsNotText(): void
    {
        $this->assertSame('', DocumentTerms::clean(null));
        $this->assertSame('', DocumentTerms::clean(['a']));
        $this->assertSame('', DocumentTerms::clean(12));
    }
}
