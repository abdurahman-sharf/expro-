<?php

namespace App\Libraries\PurchaseCycle;

use App\Libraries\Numbering\DocumentType;

/**
 * The four documents of the purchase cycle, in the order one is made from the next: the purchase request,
 * the quotation request, the quotation of a supplier and the purchase order, which the receiving of the goods
 * (the purchase invoice) closes. Each is stored in a table of its own, so an id of one kind is never the id
 * of a document of another. None of the four was observed in the reference system, whose purchase cycle
 * app is switched off in the account: the way they are built is the choice of this system, not a copy.
 */
enum PurchaseDocumentKind: string
{
    case Request           = 'purchase_request';
    case QuotationRequest  = 'quotation_request';
    case SupplierQuotation = 'supplier_quotation';
    case Order             = 'purchase_order';

    public function table(): string
    {
        return match ($this) {
            self::Request           => 'purchase_requests',
            self::QuotationRequest  => 'quotation_requests',
            self::SupplierQuotation => 'supplier_quotations',
            self::Order             => 'purchase_orders',
        };
    }

    public function linesTable(): string
    {
        return match ($this) {
            self::Request           => 'purchase_request_lines',
            self::QuotationRequest  => 'quotation_request_lines',
            self::SupplierQuotation => 'supplier_quotation_lines',
            self::Order             => 'purchase_order_lines',
        };
    }

    public function idColumn(): string
    {
        return match ($this) {
            self::Request           => 'request_id',
            self::QuotationRequest  => 'rfq_id',
            self::SupplierQuotation => 'quotation_id',
            self::Order             => 'order_id',
        };
    }

    /**
     * The column that holds the document this one was made from.
     */
    public function sourceColumn(): ?string
    {
        return match ($this) {
            self::Request           => null,
            self::QuotationRequest  => 'source_request_id',
            self::SupplierQuotation => 'source_rfq_id',
            self::Order             => 'source_quotation_id',
        };
    }

    /**
     * The module of the screens and of the permissions of the family; it does not start with the id of an existing module.
     */
    public function module(): string
    {
        return $this->table();
    }

    /**
     * The permission to do something with the documents of the kind: add, edit, delete (cancel), view_all or convert.
     */
    public function permission(string $action): string
    {
        return match ($this) {
            self::Request           => 'pr_',
            self::QuotationRequest  => 'qr_',
            self::SupplierQuotation => 'sq_',
            self::Order             => 'po_',
        } . $action;
    }

    public function documentType(): DocumentType
    {
        return match ($this) {
            self::Request           => DocumentType::PurchaseRequest,
            self::QuotationRequest  => DocumentType::QuotationRequest,
            self::SupplierQuotation => DocumentType::SupplierQuotation,
            self::Order             => DocumentType::PurchaseOrder,
        };
    }

    /**
     * The setting, named as the reference system names its four toggles, that lets the documents of the
     * kind carry a status set by hand.
     */
    public function manualStatusSetting(): string
    {
        return match ($this) {
            self::Request           => 'enable_purchase_request_manual_status',
            self::QuotationRequest  => 'enable_quotation_request_manual_status',
            self::SupplierQuotation => 'enable_purchase_quotation_manual_status',
            self::Order             => 'enable_purchase_order_manual_status',
        };
    }

    public function hasSupplier(): bool
    {
        return $this !== self::Request;
    }

    public function needsSupplier(): bool
    {
        return $this === self::SupplierQuotation || $this === self::Order;
    }

    /**
     * The kind of document that is made from this one, null for the order, which the receiving closes.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Request           => self::QuotationRequest,
            self::QuotationRequest  => self::SupplierQuotation,
            self::SupplierQuotation => self::Order,
            self::Order             => null,
        };
    }

    public function labelKey(): string
    {
        return 'Purchase_cycle.kind_' . $this->value;
    }

    public function dueLabelKey(): string
    {
        return 'Purchase_cycle.due_' . $this->value;
    }

    public static function ofModule(string $module): ?self
    {
        foreach (self::cases() as $kind) {
            if ($kind->module() === $module) {
                return $kind;
            }
        }

        return null;
    }
}
