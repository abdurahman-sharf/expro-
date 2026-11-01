<?php

namespace App\Libraries\Accounting;

use App\Libraries\ResolvedCharges;
use App\Libraries\ShippingTaxOption;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reads a saved sale in the shape posting needs. What is posted is what is stored, not what
 * the register held, so the journals follow the sale report whatever path saved the sale.
 */
final class SaleLoader
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function load(int $saleId, bool $withOrigin = true): ?SaleDocument
    {
        $sale = $this->db->table('sales')
            ->select('sale_id, sale_time, invoice_date, customer_id, sale_status, sale_type, branch_id, delivered, return_of_sale_id, return_document, adjustment_label, adjustment_amount, shipping_amount, shipping_options, shipping_tax_option, shipping_tax_amount, currency_code, currency_rate')
            ->where('sale_id', $saleId)
            ->get()
            ->getRow();

        if ($sale === null) {
            return null;
        }

        $origin = $withOrigin && $sale->return_of_sale_id !== null ? $this->load((int) $sale->return_of_sale_id, false) : null;

        $savedOn = substr((string) $sale->sale_time, 0, 10);

        return new SaleDocument(
            saleId: (int) $sale->sale_id,
            date: $sale->invoice_date === null ? $savedOn : (string) $sale->invoice_date,
            saleType: (int) $sale->sale_type,
            status: (int) $sale->sale_status,
            customerId: $sale->customer_id === null ? null : (int) $sale->customer_id,
            branchId: (int) $sale->branch_id,
            delivered: (int) $sale->delivered === 1,
            returnOfSaleId: $sale->return_of_sale_id === null ? null : (int) $sale->return_of_sale_id,
            lines: $this->lines($saleId),
            payments: $this->payments($saleId),
            origin: $origin,
            savedOn: $savedOn,
            returnOverride: $withOrigin && $sale->return_of_sale_id === null && (new ReturnOverride($this->db))->exists($saleId),
            refundInvoice: $sale->return_document === ReturnDocument::RefundInvoice->storedValue(),
            charges: $this->charges($sale),
            currency: (new Currencies($this->db))->stored($sale->currency_code, $sale->currency_rate),
        );
    }

    private function charges(object $sale): ?ResolvedCharges
    {
        $charges = new ResolvedCharges(
            adjustmentLabel: (string) ($sale->adjustment_label ?? ''),
            adjustment: bcadd((string) ($sale->adjustment_amount ?? '0'), '0', SaleAmounts::SCALE),
            shipping: bcadd((string) ($sale->shipping_amount ?? '0'), '0', SaleAmounts::SCALE),
            shippingTax: bcadd((string) ($sale->shipping_tax_amount ?? '0'), '0', SaleAmounts::SCALE),
            shippingTaxIncluded: ShippingTaxOption::fromStored($sale->shipping_tax_option) === ShippingTaxOption::Included,
            shippingOptions: $sale->shipping_options === null ? null : (int) $sale->shipping_options,
        );

        return $charges->isEmpty() ? null : $charges;
    }

    /**
     * @return list<SaleLine>
     */
    private function lines(int $saleId): array
    {
        $taxes    = [];
        $accounts = [];

        $rows = $this->db->table('sales_items_taxes')
            ->select('item_id, line, tax_type, liability_account_id, SUM(item_tax_amount) AS amount', false)
            ->where('sale_id', $saleId)
            ->groupBy('item_id, line, tax_type, liability_account_id')
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $key  = $row->item_id . ':' . $row->line;
            $type = (int) $row->tax_type;

            $taxes[$key][$type] = bcadd($taxes[$key][$type] ?? '0', (string) $row->amount, 4);

            if ($row->liability_account_id !== null) {
                $accounts[$key][(int) $row->liability_account_id] = bcadd($accounts[$key][(int) $row->liability_account_id] ?? '0', SaleAmounts::absolute((string) $row->amount), 4);
            }
        }

        $lines = [];

        foreach ($this->db->table('sales_items i')
            ->select('i.item_id, i.line, i.quantity_purchased, i.item_unit_price, i.discount, i.discount_type, i.item_cost_price, i.item_location, i.invoice_discount_share, it.stock_type')
            ->join('items it', 'it.item_id = i.item_id')
            ->where('i.sale_id', $saleId)
            ->orderBy('i.line', 'ASC')
            ->get()
            ->getResult() as $row) {
            $lineTaxes = $taxes[$row->item_id . ':' . $row->line] ?? [];

            $lines[] = new SaleLine(
                itemId: (int) $row->item_id,
                line: (int) $row->line,
                quantity: (string) $row->quantity_purchased,
                unitPrice: (string) $row->item_unit_price,
                discount: (string) $row->discount,
                discountType: (int) $row->discount_type,
                costPrice: (string) $row->item_cost_price,
                locationId: (int) $row->item_location,
                hasStock: (int) $row->stock_type === HAS_STOCK,
                includedTax: $lineTaxes[0] ?? '0',
                addedTax: $lineTaxes[1] ?? '0',
                invoiceDiscountShare: (string) ($row->invoice_discount_share ?? '0'),
                accountTaxes: $accounts[$row->item_id . ':' . $row->line] ?? [],
            );
        }

        return $lines;
    }

    /**
     * @return list<SalePayment>
     */
    private function payments(int $saleId): array
    {
        $payments = [];

        foreach ($this->db->table('sales_payments')
            ->select('payment_id, payment_type, payment_amount, cash_refund, cash_adjustment, payment_time, payment_status, treasury_id, treasury_branch_id, currency_rate')
            ->where('sale_id', $saleId)
            ->orderBy('payment_id', 'ASC')
            ->get()
            ->getResult() as $row) {
            $payments[] = new SalePayment(
                paymentId: (int) $row->payment_id,
                type: (string) $row->payment_type,
                amount: (string) $row->payment_amount,
                cashRefund: (string) $row->cash_refund,
                cashAdjustment: (int) $row->cash_adjustment === 1,
                date: substr((string) $row->payment_time, 0, 10),
                status: PaymentStatus::fromStored($row->payment_status),
                treasuryId: $row->treasury_id === null ? null : (int) $row->treasury_id,
                treasuryBranchId: $row->treasury_branch_id === null ? null : (int) $row->treasury_branch_id,
                rate: $row->currency_rate === null ? null : bcadd((string) $row->currency_rate, '0', 8),
            );
        }

        return $payments;
    }
}
