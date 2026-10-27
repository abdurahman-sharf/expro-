<?php

namespace Tests\Libraries\Numbering;

use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;

class DocumentTypeTest extends CIUnitTestCase
{
    public function testSources_NamesTheTablesOfEveryKind(): void
    {
        foreach (DocumentType::cases() as $type) {
            $this->assertNotSame([], $type->sources(), $type->value);
        }

        $this->assertSame(['sales' => 'sale_id'], DocumentType::CreditNote->sources());
        $this->assertSame(['receivings_payments' => 'payment_id', 'supplier_advances' => 'advance_id'], DocumentType::SupplierPayment->sources());
    }

    public function testNumberColumn_NamesTheColumnOfTheKindsThatHadANumberBeforeThereWasANumbering(): void
    {
        $this->assertSame('invoice_number', DocumentType::SalesInvoice->numberColumn());
        $this->assertSame('quote_number', DocumentType::SalesQuote->numberColumn());
        $this->assertSame('account_number', DocumentType::Customer->numberColumn());
        $this->assertNull(DocumentType::CreditNote->numberColumn());
        $this->assertNull(DocumentType::Expense->numberColumn());
    }

    public function testBareIdSources_ListsOnlyTheKindsThatShowTheirIdAlone(): void
    {
        $this->assertSame(['expenses' => 'expense_id'], DocumentType::Expense->bareIdSources());
        $this->assertSame(['sales_payments' => 'payment_id'], DocumentType::CustomerPayment->bareIdSources());
        $this->assertSame([], DocumentType::CreditNote->bareIdSources());
        $this->assertSame([], DocumentType::SalesInvoice->bareIdSources());
    }

    public function testFallback_GivesWhatTheDocumentShowedBeforeThereWasANumber(): void
    {
        $this->assertSame('POS 7', DocumentType::CreditNote->fallback(7));
        $this->assertSame('RECV 7', DocumentType::Purchase->fallback(7));
        $this->assertSame('RECV 7', DocumentType::DebitNote->fallback(7));
        $this->assertSame('7', DocumentType::Expense->fallback(7));
        $this->assertSame('', DocumentType::SalesInvoice->fallback(7));
    }

    public function testHasDuplicateOption_IsTrueForTheCustomerOnly(): void
    {
        foreach (DocumentType::cases() as $type) {
            $this->assertSame($type === DocumentType::Customer, $type->hasDuplicateOption(), $type->value);
        }
    }

    public function testLabelKey_NamesALanguageLineForEveryKind(): void
    {
        foreach (DocumentType::cases() as $type) {
            $this->assertNotSame($type->labelKey(), lang($type->labelKey()), $type->value);
        }
    }
}
