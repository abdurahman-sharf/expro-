<?php

namespace Tests\Libraries\PurchaseCycle;

use App\Libraries\Numbering\DocumentType;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use CodeIgniter\Test\CIUnitTestCase;

class PurchaseDocumentKindTest extends CIUnitTestCase
{
    public function testNext_ChainsTheFourDocumentsInTheOrderOneIsMadeFromTheNext(): void
    {
        $this->assertSame(PurchaseDocumentKind::QuotationRequest, PurchaseDocumentKind::Request->next());
        $this->assertSame(PurchaseDocumentKind::SupplierQuotation, PurchaseDocumentKind::QuotationRequest->next());
        $this->assertSame(PurchaseDocumentKind::Order, PurchaseDocumentKind::SupplierQuotation->next());
        $this->assertNull(PurchaseDocumentKind::Order->next(), 'the order is closed by the receiving, which is not a document of the cycle');
    }

    public function testSourceColumn_NamesTheColumnThatHoldsTheDocumentTheOneBeforeMadeIt(): void
    {
        $this->assertNull(PurchaseDocumentKind::Request->sourceColumn());
        $this->assertSame('source_request_id', PurchaseDocumentKind::QuotationRequest->sourceColumn());
        $this->assertSame('source_rfq_id', PurchaseDocumentKind::SupplierQuotation->sourceColumn());
        $this->assertSame('source_quotation_id', PurchaseDocumentKind::Order->sourceColumn());
    }

    public function testTable_KeepsEachKindInATableOfItsOwnWithItsLinesAndItsIdColumn(): void
    {
        $this->assertSame(['purchase_requests', 'purchase_request_lines', 'request_id'], [PurchaseDocumentKind::Request->table(), PurchaseDocumentKind::Request->linesTable(), PurchaseDocumentKind::Request->idColumn()]);
        $this->assertSame(['quotation_requests', 'quotation_request_lines', 'rfq_id'], [PurchaseDocumentKind::QuotationRequest->table(), PurchaseDocumentKind::QuotationRequest->linesTable(), PurchaseDocumentKind::QuotationRequest->idColumn()]);
        $this->assertSame(['supplier_quotations', 'supplier_quotation_lines', 'quotation_id'], [PurchaseDocumentKind::SupplierQuotation->table(), PurchaseDocumentKind::SupplierQuotation->linesTable(), PurchaseDocumentKind::SupplierQuotation->idColumn()]);
        $this->assertSame(['purchase_orders', 'purchase_order_lines', 'order_id'], [PurchaseDocumentKind::Order->table(), PurchaseDocumentKind::Order->linesTable(), PurchaseDocumentKind::Order->idColumn()]);
    }

    public function testModule_IsTheTableAndNamesTheFamilyInTheNavigationAndTheGrants(): void
    {
        foreach (PurchaseDocumentKind::cases() as $kind) {
            $this->assertSame($kind->table(), $kind->module());
            $this->assertSame($kind, PurchaseDocumentKind::ofModule($kind->module()));
        }

        $this->assertNull(PurchaseDocumentKind::ofModule('receivings'));
        $this->assertNull(PurchaseDocumentKind::ofModule('customer_orders'));
    }

    public function testPermission_PrefixesTheActionWithTheLettersOfTheFamily(): void
    {
        $this->assertSame('pr_add', PurchaseDocumentKind::Request->permission('add'));
        $this->assertSame('qr_edit', PurchaseDocumentKind::QuotationRequest->permission('edit'));
        $this->assertSame('sq_delete', PurchaseDocumentKind::SupplierQuotation->permission('delete'));
        $this->assertSame('po_view_all', PurchaseDocumentKind::Order->permission('view_all'));
        $this->assertSame('po_convert', PurchaseDocumentKind::Order->permission('convert'));
    }

    public function testManualStatusSetting_UsesTheNamesOfTheFourToggleKeysTheReferenceSystemShows(): void
    {
        $this->assertSame('enable_purchase_request_manual_status', PurchaseDocumentKind::Request->manualStatusSetting());
        $this->assertSame('enable_quotation_request_manual_status', PurchaseDocumentKind::QuotationRequest->manualStatusSetting());
        $this->assertSame('enable_purchase_quotation_manual_status', PurchaseDocumentKind::SupplierQuotation->manualStatusSetting());
        $this->assertSame('enable_purchase_order_manual_status', PurchaseDocumentKind::Order->manualStatusSetting());
    }

    public function testDocumentType_GivesEachKindANumberingOfItsOwnInFiveDigitsOverItsOwnTable(): void
    {
        foreach (PurchaseDocumentKind::cases() as $kind) {
            $type = $kind->documentType();

            $this->assertSame($kind->value, $type->value);
            $this->assertSame([$kind->table() => $kind->idColumn()], $type->sources());
            $this->assertSame(5, $type->defaultPadding());
            $this->assertSame(str_pad('7', 5, '0', STR_PAD_LEFT), $type->fallback(7));
            $this->assertSame($type->sources(), $type->bareIdSources());
            $this->assertInstanceOf(DocumentType::class, $type);
        }
    }

    public function testNeedsSupplier_IsTrueOnlyForTheQuotationAndTheOrderAndAPurchaseRequestHasNone(): void
    {
        $this->assertFalse(PurchaseDocumentKind::Request->hasSupplier());
        $this->assertFalse(PurchaseDocumentKind::Request->needsSupplier());
        $this->assertTrue(PurchaseDocumentKind::QuotationRequest->hasSupplier());
        $this->assertFalse(PurchaseDocumentKind::QuotationRequest->needsSupplier());
        $this->assertTrue(PurchaseDocumentKind::SupplierQuotation->needsSupplier());
        $this->assertTrue(PurchaseDocumentKind::Order->needsSupplier());
    }

    public function testLabelKey_NamesALanguageLineForEveryKindAndItsDueDate(): void
    {
        foreach (PurchaseDocumentKind::cases() as $kind) {
            $this->assertNotSame($kind->labelKey(), lang($kind->labelKey()), $kind->value);
            $this->assertNotSame($kind->dueLabelKey(), lang($kind->dueLabelKey()), $kind->value);
        }
    }
}
