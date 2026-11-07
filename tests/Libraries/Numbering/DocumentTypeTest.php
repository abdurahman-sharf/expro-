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
        $this->assertSame(['sales' => 'sale_id'], DocumentType::RefundInvoice->sources());
        $this->assertSame(['receivings_payments' => 'payment_id', 'supplier_advances' => 'advance_id'], DocumentType::SupplierPayment->sources());
    }

    public function testNumberColumn_NamesTheColumnOfTheKindsThatHadANumberBeforeThereWasANumbering(): void
    {
        $this->assertSame('invoice_number', DocumentType::SalesInvoice->numberColumn());
        $this->assertSame('quote_number', DocumentType::SalesQuote->numberColumn());
        $this->assertSame('account_number', DocumentType::Customer->numberColumn());
        $this->assertNull(DocumentType::CreditNote->numberColumn());
        $this->assertNull(DocumentType::RefundInvoice->numberColumn());
        $this->assertNull(DocumentType::Expense->numberColumn());
    }

    public function testBareIdSources_ListsOnlyTheKindsThatShowTheirIdAlone(): void
    {
        $this->assertSame(['expenses' => 'expense_id'], DocumentType::Expense->bareIdSources());
        $this->assertSame(['sales_payments' => 'payment_id', 'customer_advances' => 'advance_id'], DocumentType::CustomerPayment->bareIdSources());
        $this->assertSame([], DocumentType::CreditNote->bareIdSources());
        $this->assertSame([], DocumentType::RefundInvoice->bareIdSources());
        $this->assertSame([], DocumentType::SalesInvoice->bareIdSources());
    }

    public function testFallback_GivesWhatTheDocumentShowedBeforeThereWasANumber(): void
    {
        $this->assertSame('POS 7', DocumentType::CreditNote->fallback(7));
        $this->assertSame('POS 7', DocumentType::RefundInvoice->fallback(7));
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

    public function testSalesDebitNote_HasATableOfItsOwnAndFiveDigitsAndShowsItsIdInFiveDigitsUntilItHasANumber(): void
    {
        $type = DocumentType::SalesDebitNote;

        $this->assertSame(['sales_debit_notes' => 'debit_note_id'], $type->sources());
        $this->assertSame($type->sources(), $type->bareIdSources());
        $this->assertNull($type->numberColumn());
        $this->assertSame(5, $type->defaultPadding());
        $this->assertSame('00007', $type->fallback(7));
        $this->assertSame('123456', $type->fallback(123456));
        $this->assertNotSame(DocumentType::DebitNote->value, $type->value, 'the sales debit note is not the debit note of purchases');
    }

    public function testSalesOrder_HasATableOfItsOwnAndFiveDigitsAndShowsItsIdInFiveDigitsUntilItHasANumber(): void
    {
        $type = DocumentType::SalesOrder;

        $this->assertSame(['sales_orders' => 'order_id'], $type->sources());
        $this->assertSame($type->sources(), $type->bareIdSources());
        $this->assertNull($type->numberColumn());
        $this->assertSame(5, $type->defaultPadding());
        $this->assertSame('00001', $type->fallback(1));
        $this->assertSame('sales_order', $type->value);
        $this->assertLessThanOrEqual(24, strlen($type->value), 'the column of the document type holds 24 characters');
    }

    public function testDefaultPadding_IsZeroForEveryKindButTheSalesDebitNoteTheSalesOrderAndTheStockDocuments(): void
    {
        $stock = [DocumentType::StockPermitAdd, DocumentType::StockPermitIssue, DocumentType::StockPermitTransfer, DocumentType::Stocktake, DocumentType::StockRequest];

        foreach (DocumentType::cases() as $type) {
            $this->assertSame(match (true) {
                $type === DocumentType::SalesDebitNote, $type === DocumentType::SalesOrder => 5,
                in_array($type, $stock, true)                                              => 6,
                default                                                                    => 0,
            }, $type->defaultPadding(), $type->value);
        }
    }

    public function testSources_PutsTheThreeStockPermitsInOneTableAndTheStocktakeAndTheRequestInTheirOwn(): void
    {
        foreach ([DocumentType::StockPermitAdd, DocumentType::StockPermitIssue, DocumentType::StockPermitTransfer] as $type) {
            $this->assertSame(['stock_permits' => 'permit_id'], $type->sources(), $type->value);
        }

        $this->assertSame(['stocktakes' => 'stocktake_id'], DocumentType::Stocktake->sources());
        $this->assertSame(['stock_requests' => 'request_id'], DocumentType::StockRequest->sources());
    }

    public function testFallback_NamesTheStockDocumentsByKindSoNoTwoKindsShowTheSameLabel(): void
    {
        $labels = array_map(static fn (DocumentType $type): string => $type->fallback(7), [DocumentType::StockPermitAdd, DocumentType::StockPermitIssue, DocumentType::StockPermitTransfer, DocumentType::Stocktake, DocumentType::StockRequest]);

        $this->assertSame(['STK-IN 7', 'STK-OUT 7', 'STK-TRF 7', 'STKT 7', 'STK-REQ 7'], $labels);
        $this->assertSame([], DocumentType::StockPermitAdd->bareIdSources());
        $this->assertNull(DocumentType::StockPermitAdd->numberColumn());
    }
}
