<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\PurchaseCycle\PurchaseCycleSettings;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocuments;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;

/**
 * Whether any document of the purchase cycle is in a state the code cannot leave. The stored statuses are four (open, sent, approved
 * and cancelled) and the code moves a document out of the first three by a label, a conversion, a receiving or a cancellation; a
 * document whose stored status is none of the four cannot be moved by anything, which an old database server that
 * ignores the CHECK of the table lets in. Documents that still wait to be finished (open, sent, approved, or an order received in
 * part) and that nobody can change while the cycle is switched off, and orders received beyond what they held, are listed for
 * review. A document that was converted, received in full or cancelled is finished. It reads and changes nothing.
 */
final class PurchaseCycleChecks extends CheckGroup
{
    private const LISTED = 50;

    public function run(): array
    {
        $tables = [];

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $tables[] = $kind->table();
        }

        return [
            $this->check('purchase_cycle', 'WP39-PURCHASE-CYCLE', 'No purchase cycle document sits in a status the code cannot leave', [...$tables, 'purchase_order_lines', 'purchase_order_receivings', 'purchase_order_receiving_lines'], fn (): CheckResult => $this->cycle()),
        ];
    }

    private function cycle(): CheckResult
    {
        $stuck     = [];
        $waiting   = 0;
        $documents = 0;
        $enabled   = (new PurchaseCycleSettings($this->db))->isEnabled();
        $closed    = array_map(static fn (PurchaseDocumentStatus $status): string => $status->value, array_filter(PurchaseDocumentStatus::cases(), static fn (PurchaseDocumentStatus $status): bool => $status->isStored()));

        foreach (PurchaseDocumentKind::cases() as $kind) {
            $documents += (int) $this->db->table($kind->table())->countAllResults();

            foreach ($this->db->table($kind->table())->select($kind->idColumn() . ' AS document_id, status')->whereNotIn('status', $closed)->limit(self::LISTED)->get()->getResult() as $row) {
                $stuck[] = sprintf('%s #%d has the status "%s", which nothing in the code moves it out of.', $kind->value, $row->document_id, $row->status);
            }

            $waiting += (new PurchaseDocuments($kind, $this->db))->waitingCount();
        }

        $over   = $this->receivedBeyondTheOrder();
        $others = $this->receivingsOfAnotherSupplier();
        $data   = ['documents' => $documents, 'stuck' => count($stuck), 'waiting_while_off' => $enabled ? 0 : $waiting, 'received_beyond' => count($over), 'receivings_of_another_supplier' => $others];

        if ($stuck !== []) {
            return CheckResult::fail(count($stuck) . ' document(s) of the purchase cycle hold a status that the code cannot leave.', $stuck, $data);
        }

        $review = [];

        if (! $enabled && $waiting > 0) {
            $review[] = $waiting . ' document(s) of the purchase cycle still wait to be finished and cannot be changed, converted, received or cancelled while the cycle is switched off: switch it on in the purchase cycle settings to finish them.';
        }

        foreach ($over as $line) {
            $review[] = $line;
        }

        if ($others > 0) {
            $review[] = $others . ' receiving(s) made from a purchase order belong to another supplier than the order.';
        }

        if ($review !== []) {
            return CheckResult::review(count($review) . ' finding(s) in the purchase cycle wait for somebody to look at them.', $review, $data);
        }

        return CheckResult::pass($documents . ' document(s) of the purchase cycle, none in a status the code cannot leave.', [], $data);
    }

    /**
     * @return list<string>
     */
    private function receivedBeyondTheOrder(): array
    {
        $rows = $this->db->table('purchase_order_lines l')
            ->select('l.order_id, l.line_no, l.quantity, SUM(t.quantity) AS received', false)
            ->join('purchase_order_receiving_lines t', 't.order_line_id = l.line_id')
            ->groupBy('l.line_id')
            ->having('SUM(t.quantity) > l.quantity', null, false)
            ->limit(self::LISTED)
            ->get()
            ->getResult();

        return array_map(static fn (object $row): string => sprintf('Purchase order #%d line %d was received for %s of the %s ordered.', $row->order_id, $row->line_no, bcadd((string) $row->received, '0', 3), bcadd((string) $row->quantity, '0', 3)), $rows);
    }

    private function receivingsOfAnotherSupplier(): int
    {
        return (int) $this->db->table('purchase_order_receivings k')
            ->join('purchase_orders o', 'o.order_id = k.order_id')
            ->join('receivings r', 'r.receiving_id = k.receiving_id')
            ->where('r.supplier_id IS NULL OR r.supplier_id <> o.supplier_id', null, false)
            ->countAllResults();
    }
}
