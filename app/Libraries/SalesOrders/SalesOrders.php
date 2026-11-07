<?php

namespace App\Libraries\SalesOrders;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use App\Models\Employee;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The sales order: a document that says what a customer has asked for and posts nothing and moves no
 * stock, as a quote does, until an invoice is made from it. The invoice is made in the register, which
 * the order fills in with what is left to invoice; the quantities each saved invoice takes from each line
 * are kept with the invoice, in the transaction that saves it, and an order is never invoiced beyond what
 * it holds unless the module is set to allow it. Whether an order is partly invoiced or invoiced is read
 * from the invoices that stand, never stored. An order is the record of its invoices: once one was made
 * from it, its lines are not changed, and it is cancelled with a reason, never deleted. The order belongs
 * to the branch of the stock location it is sold from; a person limited to some branches sees and changes
 * the orders of those branches, and one who may not see every order sees their own.
 */
final class SalesOrders
{
    use RunsInTransaction;

    public const MODULE     = 'sales_orders';
    public const ADD        = 'so_add';
    public const EDIT       = 'so_edit';
    public const DELETE     = 'so_delete';
    public const VIEW_ALL   = 'so_view_all';
    public const CONVERT    = 'so_convert';
    public const NOTE_MAX   = 500;
    public const REASON_MAX = 255;
    private const ENTITY    = 'sales_order';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private SalesOrderSettings $settings;
    private DocumentNumbering $numbering;
    private AuditLog $audit;
    private RecordScope $scope;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->guard     = $guard ?? new AccountingGuard();
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->settings  = new SalesOrderSettings($this->db, $this->guard);
        $this->numbering = new DocumentNumbering($this->db);
        $this->audit     = new AuditLog($this->db);
        $this->scope     = new RecordScope($this->db);
    }

    /**
     * Writes a new order. The date defaults to today.
     *
     * @param array{customer_id?: int|string, location_id?: int|string, order_date?: string, note?: string, lines?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function create(array $input, ?int $employeeId, ?string $today = null): int
    {
        $this->guard->require(self::ADD, $employeeId);
        $this->assertOn();

        $customerId              = $this->customer($input['customer_id'] ?? 0);
        $date                    = $this->orderDate($input['order_date'] ?? null, $today ?? date('Y-m-d'));
        $note                    = $this->noteText($input['note'] ?? '');
        [$locationId, $branchId] = $this->location($input['location_id'] ?? 0, $employeeId);
        $lines                   = LineInput::normalise($input['lines'] ?? null, $locationId, $this->db, AccountingError::SalesOrderNoLines, AccountingError::SalesOrderLineInvalid);

        return $this->transactional(function () use ($customerId, $date, $note, $branchId, $lines, $employeeId): int {
            $this->db->table('sales_orders')->insert([
                'company_id'  => CompanyContext::id(),
                'branch_id'   => $branchId,
                'customer_id' => $customerId,
                'order_date'  => $date,
                'status'      => SalesOrderStatus::Open->value,
                'note'        => $note,
                'created_by'  => $employeeId,
            ]);

            $orderId = (int) $this->db->insertID();

            $this->insertLines($orderId, $lines);
            $this->numbering->assign(DocumentType::SalesOrder, 'sales_orders', $orderId, $branchId);
            $this->record($orderId, 'created', $employeeId, $branchId, ['customer_id' => $customerId, 'lines' => count($lines)]);

            return $orderId;
        });
    }

    /**
     * Changes an order nothing was invoiced from. When any invoice was made from it, even one that was
     * cancelled since, the lines are the record of what that invoice took and the order is not changed.
     *
     * @param array{customer_id?: int|string, location_id?: int|string, order_date?: string, note?: string, lines?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(int $orderId, array $input, ?int $employeeId, ?string $today = null): void
    {
        $this->guard->require(self::EDIT, $employeeId);
        $this->assertOn();

        $customerId              = $this->customer($input['customer_id'] ?? 0);
        $date                    = $this->orderDate($input['order_date'] ?? null, $today ?? date('Y-m-d'));
        $note                    = $this->noteText($input['note'] ?? '');
        [$locationId, $branchId] = $this->location($input['location_id'] ?? 0, $employeeId);
        $lines                   = LineInput::normalise($input['lines'] ?? null, $locationId, $this->db, AccountingError::SalesOrderNoLines, AccountingError::SalesOrderLineInvalid);

        $this->transactional(function () use ($orderId, $customerId, $date, $note, $branchId, $lines, $employeeId): void {
            $order = $this->lock($orderId, $employeeId);

            $this->branches->requireBranch((int) $order->branch_id, $employeeId);

            if (! $this->statusOf($orderId)?->isEditable() || $this->everInvoiced($orderId)) {
                throw new AccountingException(AccountingError::SalesOrderLocked, 'An order that was invoiced or cancelled is not changed.', ['name' => lang($this->statusOf($orderId)?->languageKey() ?? '')]);
            }

            $this->db->table('sales_orders')->where('order_id', $orderId)->update([
                'branch_id'   => $branchId,
                'customer_id' => $customerId,
                'order_date'  => $date,
                'note'        => $note,
                'updated_by'  => $employeeId,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);

            $this->db->table('sales_order_lines')->where('order_id', $orderId)->delete();
            $this->insertLines($orderId, $lines);
            $this->record($orderId, 'updated', $employeeId, $branchId, ['customer_id' => $customerId, 'lines' => count($lines)]);
        });
    }

    /**
     * Moves an order between open and approved, the two states set by hand.
     *
     * @throws AccountingException|BranchException
     */
    public function changeStatus(int $orderId, SalesOrderStatus $to, ?int $employeeId): SalesOrderStatus
    {
        $this->guard->require(self::EDIT, $employeeId);
        $this->assertOn();

        return $this->transactional(function () use ($orderId, $to, $employeeId): SalesOrderStatus {
            $order = $this->lock($orderId, $employeeId);

            $this->branches->requireBranch((int) $order->branch_id, $employeeId);

            $from = $this->statusOf($orderId) ?? SalesOrderStatus::Open;

            if ($from === $to) {
                return $from;
            }

            if (! in_array($to, $from->manualNext(), true) || ! $to->isStored()) {
                throw new AccountingException(AccountingError::SalesOrderStatusNotAllowed, 'An order cannot move from ' . $from->name . ' to ' . $to->name . '.', ['from' => lang($from->languageKey()), 'to' => lang($to->languageKey())]);
            }

            $this->db->table('sales_orders')->where('order_id', $orderId)->update(['status' => $to->value, 'updated_by' => $employeeId, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->record($orderId, 'status_changed', $employeeId, (int) $order->branch_id, ['from' => $from->name, 'to' => $to->name]);

            return $to;
        });
    }

    /**
     * Cancels an order with a reason. An order that was invoiced in part is closed for the rest; the invoices
     * stand as they are.
     *
     * @throws AccountingException|BranchException
     */
    public function cancel(int $orderId, string $reason, ?int $employeeId): void
    {
        $this->guard->require(self::DELETE, $employeeId);
        $this->assertOn();

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason is required to cancel an order.', ['max' => self::REASON_MAX]);
        }

        $this->transactional(function () use ($orderId, $reason, $employeeId): void {
            $order = $this->lock($orderId, $employeeId);

            $this->branches->requireBranch((int) $order->branch_id, $employeeId);

            $from = $this->statusOf($orderId) ?? SalesOrderStatus::Open;

            if (! $from->canCancel()) {
                throw new AccountingException(AccountingError::SalesOrderStatusNotAllowed, 'An order that is ' . $from->name . ' is not cancelled.', ['from' => lang($from->languageKey()), 'to' => lang(SalesOrderStatus::Cancelled->languageKey())]);
            }

            $this->db->table('sales_orders')->where('order_id', $orderId)->update([
                'status'        => SalesOrderStatus::Cancelled->value,
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->record($orderId, 'cancelled', $employeeId, (int) $order->branch_id, ['from' => $from->name, 'reason' => $reason]);
        });
    }

    /**
     * The order as the viewer may see it, or null when it does not exist or is out of their reach.
     */
    public function find(int $orderId, ?int $viewerId): ?object
    {
        $reach   = $this->reach($viewerId);
        $builder = $this->selected();

        $this->limitTo($builder, $reach, $viewerId);

        $order = $builder->where('o.order_id', $orderId)->get()->getRow();

        return $order === null ? null : $this->withStatus($order);
    }

    /**
     * What an order holds, line by line, with what was invoiced from each line and what is left.
     *
     * @return list<object>
     */
    public function lines(int $orderId): array
    {
        $invoiced = $this->invoicedQuantities($orderId);
        $lines    = $this->db->table('sales_order_lines l')
            ->select('l.*, i.name AS item_name, i.item_number')
            ->join('items i', 'i.item_id = l.item_id')
            ->where('l.order_id', $orderId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();

        foreach ($lines as $line) {
            $line->invoiced  = $invoiced[(int) $line->line_id] ?? '0.000';
            $line->remaining = bccomp($line->quantity, $line->invoiced, 3) > 0 ? bcsub($line->quantity, $line->invoiced, 3) : '0.000';
        }

        return $lines;
    }

    /**
     * The lines with something left to invoice, as the register takes them up.
     *
     * @return list<object>
     */
    public function remainingLines(int $orderId): array
    {
        return array_values(array_filter($this->lines($orderId), static fn (object $line): bool => bccomp($line->remaining, '0', 3) > 0));
    }

    /**
     * Where the order stands now, read from its invoices; null when there is no such order.
     */
    public function statusOf(int $orderId, ?int $exceptSaleId = null): ?SalesOrderStatus
    {
        $row = $this->db->table('sales_orders')->select('status')->where('order_id', $orderId)->get()->getRow();

        if ($row === null) {
            return null;
        }

        return $this->derive((string) $row->status, $orderId, $exceptSaleId);
    }

    /**
     * The orders the viewer may see, the newest first.
     *
     * @param array{status?: string, customer_id?: int, from?: string, to?: string, search?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, ?int $viewerId, int $limit = 25, int $offset = 0): array
    {
        $lines    = $this->db->prefixTable('sales_order_lines');
        $taken    = $this->db->prefixTable('sales_order_invoice_lines');
        $sales    = $this->db->prefixTable('sales');
        $invoiced = "COALESCE((SELECT SUM(t.quantity) FROM {$taken} t JOIN {$sales} s ON s.sale_id = t.sale_id AND s.sale_status = " . COMPLETED . ' WHERE t.order_line_id = l.line_id), 0)';
        $derived  = "CASE WHEN o.status = 'cancelled' THEN 'cancelled' WHEN (SELECT COUNT(*) FROM {$lines} l WHERE l.order_id = o.order_id) > 0 AND NOT EXISTS (SELECT 1 FROM {$lines} l WHERE l.order_id = o.order_id AND {$invoiced} < l.quantity) THEN 'invoiced' WHEN EXISTS (SELECT 1 FROM {$lines} l WHERE l.order_id = o.order_id AND {$invoiced} > 0) THEN 'partly_invoiced' ELSE o.status END";

        $count = $this->searched($filters, $viewerId, $derived)->countAllResults();
        $rows  = $this->searched($filters, $viewerId, $derived)
            ->select("o.*, {$derived} AS derived_status, b.code AS branch_code, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS customer_name, d.number AS document_number, (SELECT COALESCE(SUM(l.quantity * l.unit_price), 0) FROM {$lines} l WHERE l.order_id = o.order_id) AS gross", false)
            ->orderBy('o.order_date', 'DESC')
            ->orderBy('o.order_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $count, 'rows' => $rows];
    }

    public function numberOf(int $orderId): string
    {
        return $this->numbering->label(DocumentType::SalesOrder, 'sales_orders', $orderId);
    }

    public function peekNumber(): ?string
    {
        return $this->numbering->peek(DocumentType::SalesOrder);
    }

    public function isEnabled(): bool
    {
        return $this->settings->isEnabled();
    }

    public function allowsExcess(): bool
    {
        return $this->settings->allowsExcess();
    }

    /**
     * Whether the employee may open the register on this order now, with the reason when not: the module
     * is on, they may convert orders, the order is theirs to see (which is what keeps it to their branches), and it is approved
     * or invoiced in part. It takes no lock: the invoice that is saved asks again under the lock of the order.
     *
     * @throws AccountingException|BranchException
     */
    public function assertConvertible(int $orderId, ?int $employeeId): object
    {
        $this->guard->require(self::CONVERT, $employeeId);
        $this->assertOn();

        $order = $this->find($orderId, $employeeId);

        if ($order === null) {
            throw new AccountingException(AccountingError::SalesOrderNotFound, 'There is no such sales order.', ['name' => (string) $orderId]);
        }

        if (! $order->status_object->canConvert()) {
            throw new AccountingException(AccountingError::SalesOrderNotConvertible, 'An order that is ' . $order->status_object->name . ' does not become an invoice.', ['name' => lang($order->status_object->languageKey())]);
        }

        return $order;
    }

    /**
     * The order an invoice (or the draft of one) was made from, or null.
     */
    public function orderOfSale(int $saleId): ?int
    {
        $row = $this->db->table('sales_order_invoices')->select('order_id')->where('sale_id', $saleId)->get()->getRow();

        return $row === null ? null : (int) $row->order_id;
    }

    /**
     * The invoices made from the order that stand, oldest first.
     *
     * @return list<object>
     */
    public function invoicesOf(int $orderId): array
    {
        return $this->db->table('sales_order_invoices k')
            ->select('k.sale_id, s.sale_status, s.sale_type, s.invoice_number, s.sale_time')
            ->join('sales s', 's.sale_id = k.sale_id')
            ->where('k.order_id', $orderId)
            ->orderBy('k.sale_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Remembers that the invoice, or the draft of one, is made from the order, so the order is found
     * again whoever saves the draft next. It takes no quantity: a draft takes none until it is issued.
     */
    public function link(int $saleId, int $orderId): void
    {
        $this->db->table('sales_order_invoices')->ignore(true)->insert(['sale_id' => $saleId, 'order_id' => $orderId]);
    }

    /**
     * Takes from the lines of the order what an invoice that is completed holds. It runs inside the transaction
     * that saves the invoice, under the lock of the order, so two invoices made from one order never take the
     * same quantity, and a refusal sends the whole invoice back.
     *
     * @param list<array<string, mixed>> $items The lines of the invoice, as the register saves them.
     *
     * @throws AccountingException|BranchException
     */
    public function recordInvoice(int $saleId, int $orderId, array $items, int $customerId, ?int $employeeId, int $branchId): void
    {
        $this->transactional(function () use ($saleId, $orderId, $items, $customerId, $employeeId, $branchId): void {
            $order = LockedRead::row($this->db, 'SELECT order_id, branch_id, customer_id, status FROM ' . $this->db->prefixTable('sales_orders') . ' WHERE order_id = ? FOR UPDATE', [$orderId]);

            if ($order === null) {
                throw new AccountingException(AccountingError::SalesOrderNotFound, 'There is no such sales order.', ['name' => (string) $orderId]);
            }

            $this->branches->requireBranch((int) $order->branch_id, $employeeId);

            if ((int) $order->customer_id !== $customerId) {
                throw new AccountingException(AccountingError::SalesOrderCustomerMismatch, 'The invoice is for another customer than the order.', ['name' => $this->numberOf($orderId)]);
            }

            $status = $this->derive((string) $order->status, $orderId, $saleId);

            if (! $status->canConvert()) {
                throw new AccountingException(AccountingError::SalesOrderNotConvertible, 'An order that is ' . $status->name . ' does not become an invoice.', ['name' => lang($status->languageKey())]);
            }

            $taken = $this->allocate($orderId, $saleId, $items);

            $this->link($saleId, $orderId);
            $this->db->table('sales_order_invoice_lines')->where('sale_id', $saleId)->delete();

            foreach ($taken as $lineId => $quantity) {
                $this->db->table('sales_order_invoice_lines')->insert(['sale_id' => $saleId, 'order_line_id' => $lineId, 'quantity' => $quantity]);
            }

            $this->record($orderId, 'invoiced', $employeeId, $branchId, ['sale_id' => $saleId, 'lines' => count($taken)]);
        });
    }

    /**
     * How much of each line of the order the lines of the invoice take, the quantity of an item filling the
     * lines of that item in order. What is more than the order holds is refused, or lands on the last line
     * of the item when the module allows it; an item the order does not hold is refused or left out the same way.
     *
     * @param list<array<string, mixed>> $items
     *
     * @return array<int, string> The quantity by the id of the line.
     *
     * @throws AccountingException
     */
    private function allocate(int $orderId, int $saleId, array $items): array
    {
        $needed = [];

        foreach ($items as $item) {
            $itemId          = (int) $item['item_id'];
            $needed[$itemId] = bcadd($needed[$itemId] ?? '0', (string) $item['quantity'], 3);
        }

        $invoiced = $this->invoicedQuantities($orderId, $saleId);
        $byItem   = [];

        foreach ($this->db->table('sales_order_lines')->where('order_id', $orderId)->orderBy('line_no', 'ASC')->get()->getResult() as $line) {
            $byItem[(int) $line->item_id][] = $line;
        }

        $taken       = [];
        $allowExcess = $this->settings->allowsExcess();

        foreach ($needed as $itemId => $quantity) {
            $lines = $byItem[$itemId] ?? [];
            $room  = '0';

            foreach ($lines as $line) {
                $left = bcsub($line->quantity, $invoiced[(int) $line->line_id] ?? '0', 3);
                $left = bccomp($left, '0', 3) > 0 ? $left : '0';
                $room = bcadd($room, $left, 3);

                if (bccomp($quantity, '0', 3) <= 0 || bccomp($left, '0', 3) <= 0) {
                    continue;
                }

                $take                        = bccomp($quantity, $left, 3) < 0 ? $quantity : $left;
                $taken[(int) $line->line_id] = bcadd($taken[(int) $line->line_id] ?? '0', $take, 3);
                $quantity                    = bcsub($quantity, $take, 3);
            }

            if (bccomp($quantity, '0', 3) > 0) {
                if (! $allowExcess) {
                    throw new AccountingException(AccountingError::SalesOrderQuantityExceeded, 'The invoice takes more of the item than the order has left.', ['name' => $this->itemName($itemId), 'max' => $room]);
                }

                if ($lines !== []) {
                    $last         = (int) end($lines)->line_id;
                    $taken[$last] = bcadd($taken[$last] ?? '0', $quantity, 3);
                }
            }
        }

        return $taken;
    }

    /**
     * What each line of the order was taken for by the invoices that stand, leaving out one sale when it is
     * the one being saved again.
     *
     * @return array<int, string>
     */
    private function invoicedQuantities(int $orderId, ?int $exceptSaleId = null): array
    {
        $builder = $this->db->table('sales_order_invoice_lines t')
            ->select('t.order_line_id, SUM(t.quantity) AS taken')
            ->join('sales_order_lines l', 'l.line_id = t.order_line_id')
            ->join('sales s', 's.sale_id = t.sale_id AND s.sale_status = ' . COMPLETED)
            ->where('l.order_id', $orderId)
            ->groupBy('t.order_line_id');

        if ($exceptSaleId !== null) {
            $builder->where('t.sale_id !=', $exceptSaleId);
        }

        $quantities = [];

        foreach ($builder->get()->getResult() as $row) {
            $quantities[(int) $row->order_line_id] = bcadd((string) $row->taken, '0', 3);
        }

        return $quantities;
    }

    private function everInvoiced(int $orderId): bool
    {
        return $this->db->table('sales_order_invoice_lines t')->join('sales_order_lines l', 'l.line_id = t.order_line_id')->where('l.order_id', $orderId)->countAllResults() > 0;
    }

    private function derive(string $stored, int $orderId, ?int $exceptSaleId = null): SalesOrderStatus
    {
        if ($stored === SalesOrderStatus::Cancelled->value) {
            return SalesOrderStatus::Cancelled;
        }

        $invoiced = $this->invoicedQuantities($orderId, $exceptSaleId);
        $lines    = $this->db->table('sales_order_lines')->select('line_id, quantity')->where('order_id', $orderId)->get()->getResult();
        $touched  = false;
        $full     = $lines !== [];

        foreach ($lines as $line) {
            $took    = $invoiced[(int) $line->line_id] ?? '0';
            $touched = $touched || bccomp($took, '0', 3) > 0;
            $full    = $full && bccomp($took, (string) $line->quantity, 3) >= 0;
        }

        return match (true) {
            $full    => SalesOrderStatus::Invoiced,
            $touched => SalesOrderStatus::PartlyInvoiced,
            default  => SalesOrderStatus::from($stored),
        };
    }

    private function withStatus(object $order): object
    {
        $order->status_object = $this->derive((string) $order->status, (int) $order->order_id);

        return $order;
    }

    private function selected(): BaseBuilder
    {
        return $this->db->table('sales_orders o')
            ->select("o.*, b.code AS branch_code, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS customer_name, d.number AS document_number, TRIM(CONCAT(ep.first_name, ' ', ep.last_name)) AS creator_name", false)
            ->join('branches b', 'b.branch_id = o.branch_id')
            ->join('people p', 'p.person_id = o.customer_id', 'left')
            ->join('people ep', 'ep.person_id = o.created_by', 'left')
            ->join('document_numbers d', "d.doc_type = '" . DocumentType::SalesOrder->value . "' AND d.source_table = 'sales_orders' AND d.source_id = o.order_id", 'left')
            ->where('o.company_id', CompanyContext::id());
    }

    /**
     * @param array{status?: string, customer_id?: int, from?: string, to?: string, search?: string} $filters
     */
    private function searched(array $filters, ?int $viewerId, string $derived): BaseBuilder
    {
        $reach   = $this->reach($viewerId);
        $builder = $this->db->table('sales_orders o')
            ->join('branches b', 'b.branch_id = o.branch_id')
            ->join('people p', 'p.person_id = o.customer_id', 'left')
            ->join('document_numbers d', "d.doc_type = '" . DocumentType::SalesOrder->value . "' AND d.source_table = 'sales_orders' AND d.source_id = o.order_id", 'left')
            ->where('o.company_id', CompanyContext::id());

        $this->limitTo($builder, $reach, $viewerId);

        $status = (string) ($filters['status'] ?? '');

        if (SalesOrderStatus::tryFrom($status) !== null) {
            $builder->where("({$derived}) = " . $this->db->escape($status), null, false);
        }

        if (! empty($filters['customer_id'])) {
            $builder->where('o.customer_id', (int) $filters['customer_id']);
        }

        if (! empty($filters['from'])) {
            $builder->where('o.order_date >=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $builder->where('o.order_date <=', $filters['to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()
                ->like('d.number', $search)
                ->orLike("CONCAT(p.first_name, ' ', p.last_name)", $search)
                ->groupEnd();
        }

        return $builder;
    }

    /**
     * What the viewer reaches, worked out before a query is built: the builder of this connection forgets the
     * aliases of a query that is half built when another query is made.
     *
     * @return array{branches: list<int>|null, all: bool}
     */
    private function reach(?int $viewerId): array
    {
        return ['branches' => $this->branches->allowedBranchIds($viewerId), 'all' => $this->mayViewAll($viewerId)];
    }

    /**
     * Keeps the orders of the branches the viewer works in, and of the viewer alone when they may not see every order.
     *
     * @param array{branches: list<int>|null, all: bool} $reach
     */
    private function limitTo(BaseBuilder $builder, array $reach, ?int $viewerId): void
    {
        if ($reach['branches'] !== null) {
            $reach['branches'] === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('o.branch_id', $reach['branches']);
        }

        if (! $reach['all']) {
            $builder->where('o.created_by', $viewerId);
        }
    }

    /**
     * Whether the viewer sees the orders of others: the system does, and so does an employee who holds the
     * permission to view every order and is not narrowed to their own records.
     */
    public function mayViewAll(?int $viewerId): bool
    {
        return $viewerId === null || (model(Employee::class)->has_grant(self::VIEW_ALL, $viewerId) && ! $this->scope->ownOnly($viewerId));
    }

    /**
     * Locks the row of the order, for one that exists and that the viewer may see.
     *
     * @throws AccountingException
     */
    private function lock(int $orderId, ?int $employeeId): object
    {
        $order = LockedRead::row($this->db, 'SELECT order_id, branch_id, customer_id, status, created_by FROM ' . $this->db->prefixTable('sales_orders') . ' WHERE order_id = ? FOR UPDATE', [$orderId]);

        if ($order === null || (! $this->mayViewAll($employeeId) && (int) $order->created_by !== $employeeId)) {
            throw new AccountingException(AccountingError::SalesOrderNotFound, 'There is no such sales order.', ['name' => (string) $orderId]);
        }

        return $order;
    }

    /**
     * @param list<array<string, mixed>> $lines
     */
    private function insertLines(int $orderId, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $this->db->table('sales_order_lines')->insert([...$line, 'order_id' => $orderId, 'line_no' => $index + 1]);
        }
    }

    /**
     * @throws AccountingException
     */
    private function assertOn(): void
    {
        if (! $this->settings->isEnabled()) {
            throw new AccountingException(AccountingError::SalesOrderModuleOff, 'The sales orders are switched off.');
        }
    }

    /**
     * @throws AccountingException
     */
    private function customer(mixed $value): int
    {
        $customerId = (int) $value;
        $found      = $customerId > 0 && $this->db->table('customers')->where(['person_id' => $customerId, 'deleted' => 0])->countAllResults() > 0;

        if (! $found) {
            throw new AccountingException(AccountingError::SalesOrderCustomerInvalid, 'The customer does not exist.', ['name' => (string) $customerId]);
        }

        return $customerId;
    }

    /**
     * @return array{0: int, 1: int} The stock location and its branch.
     *
     * @throws AccountingException|BranchException
     */
    private function location(mixed $value, ?int $employeeId): array
    {
        $locationId = (int) $value;
        $row        = $locationId > 0 ? $this->db->table('stock_locations')->select('location_id, branch_id')->where(['location_id' => $locationId, 'deleted' => 0])->get()->getRow() : null;

        if ($row === null) {
            throw new AccountingException(AccountingError::SalesOrderLocationInvalid, 'The stock location does not exist.', ['name' => (string) $locationId]);
        }

        $this->branches->requireBranch((int) $row->branch_id, $employeeId);

        return [(int) $row->location_id, (int) $row->branch_id];
    }

    /**
     * @throws AccountingException
     */
    private function orderDate(mixed $value, string $today): string
    {
        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return $today;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($parsed === false || $parsed->format('Y-m-d') !== $text) {
            throw new AccountingException(AccountingError::SalesOrderDateInvalid, "The date {$text} is not a day.", ['date' => $text]);
        }

        return $text;
    }

    /**
     * @throws AccountingException
     */
    private function noteText(mixed $value): string
    {
        $text = trim((string) $value);

        if (mb_strlen($text) > self::NOTE_MAX) {
            throw new AccountingException(AccountingError::DescriptionTooLong, 'The note is too long.', ['max' => self::NOTE_MAX]);
        }

        return $text;
    }

    private function itemName(int $itemId): string
    {
        $row = $this->db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow();

        return $row === null ? (string) $itemId : (string) $row->name;
    }

    /**
     * Writes the change in the audit log of the books while they are on, as every other document does.
     *
     * @param array<string, mixed> $details
     */
    private function record(int $orderId, string $action, ?int $employeeId, int $branchId, array $details): void
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return;
        }

        $this->audit->write(self::ENTITY, $orderId, $action, $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, $details, $branchId);
    }
}
