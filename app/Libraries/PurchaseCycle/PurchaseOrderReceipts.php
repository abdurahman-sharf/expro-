<?php

namespace App\Libraries\PurchaseCycle;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the receivings took from the lines of a purchase order. The receiving is the purchase invoice: it is
 * made in the receiving register, which the order fills in with what is left to receive, and the units each
 * saved receiving takes from each line are kept with the receiving, in the transaction that saves it and under
 * the lock of the order, so two receivings never take the same units and an order is never received beyond what
 * it holds unless the settings allow it. Whether an order is received in part or in full is read from the
 * receivings that stand, never stored, and a receiving that is deleted gives its units back. The order itself
 * posts nothing and moves no stock: the receiving does, as it always has.
 */
final class PurchaseOrderReceipts
{
    use RunsInTransaction;

    private BaseConnection $db;
    private BranchGuard $branches;
    private PurchaseCycleSettings $settings;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->settings = new PurchaseCycleSettings($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * The units each line of the order was received for by the receivings that stand, leaving out one receiving
     * when it is the one being saved again.
     *
     * @return array<int, string> By the id of the line.
     */
    public function receivedQuantities(int $orderId, ?int $exceptReceivingId = null): array
    {
        $builder = $this->db->table('purchase_order_receiving_lines t')
            ->select('t.order_line_id, SUM(t.quantity) AS taken')
            ->join('purchase_order_lines l', 'l.line_id = t.order_line_id')
            ->where('l.order_id', $orderId)
            ->groupBy('t.order_line_id');

        if ($exceptReceivingId !== null) {
            $builder->where('t.receiving_id !=', $exceptReceivingId);
        }

        $quantities = [];

        foreach ($builder->get()->getResult() as $row) {
            $quantities[(int) $row->order_line_id] = bcadd((string) $row->taken, '0', 3);
        }

        return $quantities;
    }

    public function everReceived(int $orderId): bool
    {
        return $this->db->table('purchase_order_receiving_lines t')->join('purchase_order_lines l', 'l.line_id = t.order_line_id')->where('l.order_id', $orderId)->countAllResults() > 0;
    }

    /**
     * The order a receiving was made from, or null; a database that does not have the table yet has none, so that
     * the receivings made before the migration are deleted and corrected as they always were.
     */
    public function orderOfReceiving(int $receivingId): ?int
    {
        if (! $this->db->tableExists('purchase_order_receivings', false)) {
            return null;
        }

        $row = $this->db->table('purchase_order_receivings')->select('order_id')->where('receiving_id', $receivingId)->get()->getRow();

        return $row === null ? null : (int) $row->order_id;
    }

    /**
     * The receivings made from the order that stand, oldest first.
     *
     * @return list<object>
     */
    public function receivingsOf(int $orderId): array
    {
        return $this->db->table('purchase_order_receivings k')
            ->select('k.receiving_id, r.receiving_time, r.reference, r.receiving_type, (SELECT COALESCE(SUM(t.quantity), 0) FROM ' . $this->db->prefixTable('purchase_order_receiving_lines') . ' t WHERE t.receiving_id = k.receiving_id) AS units', false)
            ->join('receivings r', 'r.receiving_id = k.receiving_id')
            ->where('k.order_id', $orderId)
            ->orderBy('k.receiving_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Takes from the lines of the order what a receiving of goods holds. It runs inside the transaction that saves the
     * receiving, under the lock of the order, and a refusal sends the whole receiving back. A new receiving is refused
     * while the purchase cycle is switched off; the correction of one that was made is not.
     *
     * @param list<array<string, mixed>> $items The lines of the receiving as the register saves them: item_id, quantity and receiving_quantity.
     *
     * @throws AccountingException|BranchException
     */
    public function recordReceiving(int $receivingId, int $orderId, array $items, int $supplierId, ?int $employeeId, int $branchId, string $action = 'received'): void
    {
        if ($action === 'received' && ! $this->settings->isEnabled()) {
            throw new AccountingException(AccountingError::PurchaseDocumentModuleOff, 'The purchase cycle is switched off.');
        }

        $this->transactional(function () use ($receivingId, $orderId, $items, $supplierId, $employeeId, $branchId, $action): void {
            $order = LockedRead::row($this->db, 'SELECT order_id, branch_id, supplier_id, status FROM ' . $this->db->prefixTable('purchase_orders') . ' WHERE order_id = ? FOR UPDATE', [$orderId]);

            if ($order === null) {
                throw new AccountingException(AccountingError::PurchaseDocumentNotFound, 'There is no such purchase order.', ['name' => (string) $orderId]);
            }

            $this->branches->requireBranch((int) $order->branch_id, $employeeId);

            if ((int) $order->branch_id !== $branchId) {
                throw new AccountingException(AccountingError::PurchaseOrderBranchMismatch, 'The goods are received in another branch than the one of the order.', ['name' => $this->numberOf($orderId)]);
            }

            if ((int) $order->supplier_id !== $supplierId) {
                throw new AccountingException(AccountingError::PurchaseOrderSupplierMismatch, 'The receiving is from another supplier than the order.', ['name' => $this->numberOf($orderId)]);
            }

            $status = $this->statusOf((string) $order->status, $orderId, $receivingId);

            if (! $status->canConvert(PurchaseDocumentKind::Order)) {
                throw new AccountingException(AccountingError::PurchaseOrderNotReceivable, 'An order that is ' . $status->name . ' is not received.', ['name' => lang($status->languageKey())]);
            }

            $taken = $this->allocate($orderId, $receivingId, $items);

            $this->db->table('purchase_order_receivings')->ignore(true)->insert(['receiving_id' => $receivingId, 'order_id' => $orderId]);
            $this->db->table('purchase_order_receiving_lines')->where('receiving_id', $receivingId)->delete();

            foreach ($taken as $lineId => $quantity) {
                $this->db->table('purchase_order_receiving_lines')->insert(['receiving_id' => $receivingId, 'order_line_id' => $lineId, 'quantity' => $quantity]);
            }

            $this->audit->write(PurchaseDocumentKind::Order->value, $orderId, $action, $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, ['receiving_id' => $receivingId, 'lines' => count($taken)], $branchId);
        });
    }

    /**
     * Gives the units of a receiving back to its order when the receiving goes, and says so in the audit log.
     * A receiving that was not made from an order does nothing.
     */
    public function releaseReceiving(int $receivingId, ?int $employeeId): void
    {
        $orderId = $this->orderOfReceiving($receivingId);

        if ($orderId === null) {
            return;
        }

        $order = LockedRead::row($this->db, 'SELECT order_id, branch_id FROM ' . $this->db->prefixTable('purchase_orders') . ' WHERE order_id = ? FOR UPDATE', [$orderId]);

        $this->db->table('purchase_order_receiving_lines')->where('receiving_id', $receivingId)->delete();
        $this->db->table('purchase_order_receivings')->where('receiving_id', $receivingId)->delete();
        $this->audit->write(PurchaseDocumentKind::Order->value, $orderId, 'receipt_released', $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, ['receiving_id' => $receivingId], $order === null ? null : (int) $order->branch_id);
    }

    /**
     * The status of the order as the receivings that stand say it is, leaving out one receiving when it is the one being saved again.
     */
    public function statusOf(string $stored, int $orderId, ?int $exceptReceivingId = null): PurchaseDocumentStatus
    {
        if ($stored === PurchaseDocumentStatus::Cancelled->value) {
            return PurchaseDocumentStatus::Cancelled;
        }

        $received = $this->receivedQuantities($orderId, $exceptReceivingId);
        $lines    = $this->db->table('purchase_order_lines')->select('line_id, quantity')->where('order_id', $orderId)->get()->getResult();
        $touched  = false;
        $full     = $lines !== [];

        foreach ($lines as $line) {
            $took    = $received[(int) $line->line_id] ?? '0';
            $touched = $touched || bccomp($took, '0', 3) > 0;
            $full    = $full && bccomp($took, (string) $line->quantity, 3) >= 0;
        }

        return match (true) {
            $full    => PurchaseDocumentStatus::Received,
            $touched => PurchaseDocumentStatus::PartlyReceived,
            default  => PurchaseDocumentStatus::from($stored),
        };
    }

    private function numberOf(int $orderId): string
    {
        return (new DocumentNumbering($this->db))->label(PurchaseDocumentKind::Order->documentType(), 'purchase_orders', $orderId);
    }

    /**
     * How many units of each line of the order the lines of the receiving take, the units of an item filling the
     * lines of that item in order. What is more than the order holds is refused, or lands on the last line of the
     * item when the settings allow it; an item the order does not hold is refused or left out the same way.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array<int, string> The units by the id of the line.
     *
     * @throws AccountingException
     */
    private function allocate(int $orderId, int $receivingId, array $items): array
    {
        $needed = [];

        foreach ($items as $item) {
            $itemId = (int) $item['item_id'];
            $units  = bcmul(sprintf('%.3F', (float) $item['quantity']), sprintf('%.3F', (float) ($item['receiving_quantity'] ?? 1) ?: 1.0), 3);

            if (bccomp($units, '0', 3) > 0) {
                $needed[$itemId] = bcadd($needed[$itemId] ?? '0', $units, 3);
            }
        }

        $received = $this->receivedQuantities($orderId, $receivingId);
        $byItem   = [];

        foreach ($this->db->table('purchase_order_lines')->where('order_id', $orderId)->orderBy('line_no', 'ASC')->get()->getResult() as $line) {
            $byItem[(int) $line->item_id][] = $line;
        }

        $taken       = [];
        $allowExcess = $this->settings->allowsExcess();

        foreach ($needed as $itemId => $units) {
            $lines = $byItem[$itemId] ?? [];
            $room  = '0';

            foreach ($lines as $line) {
                $left = bcsub($line->quantity, $received[(int) $line->line_id] ?? '0', 3);
                $left = bccomp($left, '0', 3) > 0 ? $left : '0';
                $room = bcadd($room, $left, 3);

                if (bccomp($units, '0', 3) <= 0 || bccomp($left, '0', 3) <= 0) {
                    continue;
                }

                $take                        = bccomp($units, $left, 3) < 0 ? $units : $left;
                $taken[(int) $line->line_id] = bcadd($taken[(int) $line->line_id] ?? '0', $take, 3);
                $units                       = bcsub($units, $take, 3);
            }

            if (bccomp($units, '0', 3) > 0) {
                if (! $allowExcess) {
                    throw new AccountingException(AccountingError::PurchaseOrderQuantityExceeded, 'The receiving takes more of the item than the order has left.', ['name' => $this->itemName($itemId), 'max' => $room]);
                }

                if ($lines !== []) {
                    $last         = (int) end($lines)->line_id;
                    $taken[$last] = bcadd($taken[$last] ?? '0', $units, 3);
                }
            }
        }

        return $taken;
    }

    private function itemName(int $itemId): string
    {
        $row = $this->db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow();

        return $row === null ? (string) $itemId : (string) $row->name;
    }
}
