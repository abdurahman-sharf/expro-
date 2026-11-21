<?php

namespace App\Libraries\Accounting;

use App\Libraries\ShippingTaxOption;
use App\Libraries\Tax\PurchaseVat;
use App\Libraries\Tax\VatSnapshot;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the lines of sales and of purchases say about their tax, by the same rules posting applies
 * to them: the part of a document that carries tax is the lines that have tax, after their own
 * discount and the share they took of the discount on the whole document and without the tax that
 * was inside the prices; the tax on the shipping of a sale is part of its document, at its own rate.
 * Documents are read many at a time, because a report counts thousands.
 */
final class TaxDocuments
{
    public const CHUNK       = 500;
    private const WORK_SCALE = 8;
    private const SCALE      = 4;

    private BaseConnection $db;
    private int $decimals;

    public function __construct(?BaseConnection $db = null, ?int $decimals = null, private readonly int $chunk = self::CHUNK)
    {
        $this->db       = $db ?? Database::connect();
        $this->decimals = $decimals ?? totals_decimals();
    }

    /**
     * @param list<int> $saleIds
     *
     * @return array<int, TaxDocument> By sale. A sale that is not there, and a quote or a work order, has none.
     */
    public function sales(array $saleIds): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($saleIds)), $this->chunk) as $chunk) {
            $sections = $this->saleSections($chunk);

            if ($sections === []) {
                continue;
            }

            $taxes = $this->saleTaxes(array_keys($sections));
            $kept  = (new VatSnapshot($this->db))->totalsOfSales(array_keys($sections));
            $built = [];

            foreach ($this->db->table('sales_items')
                ->select('sale_id, item_id, line, quantity_purchased, item_unit_price, discount, discount_type, invoice_discount_share')
                ->whereIn('sale_id', array_keys($sections))
                ->orderBy('sale_id', 'ASC')
                ->orderBy('line', 'ASC')
                ->get()
                ->getResult() as $row) {
                $saleId = (int) $row->sale_id;
                $line   = $this->saleLine($row, $taxes[$saleId][$row->item_id . ':' . $row->line] ?? []);

                if (isset($kept[$saleId])) {
                    $this->add($built[$saleId], '0', '0', [], bccomp($line['share'], '0', self::WORK_SCALE) > 0);

                    continue;
                }

                $this->add($built[$saleId], $line['net'], $line['tax'], $line['rates'], bccomp($line['share'], '0', self::WORK_SCALE) > 0);
            }

            foreach ($kept as $saleId => $categories) {
                foreach ($categories as $category) {
                    $vat = SaleAmounts::absolute($category->vat);

                    $this->add($built[$saleId], SaleAmounts::absolute($category->taxable), $vat, [self::rateKey($category->percent) => $vat], false);
                }
            }

            foreach ($this->shippingTaxes(array_keys($sections)) as $saleId => $shipping) {
                $this->add($built[$saleId], $shipping['net'], $shipping['tax'], [$shipping['rate'] => $shipping['tax']], false);
            }

            foreach ($built as $saleId => $totals) {
                $found[$saleId] = $this->document($sections[$saleId], $totals);
            }
        }

        return $found;
    }

    /**
     * @param list<int> $debitNoteIds
     *
     * @return array<int, TaxDocument> By debit note, in the section of the sales invoices it raises what is owed with. A note that is not there has none.
     */
    public function debitNotes(array $debitNoteIds): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($debitNoteIds)), $this->chunk) as $chunk) {
            foreach ($this->db->table('sales_debit_notes')
                ->select('debit_note_id, amount, tax_percent, tax_amount')
                ->whereIn('debit_note_id', $chunk)
                ->get()
                ->getResult() as $row) {
                $tax   = bcadd((string) $row->tax_amount, '0', self::SCALE);
                $base  = bcadd((string) $row->amount, '0', self::SCALE);
                $rates = bccomp($tax, '0', self::SCALE) > 0 ? [self::rateKey((string) $row->tax_percent) => ['base' => $base, 'tax' => $tax]] : [];

                $found[(int) $row->debit_note_id] = new TaxDocument(TaxSection::SalesInvoices, $base, $tax, $rates, false);
            }
        }

        return $found;
    }

    /**
     * @param list<int> $receivingIds
     *
     * @return array<int, TaxDocument> By purchase. A purchase that is not there, and a transfer of stock, has none.
     */
    public function purchases(array $receivingIds): array
    {
        $found = [];

        foreach (array_chunk(array_values(array_unique($receivingIds)), $this->chunk) as $chunk) {
            $sections = $this->purchaseSections($chunk);

            if ($sections === []) {
                continue;
            }

            $built  = [];
            $marked = (new PurchaseVat($this->db))->markedAmong(array_keys($sections));
            $once   = [];

            foreach ($this->db->table('receivings_items')
                ->select('receiving_id, item_id, line, quantity_purchased, receiving_quantity, item_unit_price, discount, discount_type, tax_percent, invoice_discount_share')
                ->whereIn('receiving_id', array_keys($sections))
                ->orderBy('receiving_id', 'ASC')
                ->orderBy('line', 'ASC')
                ->get()
                ->getResult() as $row) {
                $line = new ReceivingLine(
                    itemId: (int) $row->item_id,
                    line: (int) $row->line,
                    quantity: (string) $row->quantity_purchased,
                    unitsPerPack: (string) $row->receiving_quantity,
                    unitPrice: (string) $row->item_unit_price,
                    discount: (string) $row->discount,
                    discountType: (int) $row->discount_type,
                    taxPercent: (string) $row->tax_percent,
                    locationId: 0,
                    hasStock: false,
                    itemCost: '0',
                    invoiceDiscount: (string) ($row->invoice_discount_share ?? '0'),
                    vatWorkedOnce: isset($marked[(int) $row->receiving_id]),
                );

                if ($line->vatWorkedOnce) {
                    $once[(int) $row->receiving_id][] = $line;
                    $this->add($built[(int) $row->receiving_id], '0', '0', [], bccomp(SaleAmounts::absolute($line->invoiceDiscount), '0', self::SCALE) > 0);

                    continue;
                }

                $tax   = ReceivingAmounts::tax($line);
                $rates = bccomp($tax, '0', self::SCALE) > 0 ? [self::rateKey((string) $row->tax_percent) => $tax] : [];

                $this->add($built[(int) $row->receiving_id], ReceivingAmounts::net($line), $tax, $rates, bccomp(SaleAmounts::absolute($line->invoiceDiscount), '0', self::SCALE) > 0);
            }

            foreach ($once as $receivingId => $lines) {
                foreach (ReceivingAmounts::byRate($lines) as $rate => $figures) {
                    $this->add($built[$receivingId], $figures['net'], $figures['tax'], [self::rateKey((string) $rate) => $figures['tax']], false);
                }
            }

            foreach ($built as $receivingId => $totals) {
                $found[$receivingId] = $this->document($sections[$receivingId], $totals);
            }
        }

        return $found;
    }

    /**
     * @param list<int> $saleIds
     *
     * @return array<int, TaxSection>
     */
    private function saleSections(array $saleIds): array
    {
        $sections = [];

        foreach ($this->db->table('sales')->select('sale_id, sale_type')->whereIn('sale_id', $saleIds)->whereIn('sale_type', [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN])->get()->getResult() as $row) {
            $sections[(int) $row->sale_id] = (int) $row->sale_type === SALE_TYPE_RETURN ? TaxSection::SalesReturns : TaxSection::SalesInvoices;
        }

        return $sections;
    }

    /**
     * The tax on the shipping of the sales that charge it: what the shipping comes to without the tax, the tax
     * and the rate it was taxed at. The journal of the sale puts it in the output tax with the tax of the lines.
     *
     * @param list<int> $saleIds
     *
     * @return array<int, array{net: numeric-string, tax: numeric-string, rate: string}>
     */
    private function shippingTaxes(array $saleIds): array
    {
        $found = [];

        foreach ($this->db->table('sales')
            ->select('sale_id, shipping_amount, shipping_tax_option, shipping_tax_percent, shipping_tax_amount')
            ->whereIn('sale_id', $saleIds)
            ->where('shipping_tax_amount >', 0)
            ->get()
            ->getResult() as $row) {
            $tax    = bcadd((string) $row->shipping_tax_amount, '0', self::WORK_SCALE);
            $amount = bcadd((string) $row->shipping_amount, '0', self::WORK_SCALE);

            $found[(int) $row->sale_id] = [
                'net'  => ShippingTaxOption::fromStored($row->shipping_tax_option) === ShippingTaxOption::Included ? bcsub($amount, $tax, self::WORK_SCALE) : $amount,
                'tax'  => $tax,
                'rate' => self::rateKey((string) $row->shipping_tax_percent),
            ];
        }

        return $found;
    }

    /**
     * @param list<int> $receivingIds
     *
     * @return array<int, TaxSection>
     */
    private function purchaseSections(array $receivingIds): array
    {
        $sections = [];

        foreach ($this->db->table('receivings')->select('receiving_id, receiving_type')->whereIn('receiving_id', $receivingIds)->whereIn('receiving_type', [ReceivingType::Receive->value, ReceivingType::Return->value])->get()->getResult() as $row) {
            $sections[(int) $row->receiving_id] = $row->receiving_type === ReceivingType::Return->value ? TaxSection::PurchaseReturns : TaxSection::Purchases;
        }

        return $sections;
    }

    /**
     * @param list<int> $saleIds
     *
     * @return array<int, array<string, list<object>>> The tax rows of each line, by sale and then by "item:line".
     */
    private function saleTaxes(array $saleIds): array
    {
        $taxes = [];

        foreach ($this->db->table('sales_items_taxes')
            ->select('sale_id, item_id, line, percent, tax_type, item_tax_amount')
            ->whereIn('sale_id', $saleIds)
            ->get()
            ->getResult() as $row) {
            $taxes[(int) $row->sale_id][$row->item_id . ':' . $row->line][] = $row;
        }

        return $taxes;
    }

    /**
     * @param list<object> $taxRows
     *
     * @return array{net: numeric-string, tax: numeric-string, rates: array<string, numeric-string>, share: numeric-string}
     */
    private function saleLine(object $row, array $taxRows): array
    {
        $included = '0';
        $added    = '0';
        $rates    = [];

        foreach ($taxRows as $taxRow) {
            $amount = SaleAmounts::absolute((string) $taxRow->item_tax_amount);

            if ((int) $taxRow->tax_type === 0) {
                $included = bcadd($included, $amount, self::WORK_SCALE);
            } else {
                $added = bcadd($added, $amount, self::WORK_SCALE);
            }

            $key         = self::rateKey((string) $taxRow->percent);
            $rates[$key] = bcadd($rates[$key] ?? '0', $amount, self::WORK_SCALE);
        }

        $line = new SaleLine(
            itemId: (int) $row->item_id,
            line: (int) $row->line,
            quantity: (string) $row->quantity_purchased,
            unitPrice: (string) $row->item_unit_price,
            discount: (string) $row->discount,
            discountType: (int) $row->discount_type,
            costPrice: '0',
            locationId: 0,
            hasStock: false,
            includedTax: $included,
            addedTax: $added,
            invoiceDiscountShare: (string) ($row->invoice_discount_share ?? '0'),
        );

        $share = SaleAmounts::absolute($line->invoiceDiscountShare);
        $net   = bcsub(bcsub(bcsub(SaleAmounts::lineGross($line), SaleAmounts::lineDiscount($line, $this->decimals), self::WORK_SCALE), $share, self::WORK_SCALE), $included, self::WORK_SCALE);
        $tax   = bcadd($included, $added, self::WORK_SCALE);

        return ['net' => $net, 'tax' => $tax, 'rates' => $rates, 'share' => $share];
    }

    /**
     * Adds a line to what is known of its document. A line without tax is not part of what the
     * report counts, but a share of the discount on the whole document marks the document.
     *
     * @param array{base: string, tax: string, rates: array<string, array{base: string, tax: string}>, discounted: bool}|null $totals
     * @param array<string, numeric-string>                                                                                   $rates  The tax of the line by rate.
     */
    private function add(?array &$totals, string $net, string $tax, array $rates, bool $discounted): void
    {
        $totals ??= ['base' => '0', 'tax' => '0', 'rates' => [], 'discounted' => false];

        $totals['discounted'] = $totals['discounted'] || $discounted;

        if (bccomp($tax, '0', self::WORK_SCALE) <= 0) {
            return;
        }

        $totals['base'] = bcadd($totals['base'], $net, self::WORK_SCALE);
        $totals['tax']  = bcadd($totals['tax'], $tax, self::WORK_SCALE);

        foreach ($rates as $key => $amount) {
            $rate                  = $totals['rates'][$key] ?? ['base' => '0', 'tax' => '0'];
            $totals['rates'][$key] = [
                'base' => bcadd($rate['base'], $net, self::WORK_SCALE),
                'tax'  => bcadd($rate['tax'], $amount, self::WORK_SCALE),
            ];
        }
    }

    /**
     * @param array{base: string, tax: string, rates: array<string, array{base: string, tax: string}>, discounted: bool} $totals
     */
    private function document(TaxSection $section, array $totals): TaxDocument
    {
        $rates = [];

        foreach ($totals['rates'] as $key => $rate) {
            $rates[$key] = ['base' => bcadd($rate['base'], '0', self::SCALE), 'tax' => bcadd($rate['tax'], '0', self::SCALE)];
        }

        return new TaxDocument($section, bcadd($totals['base'], '0', self::SCALE), bcadd($totals['tax'], '0', self::SCALE), $rates, $totals['discounted']);
    }

    private static function rateKey(string $percent): string
    {
        $rate = str_contains($percent, '.') ? rtrim(rtrim($percent, '0'), '.') : $percent;

        return $rate === '' ? '0' : $rate;
    }
}
