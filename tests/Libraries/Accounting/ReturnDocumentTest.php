<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;

class ReturnDocumentTest extends CIUnitTestCase
{
    public function testFromStored_ReadsAReturnWithNoDocumentAsACreditNote(): void
    {
        $this->assertSame(ReturnDocument::CreditNote, ReturnDocument::fromStored(null));
        $this->assertSame(ReturnDocument::CreditNote, ReturnDocument::fromStored(''));
    }

    public function testFromStored_ReadsAStoredRefundInvoice(): void
    {
        $this->assertSame(ReturnDocument::RefundInvoice, ReturnDocument::fromStored('refund_invoice'));
    }

    public function testFromStored_ReadsAnythingItDoesNotKnowAsACreditNote(): void
    {
        $this->assertSame(ReturnDocument::CreditNote, ReturnDocument::fromStored('debit_note'));
        $this->assertSame(ReturnDocument::CreditNote, ReturnDocument::fromStored('REFUND_INVOICE'));
    }

    public function testStoredValue_LeavesACreditNoteEmptyAndNamesARefundInvoice(): void
    {
        $this->assertNull(ReturnDocument::CreditNote->storedValue());
        $this->assertSame('refund_invoice', ReturnDocument::RefundInvoice->storedValue());
    }

    public function testDocumentType_GivesEachDocumentTheSequenceOfItsOwn(): void
    {
        $this->assertSame(DocumentType::CreditNote, ReturnDocument::CreditNote->documentType());
        $this->assertSame(DocumentType::RefundInvoice, ReturnDocument::RefundInvoice->documentType());
    }

    public function testLanguageKey_NamesALanguageLineForEachDocument(): void
    {
        foreach (ReturnDocument::cases() as $document) {
            $this->assertNotSame($document->languageKey(), lang($document->languageKey()), $document->value);
        }
    }
}
