<?php

namespace App\Libraries\Accounting;

use App\Libraries\InvoiceDiscount;
use App\Libraries\InvoiceDiscountProblem;
use App\Libraries\Tax_lib;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A draft invoice is a sale that was suspended while it was being made as an invoice. It has no
 * number of its sequence and none is given until it is issued, so it is known by a temporary one
 * worked out from its id, which is never stored and takes nothing from the sequence. It posts no
 * journal and moves no stock. This reads a draft in the shape the sale model saves it as an issued
 * invoice, with the taxes it comes to at the moment it is issued, as they would be if the register
 * took it up and completed it.
 */
final class DraftInvoice
{
    public const PREFIX = 'draft-';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public static function label(int $saleId): string
    {
        return self::PREFIX . $saleId;
    }

    /**
     * The row of a draft invoice, or null when the sale is not one (it was issued, cancelled, or
     * is another kind of document).
     */
    public function find(int $saleId): ?object
    {
        return $this->db->table('sales')
            ->select('sale_id, sale_time, customer_id, employee_id, comment, invoice_number, branch_id, delivered, dinner_table_id, invoice_discount_type, invoice_discount, invoice_date, payment_terms_days, source_quote_id')
            ->where(['sale_id' => $saleId, 'sale_status' => SUSPENDED, 'sale_type' => SALE_TYPE_INVOICE])
            ->get()
            ->getRow();
    }

    public function isDraft(int $saleId): bool
    {
        return $this->find($saleId) !== null;
    }

    /**
     * What saving the draft as an issued invoice takes, or null when the sale is not a draft.
     */
    public function forIssue(int $saleId): ?DraftIssue
    {
        $draft = $this->find($saleId);

        return $draft === null ? null : $this->build($draft);
    }

    /**
     * What an invoice made now from a suspended invoice or quote would carry, for a page that shows
     * it before anything is saved, or null when the sale is neither.
     */
    public function preview(int $saleId): ?DraftIssue
    {
        $row = $this->db->table('sales')
            ->select('sale_id, sale_time, customer_id, employee_id, comment, invoice_number, branch_id, delivered, dinner_table_id, invoice_discount_type, invoice_discount, invoice_date, payment_terms_days, source_quote_id')
            ->where('sale_status', SUSPENDED)
            ->where('sale_id', $saleId)
            ->whereIn('sale_type', [SALE_TYPE_INVOICE, SALE_TYPE_QUOTE])
            ->get()
            ->getRow();

        return $row === null ? null : $this->build($row);
    }

    private function build(object $draft): DraftIssue
    {
        $saleId     = (int) $draft->sale_id;
        $items      = $this->lines($saleId);
        $customerId = $draft->customer_id === null ? null : (int) $draft->customer_id;
        $discount   = $draft->invoice_discount_type === null || $draft->invoice_discount === null
            ? null
            : ['type' => (int) $draft->invoice_discount_type, 'value' => (string) $draft->invoice_discount];
        $problem    = null;

        if ($discount !== null) {
            $plan = InvoiceDiscount::forSale($items, $discount['type'], $discount['value'], totals_decimals());

            if ($plan instanceof InvoiceDiscountProblem) {
                $problem = $plan;
            } else {
                $items = $plan->applyTo($items);
            }
        }

        $taxes = $problem === null && $items !== [] ? (new Tax_lib())->get_taxes($items, NEW_ENTRY, $customerId ?? NEW_ENTRY, 'sale_invoice') : [[], []];

        [$payments, $paidWithStoredValue] = $this->payments($saleId);

        return new DraftIssue(
            saleId: $saleId,
            items: $items,
            taxes: $taxes,
            payments: $payments,
            customerId: $customerId,
            comment: (string) $draft->comment,
            dinnerTableId: $draft->dinner_table_id === null ? null : (int) $draft->dinner_table_id,
            delivered: (int) $draft->delivered === 1,
            invoiceDiscount: $discount,
            invoiceNumber: $draft->invoice_number === null || $draft->invoice_number === '' ? null : (string) $draft->invoice_number,
            invoiceDate: $draft->invoice_date === null ? null : (string) $draft->invoice_date,
            paymentTermsDays: $draft->payment_terms_days === null ? null : (int) $draft->payment_terms_days,
            paidWithStoredValue: $paidWithStoredValue,
            problem: $problem,
            document: $this->document($draft, $items, $taxes),
        );
    }

    /**
     * What the draft comes to as it stands: what its lines sell for, what their own discounts and
     * the discount on the whole of it take off, the tax it would carry now, and the total. The tax is
     * the one worked out at this moment, so it can still change before the draft is issued.
     *
     * @return array{gross: string, discount: string, invoiceDiscount: string, tax: string, total: string}
     */
    public function figures(DraftIssue $issue): array
    {
        $decimals = totals_decimals();
        $gross    = '0';
        $discount = '0';
        $share    = '0';
        $added    = '0';
        $included = '0';

        foreach ($issue->document->lines ?? [] as $line) {
            $gross    = bcadd($gross, SaleAmounts::lineGross($line), SaleAmounts::SCALE);
            $discount = bcadd($discount, SaleAmounts::lineDiscount($line, $decimals), SaleAmounts::SCALE);
            $share    = bcadd($share, SaleAmounts::absolute($line->invoiceDiscountShare), SaleAmounts::SCALE);
            $added    = bcadd($added, SaleAmounts::absolute($line->addedTax), SaleAmounts::SCALE);
            $included = bcadd($included, SaleAmounts::absolute($line->includedTax), SaleAmounts::SCALE);
        }

        return [
            'gross'           => $gross,
            'discount'        => $discount,
            'invoiceDiscount' => $share,
            'tax'             => bcadd($added, $included, SaleAmounts::SCALE),
            'total'           => bcadd(bcsub(bcsub($gross, $discount, SaleAmounts::SCALE), $share, SaleAmounts::SCALE), $added, SaleAmounts::SCALE),
        ];
    }

    /**
     * The lines as the register carries them: the price they were saved at and the cost of the item
     * now, as the register takes both when it brings a suspended sale back.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lines(int $saleId): array
    {
        $rows = $this->db->table('sales_items i')
            ->select('i.item_id, i.line, i.description, i.serialnumber, i.quantity_purchased, i.discount, i.discount_type, i.item_unit_price, i.item_location, i.print_option, it.cost_price, it.tax_category_id')
            ->join('items it', 'it.item_id = i.item_id')
            ->where('i.sale_id', $saleId)
            ->orderBy('i.line', 'ASC')
            ->get()
            ->getResult();

        $items = [];

        foreach ($rows as $row) {
            $items[(int) $row->line] = [
                'item_id'         => (int) $row->item_id,
                'line'            => (int) $row->line,
                'description'     => (string) $row->description,
                'serialnumber'    => (string) $row->serialnumber,
                'quantity'        => (string) $row->quantity_purchased,
                'discount'        => (string) $row->discount,
                'discount_type'   => (int) $row->discount_type,
                'cost_price'      => (string) $row->cost_price,
                'price'           => (string) $row->item_unit_price,
                'item_location'   => (int) $row->item_location,
                'print_option'    => (int) $row->print_option,
                'tax_category_id' => $row->tax_category_id === null ? null : (int) $row->tax_category_id,
            ];
        }

        return $items;
    }

    /**
     * @return array{0: array<string, array<string, mixed>>, 1: bool} The payments by their type, and whether one of them is a gift card or reward points.
     */
    private function payments(int $saleId): array
    {
        $payments = [];
        $stored   = false;

        foreach ($this->db->table('sales_payments')->where('sale_id', $saleId)->orderBy('payment_id', 'ASC')->get()->getResult() as $row) {
            $payments[(string) $row->payment_type] = [
                'payment_type'    => (string) $row->payment_type,
                'payment_amount'  => (string) $row->payment_amount,
                'cash_refund'     => (string) $row->cash_refund,
                'cash_adjustment' => (int) $row->cash_adjustment,
                'reference_code'  => (string) $row->reference_code,
                'treasury_id'     => $row->treasury_id === null ? 0 : (int) $row->treasury_id,
            ];

            $type   = PaymentType::fromLabel((string) $row->payment_type);
            $stored = $stored || $type === PaymentType::Giftcard || $type === PaymentType::Rewards;
        }

        return [$payments, $stored];
    }

    /**
     * @param array<int, array<string, mixed>>                                             $items
     * @param array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>} $taxes
     */
    private function document(object $draft, array $items, array $taxes): SaleDocument
    {
        $byLine = [];

        foreach ($taxes[1] as $tax) {
            $key = $tax['item_id'] . ':' . $tax['line'];
            $type = (int) $tax['tax_type'];

            $byLine[$key][$type] = bcadd($byLine[$key][$type] ?? '0', (string) $tax['item_tax_amount'], SaleAmounts::SCALE);
        }

        $lines = [];

        foreach ($items as $item) {
            $key     = $item['item_id'] . ':' . $item['line'];
            $lines[] = new SaleLine(
                itemId: (int) $item['item_id'],
                line: (int) $item['line'],
                quantity: (string) $item['quantity'],
                unitPrice: (string) $item['price'],
                discount: (string) $item['discount'],
                discountType: (int) $item['discount_type'],
                costPrice: (string) $item['cost_price'],
                locationId: (int) $item['item_location'],
                hasStock: false,
                includedTax: $byLine[$key][0] ?? '0',
                addedTax: $byLine[$key][1] ?? '0',
                invoiceDiscountShare: (string) ($item['invoice_discount_share'] ?? '0'),
            );
        }

        return new SaleDocument(
            saleId: (int) $draft->sale_id,
            date: substr((string) $draft->sale_time, 0, 10),
            saleType: SALE_TYPE_INVOICE,
            status: SUSPENDED,
            customerId: $draft->customer_id === null ? null : (int) $draft->customer_id,
            branchId: (int) $draft->branch_id,
            delivered: (int) $draft->delivered === 1,
            returnOfSaleId: null,
            lines: $lines,
            payments: [],
        );
    }
}
