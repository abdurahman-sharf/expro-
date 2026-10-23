<?php

namespace App\Libraries\Accounting;

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
            ->select('sale_id, sale_time, customer_id, sale_status, sale_type, branch_id, delivered, return_of_sale_id')
            ->where('sale_id', $saleId)
            ->get()
            ->getRow();

        if ($sale === null) {
            return null;
        }

        $origin = $withOrigin && $sale->return_of_sale_id !== null ? $this->load((int) $sale->return_of_sale_id, false) : null;

        return new SaleDocument(
            saleId: (int) $sale->sale_id,
            date: substr((string) $sale->sale_time, 0, 10),
            saleType: (int) $sale->sale_type,
            status: (int) $sale->sale_status,
            customerId: $sale->customer_id === null ? null : (int) $sale->customer_id,
            branchId: (int) $sale->branch_id,
            delivered: (int) $sale->delivered === 1,
            returnOfSaleId: $sale->return_of_sale_id === null ? null : (int) $sale->return_of_sale_id,
            lines: $this->lines($saleId),
            payments: $this->payments($saleId),
            origin: $origin,
        );
    }

    /**
     * @return list<SaleLine>
     */
    private function lines(int $saleId): array
    {
        $taxes = [];

        $rows = $this->db->table('sales_items_taxes')
            ->select('item_id, line, tax_type, SUM(item_tax_amount) AS amount', false)
            ->where('sale_id', $saleId)
            ->groupBy('item_id, line, tax_type')
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $taxes[$row->item_id . ':' . $row->line][(int) $row->tax_type] = (string) $row->amount;
        }

        $lines = [];

        foreach ($this->db->table('sales_items i')
            ->select('i.item_id, i.line, i.quantity_purchased, i.item_unit_price, i.discount, i.discount_type, i.item_cost_price, i.item_location, it.stock_type')
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
            ->select('payment_id, payment_type, payment_amount, cash_refund, cash_adjustment, payment_time')
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
            );
        }

        return $payments;
    }
}
