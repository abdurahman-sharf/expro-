<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Describes the main journal of a sale or a purchase with the template the company chose, when it chose
 * one: the number of the document, the name of the customer or the supplier, the kind of document and its
 * date go in the placeholders. Only a journal that is being posted takes it. A journal that stands keeps the
 * description it was given, and with no template (the default) every description is the one the application
 * writes itself, so nothing that exists or was posted before changes.
 */
final class JournalDescriptions
{
    private BaseConnection $db;
    private AccountingSettings $settings;
    private DocumentNumbering $numbering;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->settings  = new AccountingSettings($this->db);
        $this->numbering = new DocumentNumbering($this->db);
    }

    public function ofSale(JournalDraft $draft, SaleDocument $sale): JournalDraft
    {
        $template = $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::SALES);

        if ($template === '') {
            return $draft;
        }

        $text = JournalDescriptionTemplate::render($template, [
            'number' => $this->saleNumber($sale),
            'party'  => $sale->customerId === null ? '' : $this->personName($sale->customerId),
            'type'   => $this->saleType($sale),
            'date'   => $sale->date,
        ]);

        return $text === '' ? $draft : $draft->withDescription(ReturnOverride::describe($text, $sale->returnOverride));
    }

    public function ofPurchase(JournalDraft $draft, ReceivingDocument $purchase): JournalDraft
    {
        $template = $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::PURCHASES);

        if ($template === '') {
            return $draft;
        }

        $text = JournalDescriptionTemplate::render($template, [
            'number' => $this->purchaseNumber($purchase),
            'party'  => $purchase->supplierId === null ? '' : $this->supplierName($purchase->supplierId),
            'type'   => lang($purchase->type === ReceivingType::Return ? 'Accounting.template_type_purchase_return' : 'Accounting.template_type_purchase'),
            'date'   => $purchase->date,
        ]);

        return $text === '' ? $draft : $draft->withDescription($text);
    }

    private function saleNumber(SaleDocument $sale): string
    {
        if ($sale->isReturn()) {
            return $this->numbering->label($sale->refundInvoice ? DocumentType::RefundInvoice : DocumentType::CreditNote, 'sales', $sale->saleId);
        }

        $row = $this->db->table('sales')->select('invoice_number')->where('sale_id', $sale->saleId)->get()->getRow();

        return $row === null || $row->invoice_number === null || $row->invoice_number === '' ? (string) $sale->saleId : (string) $row->invoice_number;
    }

    private function saleType(SaleDocument $sale): string
    {
        return match (true) {
            $sale->isReturn() && $sale->refundInvoice => lang('Accounting.template_type_refund_invoice'),
            $sale->isReturn()                         => lang('Accounting.template_type_credit_note'),
            $sale->saleType === SALE_TYPE_INVOICE     => lang('Accounting.template_type_invoice'),
            default                                   => lang('Accounting.template_type_sale'),
        };
    }

    private function purchaseNumber(ReceivingDocument $purchase): string
    {
        return $this->numbering->label($purchase->type === ReceivingType::Return ? DocumentType::DebitNote : DocumentType::Purchase, 'receivings', $purchase->receivingId);
    }

    private function personName(int $personId): string
    {
        $row = $this->db->table('people')->select("TRIM(CONCAT(first_name, ' ', last_name)) AS name", false)->where('person_id', $personId)->get()->getRow();

        return $row === null ? '' : (string) $row->name;
    }

    private function supplierName(int $supplierId): string
    {
        $row = $this->db->table('suppliers')->select('company_name')->where('person_id', $supplierId)->get()->getRow();

        return $row !== null && trim((string) $row->company_name) !== '' ? trim((string) $row->company_name) : $this->personName($supplierId);
    }
}
