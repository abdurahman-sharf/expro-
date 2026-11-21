<?php

namespace App\Libraries\Accounting;

use App\Libraries\Tax\PurchaseVat;
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
            ->select('receiving_id, receiving_time, invoice_date, receiving_type, supplier_id, branch_id, to_branch_id, payment_type, return_of_receiving_id, treasury_id, currency_code, currency_rate')
            ->where('receiving_id', $receivingId)
            ->get()
            ->getRow();

        if ($receiving === null) {
            return null;
        }

        $origin  = $withOrigin && $receiving->return_of_receiving_id !== null ? $this->load((int) $receiving->return_of_receiving_id, false) : null;
        $savedOn = substr((string) $receiving->receiving_time, 0, 10);

        return new ReceivingDocument(
            receivingId: (int) $receiving->receiving_id,
            date: $receiving->invoice_date === null ? $savedOn : (string) $receiving->invoice_date,
            type: ReceivingType::tryFrom((string) $receiving->receiving_type) ?? ReceivingType::Receive,
            supplierId: $receiving->supplier_id === null ? null : (int) $receiving->supplier_id,
            branchId: (int) $receiving->branch_id,
            paymentType: $receiving->payment_type === null || trim((string) $receiving->payment_type) === '' ? null : (string) $receiving->payment_type,
            returnOfReceivingId: $receiving->return_of_receiving_id === null ? null : (int) $receiving->return_of_receiving_id,
            lines: $this->lines($receivingId, (new PurchaseVat($this->db))->markedAmong([$receivingId]) !== []),
            origin: $origin,
            payments: $this->payments($receivingId),
            treasuryId: $receiving->treasury_id === null ? null : (int) $receiving->treasury_id,
            toBranchId: $receiving->to_branch_id === null ? null : (int) $receiving->to_branch_id,
            branchCodes: $this->branchCodes((int) $receiving->branch_id, $receiving->to_branch_id === null ? null : (int) $receiving->to_branch_id),
            savedOn: $savedOn,
            currency: (new Currencies($this->db))->stored($receiving->currency_code, $receiving->currency_rate),
        );
    }

    /**
     * @return array<int, string>
     */
    private function branchCodes(int $branchId, ?int $toBranchId): array
    {
        if ($toBranchId === null) {
            return [];
        }

        $codes = [];

        foreach ($this->db->table('branches')->select('branch_id, code')->whereIn('branch_id', [$branchId, $toBranchId])->get()->getResult() as $row) {
            $codes[(int) $row->branch_id] = (string) $row->code;
        }

        return $codes;
    }

    /**
     * @return list<SupplierPayment>
     */
    private function payments(int $receivingId): array
    {
        $payments = [];

        foreach ($this->db->table('receivings_payments')
            ->select('payment_id, payment_type, payment_amount, payment_time, payment_date, payment_status, treasury_id, treasury_branch_id, currency_rate')
            ->where('receiving_id', $receivingId)
            ->orderBy('payment_id', 'ASC')
            ->get()
            ->getResult() as $row) {
            $payments[] = new SupplierPayment(
                paymentId: (int) $row->payment_id,
                type: (string) $row->payment_type,
                amount: (string) $row->payment_amount,
                date: SupplierPaymentDate::of($row->payment_date === null ? null : (string) $row->payment_date, (string) $row->payment_time),
                status: PaymentStatus::fromStored($row->payment_status),
                treasuryId: $row->treasury_id === null ? null : (int) $row->treasury_id,
                treasuryBranchId: $row->treasury_branch_id === null ? null : (int) $row->treasury_branch_id,
                rate: $row->currency_rate === null ? null : bcadd((string) $row->currency_rate, '0', 8),
            );
        }

        return $payments;
    }

    /**
     * @return list<ReceivingLine>
     */
    private function lines(int $receivingId, bool $vatWorkedOnce): array
    {
        $lines = [];

        foreach ($this->db->table('receivings_items l')
            ->select('l.item_id, l.line, l.quantity_purchased, l.receiving_quantity, l.item_unit_price, l.discount, l.discount_type, l.tax_percent, l.invoice_discount_share, l.item_location, it.stock_type, it.cost_price, sl.branch_id AS location_branch_id')
            ->join('items it', 'it.item_id = l.item_id')
            ->join('stock_locations sl', 'sl.location_id = l.item_location', 'left')
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
                branchId: $row->location_branch_id === null ? null : (int) $row->location_branch_id,
                invoiceDiscount: $row->invoice_discount_share === null ? '0' : (string) $row->invoice_discount_share,
                vatWorkedOnce: $vatWorkedOnce,
            );
        }

        return $lines;
    }
}
