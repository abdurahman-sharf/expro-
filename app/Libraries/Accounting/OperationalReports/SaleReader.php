<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Accounting\SaleLine;
use App\Libraries\ResolvedCharges;
use App\Libraries\ShippingTaxOption;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reads many saved sales in the shape posting reads one, in a few queries instead of a few for each: what
 * is stored is what the journals were built from, so the figures worked out from these documents are the
 * figures of the journals. A document comes without payments and without the sale it is a return of,
 * which no report of the family needs.
 */
final class SaleReader
{
    private const CHUNK = 400;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<int> $saleIds
     *
     * @return array<int, SaleDocument>
     */
    public function documents(array $saleIds): array
    {
        $documents = [];

        foreach (array_chunk(array_values(array_unique($saleIds)), self::CHUNK) as $chunk) {
            $lines = $this->lines($chunk);

            foreach ($this->db->table('sales')
                ->select('sale_id, sale_time, invoice_date, customer_id, sale_status, sale_type, branch_id, delivered, return_of_sale_id, return_document, adjustment_label, adjustment_amount, shipping_amount, shipping_options, shipping_tax_option, shipping_tax_amount')
                ->whereIn('sale_id', $chunk)
                ->get()
                ->getResult() as $sale) {
                $savedOn = substr((string) $sale->sale_time, 0, 10);

                $documents[(int) $sale->sale_id] = new SaleDocument(
                    saleId: (int) $sale->sale_id,
                    date: $sale->invoice_date === null ? $savedOn : (string) $sale->invoice_date,
                    saleType: (int) $sale->sale_type,
                    status: (int) $sale->sale_status,
                    customerId: $sale->customer_id === null ? null : (int) $sale->customer_id,
                    branchId: (int) $sale->branch_id,
                    delivered: (int) $sale->delivered === 1,
                    returnOfSaleId: $sale->return_of_sale_id === null ? null : (int) $sale->return_of_sale_id,
                    lines: $lines[(int) $sale->sale_id] ?? [],
                    payments: [],
                    savedOn: $savedOn,
                    refundInvoice: $sale->return_document === ReturnDocument::RefundInvoice->storedValue(),
                    charges: $this->charges($sale),
                );
            }
        }

        return $documents;
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
     * @param list<int> $saleIds
     *
     * @return array<int, list<SaleLine>>
     */
    private function lines(array $saleIds): array
    {
        $taxes    = [];
        $accounts = [];

        foreach ($this->db->table('sales_items_taxes')
            ->select('sale_id, item_id, line, tax_type, liability_account_id, SUM(item_tax_amount) AS amount', false)
            ->whereIn('sale_id', $saleIds)
            ->groupBy('sale_id, item_id, line, tax_type, liability_account_id')
            ->get()
            ->getResult() as $row) {
            $key  = $row->sale_id . ':' . $row->item_id . ':' . $row->line;
            $type = (int) $row->tax_type;

            $taxes[$key][$type] = bcadd($taxes[$key][$type] ?? '0', (string) $row->amount, 4);

            if ($row->liability_account_id !== null) {
                $accounts[$key][(int) $row->liability_account_id] = bcadd($accounts[$key][(int) $row->liability_account_id] ?? '0', SaleAmounts::absolute((string) $row->amount), 4);
            }
        }

        $lines = [];

        foreach ($this->db->table('sales_items i')
            ->select('i.sale_id, i.item_id, i.line, i.quantity_purchased, i.item_unit_price, i.discount, i.discount_type, i.item_cost_price, i.item_location, i.invoice_discount_share, it.stock_type')
            ->join('items it', 'it.item_id = i.item_id')
            ->whereIn('i.sale_id', $saleIds)
            ->orderBy('i.sale_id', 'ASC')
            ->orderBy('i.line', 'ASC')
            ->get()
            ->getResult() as $row) {
            $key       = $row->sale_id . ':' . $row->item_id . ':' . $row->line;
            $lineTaxes = $taxes[$key] ?? [];

            $lines[(int) $row->sale_id][] = new SaleLine(
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
                accountTaxes: $accounts[$key] ?? [],
            );
        }

        return $lines;
    }
}
