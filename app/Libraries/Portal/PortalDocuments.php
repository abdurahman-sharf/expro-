<?php

namespace App\Libraries\Portal;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerPosition;
use App\Libraries\Accounting\Money;
use App\Libraries\Accounting\PaymentStatus;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SaleLoader;
use App\Libraries\Documents\VoucherDocument;
use App\Libraries\Documents\VoucherDocuments;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use Closure;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The documents of one customer, read for the portal: their invoices, their credit notes and refund
 * invoices, and their payments. The customer is always an argument that the caller took from the portal
 * session, every query is bound to it, and a document of another customer is not found: it is the same
 * answer as a document that does not exist. A document is read from the saved sale and shows prices,
 * discounts, tax, charges and what was paid, and nothing of what the shop paid for the goods, of who sold
 * them, of the books or of any other party.
 */
final class PortalDocuments
{
    public const PAGE_SIZE = 25;

    private BaseConnection $db;
    private DocumentNumbering $numbering;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->numbering = new DocumentNumbering($this->db);
    }

    /**
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function invoices(int $customerId, int $page): array
    {
        $found    = $this->page(fn (): BaseBuilder => $this->sales($customerId, [SALE_TYPE_POS, SALE_TYPE_INVOICE]), $page);
        $position = $this->position($customerId);
        $rows     = [];

        foreach ($found['rows'] as $sale) {
            $figures = $position?->invoice((int) $sale->sale_id);
            $total   = $this->totalOf((int) $sale->sale_id);

            $rows[] = [
                'sale_id'  => (int) $sale->sale_id,
                'number'   => $this->invoiceLabel($sale),
                'date'     => $this->dateOf($sale),
                'due_date' => $figures === null ? null : to_date(strtotime($figures->dueDate)),
                'total'    => $this->money($total, $sale->currency_code),
                'open'     => $figures === null ? null : $this->money($figures->open(), null),
                'state'    => $figures?->paymentState()->languageKey(),
            ];
        }

        return ['total' => $found['total'], 'rows' => $rows];
    }

    /**
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function creditNotes(int $customerId, int $page): array
    {
        $found = $this->page(fn (): BaseBuilder => $this->sales($customerId, [SALE_TYPE_RETURN]), $page);
        $rows  = [];

        foreach ($found['rows'] as $sale) {
            [$title, $number] = $this->returnLabels($sale);

            $rows[] = [
                'sale_id' => (int) $sale->sale_id,
                'title'   => $title,
                'number'  => $number,
                'date'    => $this->dateOf($sale),
                'origin'  => $this->originLabel($customerId, $sale),
                'total'   => $this->money($this->totalOf((int) $sale->sale_id), $sale->currency_code),
            ];
        }

        return ['total' => $found['total'], 'rows' => $rows];
    }

    /**
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function payments(int $customerId, int $page): array
    {
        $total = $this->receipts($customerId)->countAllResults();
        $rows  = [];

        foreach ($this->receipts($customerId)->select('p.payment_id, p.payment_time, p.payment_type, p.payment_amount, p.cash_refund, p.reference_code, p.currency_code AS payment_currency, s.sale_id, s.invoice_number, s.currency_code')->orderBy('p.payment_time', 'DESC')->orderBy('p.payment_id', 'DESC')->limit(self::PAGE_SIZE, $this->offset($page))->get()->getResult() as $payment) {
            $rows[] = [
                'payment_id' => (int) $payment->payment_id,
                'number'     => $this->numbering->label(DocumentType::CustomerPayment, 'sales_payments', (int) $payment->payment_id),
                'date'       => to_date(strtotime((string) $payment->payment_time)),
                'method'     => (string) $payment->payment_type,
                'invoice'    => $this->invoiceLabel($payment),
                'reference'  => (string) $payment->reference_code,
                'amount'     => $this->money(bcsub((string) $payment->payment_amount, (string) $payment->cash_refund, SaleAmounts::SCALE), $payment->payment_currency ?? $payment->currency_code),
            ];
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * An invoice of the customer to read and to print, or null when the customer has no such invoice.
     *
     * @return array<string, mixed>|null
     */
    public function invoice(int $customerId, int $saleId): ?array
    {
        $sale = $this->sales($customerId, [SALE_TYPE_POS, SALE_TYPE_INVOICE])->where('s.sale_id', $saleId)->get()->getRow();

        if ($sale === null) {
            return null;
        }

        $figures = $this->position($customerId)?->invoice($saleId);

        return $this->document($customerId, $sale, lang((int) $sale->sale_type === SALE_TYPE_INVOICE ? 'Sales.invoice' : 'Sales.receipt'), $this->invoiceLabel($sale)) + [
            'due_date' => $figures === null ? null : to_date(strtotime($figures->dueDate)),
            'settled'  => $figures === null ? null : $this->money($figures->settled(), null),
            'open'     => $figures === null ? null : $this->money($figures->open(), null),
            'origin'   => null,
        ];
    }

    /**
     * A credit note or refund invoice of the customer to read and to print, or null when the customer has none.
     *
     * @return array<string, mixed>|null
     */
    public function creditNote(int $customerId, int $saleId): ?array
    {
        $sale = $this->sales($customerId, [SALE_TYPE_RETURN])->where('s.sale_id', $saleId)->get()->getRow();

        if ($sale === null) {
            return null;
        }

        [$title, $number] = $this->returnLabels($sale);

        return $this->document($customerId, $sale, $title, $number) + ['due_date' => null, 'settled' => null, 'open' => null, 'origin' => $this->originLabel($customerId, $sale)];
    }

    /**
     * The receipt of a payment of the customer, or null when the customer has no such payment. What the
     * shop prints for the payment is used, without the treasury it went to and the employee who took it.
     */
    public function payment(int $customerId, int $paymentId): ?VoucherDocument
    {
        if ($this->receipts($customerId)->where('p.payment_id', $paymentId)->countAllResults() !== 1) {
            return null;
        }

        $voucher = (new VoucherDocuments($this->db))->customerPayment($paymentId, null);

        if ($voucher === null) {
            return null;
        }

        $hidden = [lang('Documents.treasury'), lang('Documents.received_by')];

        return new VoucherDocument(
            $voucher->kind,
            $voucher->title,
            $voucher->number,
            $voucher->date,
            $voucher->partyLabel,
            $voucher->partyName,
            $voucher->amount,
            $voucher->amountText,
            $voucher->currencyCode,
            array_values(array_filter($voucher->rows, static fn (array $row): bool => ! in_array($row[0], $hidden, true))),
            $voucher->signatures,
            $voucher->amountWords,
            0,
        );
    }

    public function customerName(int $customerId): string
    {
        $row = $this->db->table('customers c')
            ->select("TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS person, c.company_name", false)
            ->join('people p', 'p.person_id = c.person_id')
            ->where('c.person_id', $customerId)
            ->get()
            ->getRow();

        return $row === null ? '' : trim((string) ($row->company_name ?: $row->person));
    }

    private function sales(int $customerId, array $types): BaseBuilder
    {
        return $this->db->table('sales s')
            ->select('s.sale_id, s.sale_time, s.invoice_date, s.invoice_number, s.sale_type, s.return_of_sale_id, s.return_document, s.currency_code')
            ->where('s.customer_id', $customerId)
            ->where('s.sale_status', COMPLETED)
            ->whereIn('s.sale_type', $types);
    }

    private function receipts(int $customerId): BaseBuilder
    {
        return $this->db->table('sales_payments p')
            ->join('sales s', 's.sale_id = p.sale_id')
            ->where('s.customer_id', $customerId)
            ->where('s.sale_status', COMPLETED)
            ->where('p.cash_adjustment', 0)
            ->where('p.payment_status', PaymentStatus::Completed->value)
            ->where('(p.payment_amount - p.cash_refund) >', 0, false)
            ->whereNotIn('p.payment_type', PaymentType::Due->labels());
    }

    /**
     * The query is made twice, once to count and once to read a page, because counting a builder takes the
     * names of its tables from the connection.
     *
     * @param Closure(): BaseBuilder $builder
     *
     * @return array{total: int, rows: list<object>}
     */
    private function page(Closure $builder, int $page): array
    {
        $total = $builder()->countAllResults();
        $rows  = $builder()->orderBy('COALESCE(s.invoice_date, DATE(s.sale_time))', 'DESC', false)->orderBy('s.sale_id', 'DESC')->limit(self::PAGE_SIZE, $this->offset($page))->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    private function offset(int $page): int
    {
        return (max(1, $page) - 1) * self::PAGE_SIZE;
    }

    private function position(int $customerId): ?CustomerPosition
    {
        return (new AccountingSettings($this->db))->isEnabled() ? (new CustomerLedger($this->db))->position($customerId) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function document(int $customerId, object $sale, string $title, string $number): array
    {
        $loaded   = (new SaleLoader($this->db))->load((int) $sale->sale_id, false);
        $figures  = SaleAmounts::figures($loaded, (new Currencies($this->db))->baseDecimals());
        $currency = $sale->currency_code;
        $names    = [];
        $lines    = [];
        $isReturn = (int) $sale->sale_type === SALE_TYPE_RETURN;

        foreach ($this->db->table('sales_items i')->select('i.line, it.name')->join('items it', 'it.item_id = i.item_id')->where('i.sale_id', (int) $sale->sale_id)->get()->getResult() as $item) {
            $names[(int) $item->line] = (string) $item->name;
        }

        foreach ($loaded->lines as $line) {
            $quantity = $isReturn ? SaleAmounts::absolute($line->quantity) : $line->quantity;
            $gross    = SaleAmounts::lineGross($line);
            $discount = SaleAmounts::lineDiscount($line, (new Currencies($this->db))->baseDecimals());

            $lines[] = [
                'name'     => $names[$line->line] ?? '',
                'quantity' => rtrim(rtrim(bcadd($quantity, '0', 4), '0'), '.'),
                'price'    => $this->money(SaleAmounts::absolute($line->unitPrice), $currency),
                'discount' => bccomp($discount, '0', SaleAmounts::SCALE) === 0 ? '' : $this->money($discount, $currency),
                'total'    => $this->money(SaleAmounts::absolute(bcsub($gross, $discount, SaleAmounts::SCALE)), $currency),
            ];
        }

        $payments = [];

        foreach ($this->receipts($customerId)->where('p.sale_id', (int) $sale->sale_id)->select('p.payment_time, p.payment_type, p.payment_amount, p.cash_refund')->orderBy('p.payment_id', 'ASC')->get()->getResult() as $payment) {
            $payments[] = ['date' => to_date(strtotime((string) $payment->payment_time)), 'method' => (string) $payment->payment_type, 'amount' => $this->money(bcsub((string) $payment->payment_amount, (string) $payment->cash_refund, SaleAmounts::SCALE), $currency)];
        }

        return [
            'sale_id'    => (int) $sale->sale_id,
            'title'      => $title,
            'number'     => $number,
            'date'       => $this->dateOf($sale),
            'currency'   => $currency ?? (new Currencies($this->db))->baseCode(),
            'lines'      => $lines,
            'goods'      => $this->money(SaleAmounts::absolute($figures['revenue']), $currency),
            'shipping'   => bccomp($figures['shipping'], '0', SaleAmounts::SCALE) === 0 ? null : $this->money(SaleAmounts::absolute($figures['shipping']), $currency),
            'adjustment' => bccomp($figures['adjustment'], '0', SaleAmounts::SCALE) === 0 ? null : $this->money(SaleAmounts::absolute($figures['adjustment']), $currency),
            'tax'        => $this->money(SaleAmounts::absolute($figures['tax']), $currency),
            'discount'   => bccomp($figures['discount'], '0', SaleAmounts::SCALE) === 0 ? null : $this->money(SaleAmounts::absolute($figures['discount']), $currency),
            'total'      => $this->money(SaleAmounts::absolute($figures['total']), $currency),
            'payments'   => $payments,
        ];
    }

    private function totalOf(int $saleId): string
    {
        $loaded = (new SaleLoader($this->db))->load($saleId, false);

        return SaleAmounts::absolute(SaleAmounts::figures($loaded, (new Currencies($this->db))->baseDecimals())['total']);
    }

    private function invoiceLabel(object $sale): string
    {
        return (string) ($sale->invoice_number ?: 'POS ' . (int) $sale->sale_id);
    }

    private function dateOf(object $sale): string
    {
        return to_date(strtotime((string) ($sale->invoice_date ?? $sale->sale_time)));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function returnLabels(object $sale): array
    {
        $kind = ReturnDocument::fromStored($sale->return_document);

        return [lang($kind->languageKey()), $this->numbering->label($kind->documentType(), 'sales', (int) $sale->sale_id)];
    }

    private function originLabel(int $customerId, object $sale): ?string
    {
        if ($sale->return_of_sale_id === null) {
            return null;
        }

        $origin = $this->db->table('sales')->select('sale_id, invoice_number')->where(['sale_id' => (int) $sale->return_of_sale_id, 'customer_id' => $customerId])->get()->getRow();

        return $origin === null ? null : $this->invoiceLabel($origin);
    }

    private function money(string $amount, ?string $currency): string
    {
        $currencies = new Currencies($this->db);
        $code       = $currency ?? $currencies->baseCode();

        return Money::format($amount, $currencies->decimalsOf($code), $code);
    }
}
