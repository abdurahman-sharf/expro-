<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SaleDocument;
use App\Libraries\Accounting\SaleLine;
use App\Libraries\Accounting\SaleLoader;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Tax\LineVat;
use App\Libraries\Tax\VatClassification;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The document of a sale, a return or a sales debit note, with the figures of the books and no others: the
 * totals, the tax and the discounts are those SaleAmounts gives the posting of the sale, taken line by line
 * and rounded once to the two decimals the standard allows. The document is then held against the rules of
 * the standard that tie its parts together (the sum of the lines, the tax of a category from its taxable
 * amount) and against the figures of the books, and a document that does not tie is refused with the rule
 * and the two figures, never adjusted: a cent of difference means the books and the standard cannot both be
 * written down as they are. A sale in a foreign currency, one with charges in its header and one with a line
 * that carries several taxes are refused too, since this candidate does not write them.
 */
final class EInvoiceBuilder
{
    private const PAYMENT_MEANS = [
        'cash'          => '10',
        'check'         => '20',
        'bank_transfer' => '30',
        'upi'           => '30',
        'wallet'        => '30',
        'debit'         => '48',
        'credit'        => '48',
    ];

    private BaseConnection $db;
    private EInvoiceSettings $settings;
    private EInvoiceParties $parties;

    public function __construct(?BaseConnection $db = null, ?EInvoiceSettings $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->settings = $settings ?? new EInvoiceSettings($this->db);
        $this->parties  = new EInvoiceParties($this->db, $this->settings);
    }

    /**
     * Which subtype an invoice would be issued as, for the screen that offers the choice.
     */
    public function defaultSubtype(int $saleId): EInvoiceSubtype
    {
        $row = $this->db->table('sales')->select('customer_id')->where('sale_id', $saleId)->get()->getRow();

        return $this->parties->defaultSubtype($row === null || $row->customer_id === null ? null : (int) $row->customer_id);
    }

    /**
     * @throws EInvoiceException
     */
    public function fromSale(int $saleId, ?EInvoiceSubtype $subtype = null, string $reason = '', ?OriginalReference $original = null): EInvoiceDocument
    {
        $sale = (new SaleLoader($this->db))->load($saleId);

        if ($sale === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The sale does not exist.', ['number' => (string) $saleId]);
        }

        if ($sale->status !== COMPLETED || ! in_array($sale->saleType, [SALE_TYPE_INVOICE, SALE_TYPE_RETURN], true)) {
            throw new EInvoiceException(EInvoiceError::SourceNotIssuable, 'Only a completed invoice or return becomes a document.', ['number' => (string) $saleId]);
        }

        if ($sale->currency !== null) {
            throw new EInvoiceException(EInvoiceError::ForeignCurrency, 'A sale in a foreign currency is not written to the document.', ['number' => (string) $saleId]);
        }

        if ($sale->charges !== null) {
            throw new EInvoiceException(EInvoiceError::HeaderChargesUnsupported, 'A sale with shipping or an adjustment in its header is not written to the document.', ['number' => (string) $saleId]);
        }

        $kind = $sale->isReturn() ? EInvoiceKind::CreditNote : EInvoiceKind::Invoice;
        $note = $this->noteFacts($kind, $reason, $original);
        $row  = $this->db->table('sales')->select('sale_time, invoice_date')->where('sale_id', $saleId)->get()->getRow();

        $subtype = $original?->subtype ?? $subtype ?? $this->parties->defaultSubtype($sale->customerId);
        $built   = $this->lines($sale);
        $rules   = $this->assemble($built);

        $this->tieToTheBooks($sale, $rules);

        $time = (int) strtotime((string) $row->sale_time);

        return new EInvoiceDocument(
            kind: $kind,
            subtype: $subtype,
            number: $this->numberOf($sale),
            issueDate: $row->invoice_date === null ? date('Y-m-d', $time) : (string) $row->invoice_date,
            issueTime: date('H:i:s', $time),
            supplyDate: $row->invoice_date === null ? date('Y-m-d', $time) : (string) $row->invoice_date,
            seller: $this->parties->seller(),
            buyer: $this->parties->buyer($sale->customerId, $subtype),
            lines: array_map(static fn (BuiltLine $built): EInvoiceLine => $built->line, $built),
            taxSubtotals: $rules['subtotals'],
            lineExtension: $rules['lineExtension'],
            taxExclusive: $rules['lineExtension'],
            taxAmount: $rules['tax'],
            taxInclusive: $rules['total'],
            paymentMeansCodes: $this->paymentMeans($sale),
            reason: $note['reason'],
            billingReference: $note['reference'],
        );
    }

    /**
     * @throws EInvoiceException
     */
    public function fromDebitNote(int $debitNoteId, string $reason, OriginalReference $original): EInvoiceDocument
    {
        $row = $this->db->table('sales_debit_notes')->where('debit_note_id', $debitNoteId)->get()->getRow();

        if ($row === null) {
            throw new EInvoiceException(EInvoiceError::SourceNotFound, 'The debit note does not exist.', ['number' => (string) $debitNoteId]);
        }

        if ($row->status !== 'posted') {
            throw new EInvoiceException(EInvoiceError::SourceNotIssuable, 'A debit note that was taken back is not a document.', ['number' => (string) $debitNoteId]);
        }

        $note   = $this->noteFacts(EInvoiceKind::DebitNote, $reason, $original);
        $number = (new DocumentNumbering($this->db))->label(DocumentType::SalesDebitNote, 'sales_debit_notes', $debitNoteId);
        $amount = SaleAmounts::round((string) $row->amount, 2);
        $name   = trim((string) $row->note) !== '' ? (string) $row->note : $number;
        $exact  = bcadd((string) $row->tax_amount, '0', SaleAmounts::SCALE);
        $built  = [new BuiltLine($this->lineOf(1, $name, '1', $amount, '0.00', $amount, $this->categoryOf((string) $row->tax_percent, $name), SaleAmounts::round($exact, 2)), $exact)];
        $rules  = $this->assemble($built);

        if (bccomp($rules['total'], (string) $row->total, 2) !== 0) {
            throw new EInvoiceException(EInvoiceError::TotalsDoNotTie, 'The document does not come to the total of the debit note.', ['rule' => 'total', 'expected' => bcadd((string) $row->total, '0', 2), 'actual' => $rules['total']]);
        }

        $stamp = (int) strtotime((string) $row->created_at);

        return new EInvoiceDocument(
            kind: EInvoiceKind::DebitNote,
            subtype: $original->subtype,
            number: $number,
            issueDate: (string) $row->note_date,
            issueTime: date('H:i:s', $stamp),
            supplyDate: (string) $row->note_date,
            seller: $this->parties->seller(),
            buyer: $this->parties->buyer((int) $row->customer_id, $original->subtype),
            lines: [$built[0]->line],
            taxSubtotals: $rules['subtotals'],
            lineExtension: $rules['lineExtension'],
            taxExclusive: $rules['lineExtension'],
            taxAmount: $rules['tax'],
            taxInclusive: $rules['total'],
            reason: $note['reason'],
            billingReference: $note['reference'],
        );
    }

    /**
     * @return array{reason: string, reference: ?string}
     *
     * @throws EInvoiceException
     */
    private function noteFacts(EInvoiceKind $kind, string $reason, ?OriginalReference $original): array
    {
        if (! $kind->isNote()) {
            return ['reason' => '', 'reference' => null];
        }

        if ($original === null) {
            throw new EInvoiceException(EInvoiceError::OriginalNotIssued, 'A note refers to a document that has been issued.');
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw new EInvoiceException(EInvoiceError::ReasonRequired, 'A credit note and a debit note give the reason they are issued.');
        }

        if (! EInvoiceRules::within($reason, EInvoiceRules::NOTE_MAX)) {
            throw new EInvoiceException(EInvoiceError::ReasonTooLong, 'The reason is too long.', ['expected' => EInvoiceRules::NOTE_MAX]);
        }

        return ['reason' => $reason, 'reference' => $original->number];
    }

    /**
     * @return list<BuiltLine>
     *
     * @throws EInvoiceException
     */
    private function lines(SaleDocument $sale): array
    {
        $names    = $this->itemNames($sale);
        $taxRows  = $this->taxRows($sale->saleId);
        $soldAs   = $this->soldUnder($sale->saleId);
        $decimals = totals_decimals();
        $lines    = [];
        $number   = 0;

        foreach ($sale->lines as $line) {
            if (bccomp($line->quantity, '0', 3) === 0) {
                continue;
            }

            $number++;

            $lines[] = $this->saleLine($number, $line, $names[$line->itemId] ?? '', $taxRows[$line->itemId . ':' . $line->line] ?? [], $decimals, $soldAs[$line->itemId . ':' . $line->line] ?? null);
        }

        if ($lines === []) {
            throw new EInvoiceException(EInvoiceError::NothingToIssue, 'The sale has no line to write.', ['number' => (string) $sale->saleId]);
        }

        return $lines;
    }

    /**
     * @param list<object> $taxes
     *
     * @throws EInvoiceException
     */
    private function saleLine(int $number, SaleLine $line, string $name, array $taxes, int $decimals, ?LineVat $vat = null): BuiltLine
    {
        $quantity = SaleAmounts::absolute($line->quantity);
        $discount = SaleAmounts::lineDiscount($line, $decimals);
        $share    = SaleAmounts::absolute($line->invoiceDiscountShare);
        $included = SaleAmounts::absolute($line->includedTax);
        $goods    = SaleAmounts::lineGoods($line, $decimals, false);

        if (bccomp($goods, '0', 8) < 0) {
            throw new EInvoiceException(EInvoiceError::RuleBroken, 'The line comes to less than nothing.', ['rule' => 'BR-24', 'line' => $number]);
        }

        $net = SaleAmounts::round($goods, 2);

        if (bccomp($included, '0', 8) === 0) {
            $price     = bcadd($line->unitPrice, '0', 4);
            $allowance = SaleAmounts::round(bcadd($discount, $share, 8), 2);
        } else {
            $price     = SaleAmounts::round(bcdiv($goods, $quantity, 8), 4);
            $allowance = '0.00';
        }

        $lineTax = bcadd($included, SaleAmounts::absolute($line->addedTax), SaleAmounts::SCALE);

        if (bccomp(bcsub(SaleAmounts::round(bcmul($quantity, $price, 8), 2), $allowance, 2), $net, 2) !== 0) {
            throw new EInvoiceException(EInvoiceError::RuleBroken, 'The line does not come to its quantity times its price less its discount.', ['rule' => 'BR-KSA-EN16931-11', 'line' => $number]);
        }

        $rows = array_values(array_filter($taxes, static fn (object $row): bool => bccomp((string) $row->percent, '0', 4) > 0));

        if (count($rows) > 1) {
            throw new EInvoiceException(EInvoiceError::LineTaxAmbiguous, 'A line carries more than one tax, and the standard has one VAT rate for a line.', ['item' => $name]);
        }

        $category = $vat === null ? $this->categoryOf($rows === [] ? '0' : (string) $rows[0]->percent, $name) : $this->categoryOfClass($vat, $name);

        return new BuiltLine($this->lineOf($number, $name, $quantity, $price, $allowance, $net, $category, SaleAmounts::round($lineTax, 2)), $lineTax, $vat?->exemptionCode, $vat?->exemptionText);
    }

    /**
     * @param array{0: string, 1: string} $category
     */
    private function lineOf(int $number, string $name, string $quantity, string $price, string $allowance, string $net, array $category, string $tax): EInvoiceLine
    {
        [$category, $rate] = $category;

        return new EInvoiceLine(
            id: $number,
            name: $name,
            quantity: $this->quantity($quantity),
            unitCode: 'PCE',
            priceNet: $price,
            allowance: $allowance,
            netAmount: $net,
            vatCategory: $category,
            vatRate: $rate,
            vatAmount: $tax,
            amountWithVat: bcadd($net, $tax, 2),
        );
    }

    /**
     * The VAT category and rate of a percentage: standard rate when there is one, and the classification of
     * the settings when the line carries no tax.
     *
     * @return array{0: string, 1: string}
     *
     * @throws EInvoiceException
     */
    private function categoryOf(string $percent, string $name): array
    {
        if (bccomp($percent, '0', 4) <= 0) {
            return $this->exemptCategory();
        }

        $rate = bcadd($percent, '0', 2);

        if (bccomp($rate, $percent, 4) !== 0) {
            throw new EInvoiceException(EInvoiceError::RateNotRepresentable, 'A VAT rate has two decimals in the standard.', ['item' => $name, 'actual' => $percent]);
        }

        return ['S', $rate];
    }

    /**
     * The category and rate of a line sold under a VAT class, from what the line kept when it was sold.
     *
     * @return array{0: string, 1: string}
     *
     * @throws EInvoiceException
     */
    private function categoryOfClass(LineVat $vat, string $name): array
    {
        if ($vat->category !== 'S') {
            return [$vat->category, '0.00'];
        }

        $rate = bcadd($vat->percent, '0', 2);

        if (bccomp($rate, $vat->percent, 4) !== 0) {
            throw new EInvoiceException(EInvoiceError::RateNotRepresentable, 'A VAT rate has two decimals in the standard.', ['item' => $name, 'actual' => $vat->percent]);
        }

        return ['S', $rate];
    }

    /**
     * What the lines of a sale were sold under, by item and line; nothing for a sale made before the classes.
     *
     * @return array<string, LineVat>
     */
    private function soldUnder(int $saleId): array
    {
        return $this->db->tableExists($this->db->prefixTable('sales_items_vat'), false) ? (new VatClassification($this->db))->linesOfSale($saleId) : [];
    }

    /**
     * @return array{0: string, 1: string}
     *
     * @throws EInvoiceException
     */
    private function exemptCategory(): array
    {
        $values = $this->settings->all();

        if ($values['exempt_category'] === '' || $values['exempt_reason_code'] === '') {
            throw new EInvoiceException(EInvoiceError::ExemptionNotConfigured, 'A line without tax needs the category and the reason code of the settings.');
        }

        return [$values['exempt_category'], '0.00'];
    }

    /**
     * Sums the lines the way the standard says and holds the sums to its rules.
     *
     * @param list<BuiltLine> $lines
     *
     * @return array{subtotals: list<EInvoiceTaxSubtotal>, lineExtension: string, tax: string, total: string}
     *
     * @throws EInvoiceException
     */
    private function assemble(array $lines): array
    {
        $buckets = [];

        foreach ($lines as $built) {
            $key = $built->line->vatCategory . '|' . $built->line->vatRate . '|' . ($built->exemptionCode ?? '');

            $buckets[$key]['taxable'] = bcadd($buckets[$key]['taxable'] ?? '0', $built->line->netAmount, 2);
            $buckets[$key]['tax']     = bcadd($buckets[$key]['tax'] ?? '0', $built->exactTax, SaleAmounts::SCALE);
            $buckets[$key]['text']    = $built->exemptionText;
        }

        $values    = $this->settings->all();
        $extension = '0';
        $tax       = '0';
        $subtotals = [];

        foreach ($buckets as $key => $bucket) {
            [$category, $rate, $code] = explode('|', $key);

            $taxAmount = SaleAmounts::round($bucket['tax'], 2);

            if (bccomp(SaleAmounts::round(bcdiv(bcmul($bucket['taxable'], $rate, 8), '100', 8), 2), $taxAmount, 2) !== 0) {
                throw new EInvoiceException(EInvoiceError::RuleBroken, 'The tax of a category is not its taxable amount times its rate.', ['rule' => 'BR-CO-17', 'expected' => SaleAmounts::round(bcdiv(bcmul($bucket['taxable'], $rate, 8), '100', 8), 2), 'actual' => $taxAmount]);
            }

            $exempt      = $category !== 'S';
            $ownReason   = $code !== '';
            $subtotals[] = new EInvoiceTaxSubtotal($category, $rate, $bucket['taxable'], $taxAmount, $exempt ? ($ownReason ? $code : $values['exempt_reason_code']) : '', $exempt ? ($ownReason ? (string) $bucket['text'] : $values['exempt_reason_text']) : '');
            $extension   = bcadd($extension, $bucket['taxable'], 2);
            $tax         = bcadd($tax, $taxAmount, 2);
        }

        return ['subtotals' => $subtotals, 'lineExtension' => $extension, 'tax' => $tax, 'total' => bcadd($extension, $tax, 2)];
    }

    /**
     * @param array{subtotals: list<EInvoiceTaxSubtotal>, lineExtension: string, tax: string, total: string} $rules
     *
     * @throws EInvoiceException
     */
    private function tieToTheBooks(SaleDocument $sale, array $rules): void
    {
        $figures = SaleAmounts::figures($sale, totals_decimals());

        if (bccomp($rules['total'], $figures['total'], 2) !== 0) {
            throw new EInvoiceException(EInvoiceError::TotalsDoNotTie, 'The document does not come to the total of the sale.', ['rule' => 'total', 'expected' => bcadd($figures['total'], '0', 2), 'actual' => $rules['total']]);
        }

        if (bccomp($rules['tax'], SaleAmounts::round($figures['tax'], 2), 2) !== 0) {
            throw new EInvoiceException(EInvoiceError::TotalsDoNotTie, 'The document does not hold the tax of the sale.', ['rule' => 'tax', 'expected' => SaleAmounts::round($figures['tax'], 2), 'actual' => $rules['tax']]);
        }
    }

    private function quantity(string $quantity): string
    {
        $fixed              = bcadd($quantity, '0', 6);
        [$whole, $fraction] = explode('.', $fixed) + [1 => ''];

        return $whole . '.' . str_pad(rtrim($fraction, '0'), 2, '0');
    }

    /**
     * @return list<string>
     */
    private function paymentMeans(SaleDocument $sale): array
    {
        $codes = [];

        foreach ($sale->payments as $payment) {
            if (! $payment->status->posts() || $payment->cashAdjustment) {
                continue;
            }

            $type = PaymentType::fromLabel($payment->type);
            $code = $type === null ? null : (self::PAYMENT_MEANS[$type->value] ?? null);

            if ($code !== null) {
                $codes[$code] = $code;
            }
        }

        return array_values($codes);
    }

    private function numberOf(SaleDocument $sale): string
    {
        $type  = $sale->isReturn() ? ($sale->refundInvoice ? DocumentType::RefundInvoice : DocumentType::CreditNote) : DocumentType::SalesInvoice;
        $label = (new DocumentNumbering($this->db))->label($type, 'sales', $sale->saleId);

        return $label !== '' ? $label : (string) $sale->saleId;
    }

    /**
     * @return array<int, string>
     */
    private function itemNames(SaleDocument $sale): array
    {
        $ids = array_values(array_unique(array_map(static fn (SaleLine $line): int => $line->itemId, $sale->lines)));

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach ($this->db->table('items')->select('item_id, name')->whereIn('item_id', $ids)->get()->getResult() as $row) {
            $names[(int) $row->item_id] = trim((string) $row->name);
        }

        return $names;
    }

    /**
     * @return array<string, list<object>>
     */
    private function taxRows(int $saleId): array
    {
        $rows = [];

        foreach ($this->db->table('sales_items_taxes')->select('item_id, line, name, percent, tax_type, item_tax_amount')->where('sale_id', $saleId)->get()->getResult() as $row) {
            $rows[$row->item_id . ':' . $row->line][] = $row;
        }

        return $rows;
    }
}
