<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reads a saved purchase in the shape posting needs. What is posted is what is stored, so the
 * journals follow the purchase report whatever path saved the purchase.
 */
final class ReceivingLoader
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function load(int $receivingId, bool $withOrigin = true): ?ReceivingDocument
    {
        $receiving = $this->db->table('receivings')
            ->select('receiving_id, receiving_time, receiving_type, supplier_id, branch_id, payment_type, return_of_receiving_id')
            ->where('receiving_id', $receivingId)
            ->get()
            ->getRow();

        if ($receiving === null) {
            return null;
        }

        $origin = $withOrigin && $receiving->return_of_receiving_id !== null ? $this->load((int) $receiving->return_of_receiving_id, false) : null;

        return new ReceivingDocument(
            receivingId: (int) $receiving->receiving_id,
            date: substr((string) $receiving->receiving_time, 0, 10),
            type: ReceivingType::tryFrom((string) $receiving->receiving_type) ?? ReceivingType::Receive,
            supplierId: $receiving->supplier_id === null ? null : (int) $receiving->supplier_id,
            branchId: (int) $receiving->branch_id,
            paymentType: $receiving->payment_type === null || trim((string) $receiving->payment_type) === '' ? null : (string) $receiving->payment_type,
            returnOfReceivingId: $receiving->return_of_receiving_id === null ? null : (int) $receiving->return_of_receiving_id,
            lines: $this->lines($receivingId),
            origin: $origin,
        );
    }

    /**
     * @return list<ReceivingLine>
     */
    private function lines(int $receivingId): array
    {
        $lines = [];

        foreach ($this->db->table('receivings_items l')
            ->select('l.item_id, l.line, l.quantity_purchased, l.receiving_quantity, l.item_unit_price, l.discount, l.discount_type, l.tax_percent, l.item_location, it.stock_type, it.cost_price')
            ->join('items it', 'it.item_id = l.item_id')
            ->where('l.receiving_id', $receivingId)
            ->orderBy('l.line', 'ASC')
            ->get()
            ->getResult() as $row) {
            $lines[] = new ReceivingLine(
                itemId: (int) $row->item_id,
                line: (int) $row->line,
                quantity: (string) $row->quantity_purchased,
                unitsPerPack: (string) $row->receiving_quantity,
                unitPrice: (string) $row->item_unit_price,
                discount: (string) $row->discount,
                discountType: (int) $row->discount_type,
                taxPercent: (string) $row->tax_percent,
                locationId: (int) $row->item_location,
                hasStock: (int) $row->stock_type === HAS_STOCK,
                itemCost: (string) $row->cost_price,
            );
        }

        return $lines;
    }
}
