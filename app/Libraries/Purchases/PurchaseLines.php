<?php

namespace App\Libraries\Purchases;

use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLine;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The lines of saved purchases as the books count them, and what each purchase comes to with its
 * discounts and tax. Every list and report of purchases reads its lines here, so that there is one
 * meaning of what a purchase is worth.
 */
final class PurchaseLines
{
    private const CHUNK = 500;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array{lines: list<array{0: ReceivingLine, 1: object}>, purchase: list<ReceivingLine>}>
     */
    public function of(array $ids): array
    {
        $found = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $rows = $this->db->table('receivings_items ri')
                ->select('ri.receiving_id, ri.item_id, ri.line, ri.quantity_purchased, ri.receiving_quantity, ri.item_unit_price, ri.discount, ri.discount_type, ri.item_location, ri.tax_percent, ri.invoice_discount_share, i.name, i.item_number, i.category')
                ->join('items i', 'i.item_id = ri.item_id', 'left')
                ->whereIn('ri.receiving_id', $chunk)
                ->orderBy('ri.receiving_id', 'ASC')
                ->orderBy('ri.line', 'ASC')
                ->get()
                ->getResult();

            foreach ($rows as $row) {
                $line = new ReceivingLine(
                    itemId: (int) $row->item_id,
                    line: (int) $row->line,
                    quantity: sprintf('%.3F', (float) $row->quantity_purchased),
                    unitsPerPack: sprintf('%.3F', (float) $row->receiving_quantity),
                    unitPrice: sprintf('%.2F', (float) $row->item_unit_price),
                    discount: sprintf('%.2F', (float) $row->discount),
                    discountType: (int) $row->discount_type,
                    taxPercent: sprintf('%.3F', (float) $row->tax_percent),
                    locationId: (int) $row->item_location,
                    hasStock: true,
                    itemCost: '0',
                    invoiceDiscount: $row->invoice_discount_share === null ? '0' : sprintf('%.4F', (float) $row->invoice_discount_share),
                );

                $found[(int) $row->receiving_id]['purchase'][] = $line;
                $found[(int) $row->receiving_id]['lines'][]    = [$line, $row];
            }
        }

        return $found;
    }

    /**
     * @param list<int>       $ids
     * @param array<int, int> $decimals The decimals to keep a total in, by purchase: those of the currency of a foreign purchase, two for any other.
     *
     * @return array<int, array{net: numeric-string, tax: numeric-string, total: numeric-string}>
     */
    public function totalsOf(array $ids, array $decimals = []): array
    {
        $totals = [];
        $lines  = $this->of($ids);

        foreach ($ids as $id) {
            $totals[$id] = ReceivingAmounts::totals($lines[$id]['purchase'] ?? [], $decimals[$id] ?? 2);
        }

        return $totals;
    }
}
