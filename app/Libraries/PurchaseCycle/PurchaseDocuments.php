<?php

namespace App\Libraries\PurchaseCycle;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\TextInput;
use App\Libraries\Numbering\DocumentNumbering;
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
 * One of the four documents of the purchase cycle (a purchase request, a quotation request, the quotation of
 * a supplier or a purchase order): what is wanted, from whom, at what price, and a status that says where it
 * stands. It posts nothing, moves no stock and touches no treasury: only the purchase invoice, the receiving,
 * does, and it is made in the receiving register. A document is made from the one before it by an explicit
 * conversion that copies the lines forward and never by itself; one that was converted is left as it was and
 * is the document of the next step. The document belongs to the branch of the stock location the goods are
 * wanted at; a person limited to some branches sees and changes the documents of those branches, and one who
 * may not see every document sees their own. Nothing is ever sent to a supplier: the status "sent" is a label
 * that somebody sets by hand, where the family allows it. A document is cancelled with a reason, never deleted.
 */
final class PurchaseDocuments
{
    use RunsInTransaction;

    public const ADD        = 'add';
    public const EDIT       = 'edit';
    public const DELETE     = 'delete';
    public const VIEW_ALL   = 'view_all';
    public const CONVERT    = 'convert';
    public const NOTE_MAX   = 500;
    public const REASON_MAX = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PurchaseCycleSettings $settings;
    private DocumentNumbering $numbering;
    private AuditLog $audit;
    private RecordScope $scope;
    private PurchaseOrderReceipts $receipts;

    public function __construct(private readonly PurchaseDocumentKind $kind, ?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->guard     = $guard ?? new AccountingGuard();
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->settings  = new PurchaseCycleSettings($this->db, $this->guard);
        $this->numbering = new DocumentNumbering($this->db);
        $this->audit     = new AuditLog($this->db);
        $this->scope     = new RecordScope($this->db);
        $this->receipts  = new PurchaseOrderReceipts($this->db, $this->branches);
    }

    public function kind(): PurchaseDocumentKind
    {
        return $this->kind;
    }

    /**
     * Writes a new document. The date defaults to today.
     *
     * @param array{supplier_id?: int|string, location_id?: int|string, document_date?: string, due_date?: string, note?: string, lines?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function create(array $input, ?int $employeeId, ?string $today = null): int
    {
        $this->guard->require($this->kind->permission(self::ADD), $employeeId);
        $this->assertOn();

        $header = $this->header($input, $employeeId, $today ?? date('Y-m-d'));
        $lines  = PurchaseLines::normalise($input['lines'] ?? null, $this->db);

        return $this->transactional(fn (): int => $this->store($header, $lines, null, $employeeId));
    }

    /**
     * Writes the document that a conversion makes from the one before it, in the transaction of the conversion.
     *
     * @param array{branch_id: int, location_id: int, supplier_id: int|null, document_date: string, due_date: string|null, note: string} $header
     * @param list<array{item_id: int, description: string, quantity: string, unit_price: string}>                                       $lines
     *
     * @throws AccountingException
     */
    public function createFromSource(int $sourceId, array $header, array $lines, ?int $employeeId): int
    {
        $this->guard->require($this->kind->permission(self::ADD), $employeeId);
        $this->assertOn();

        return $this->transactional(fn (): int => $this->store($header, $lines, $sourceId, $employeeId));
    }

    /**
     * Changes a document that was not converted and, for an order, never received from. A document that
     * was cancelled, converted or received is the record of what was agreed and is not changed.
     *
     * @param array{supplier_id?: int|string, location_id?: int|string, document_date?: string, due_date?: string, note?: string, lines?: mixed} $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(int $documentId, array $input, ?int $employeeId, ?string $today = null): void
    {
        $this->guard->require($this->kind->permission(self::EDIT), $employeeId);
        $this->assertOn();

        $header = $this->header($input, $employeeId, $today ?? date('Y-m-d'));
        $lines  = PurchaseLines::normalise($input['lines'] ?? null, $this->db);

        $this->transactional(function () use ($documentId, $header, $lines, $employeeId): void {
            $document = $this->lock($documentId, $employeeId);
            $status   = $this->derive((string) $document->status, $documentId);

            if (! $status->isEditable() || ($this->kind === PurchaseDocumentKind::Order && $this->receipts->everReceived($documentId))) {
                throw new AccountingException(AccountingError::PurchaseDocumentLocked, 'A document that was converted, received or cancelled is not changed.', ['name' => lang($status->languageKey())]);
            }

            $row = [
                'branch_id'     => $header['branch_id'],
                'location_id'   => $header['location_id'],
                'document_date' => $header['document_date'],
                'due_date'      => $header['due_date'],
                'note'          => $header['note'],
                'updated_by'    => $employeeId,
                'updated_at'    => date('Y-m-d H:i:s'),
            ];

            if ($this->kind->hasSupplier()) {
                $row['supplier_id'] = $header['supplier_id'];
            }

            $this->db->table($this->kind->table())->where($this->kind->idColumn(), $documentId)->update($row);
            $this->db->table($this->kind->linesTable())->where($this->kind->idColumn(), $documentId)->delete();
            $this->insertLines($documentId, $lines);
            $this->record($documentId, 'updated', $employeeId, $header['branch_id'], ['lines' => count($lines)]);
        });
    }

    /**
     * Moves a document between the three labels that are set by hand, where the family allows it.
     *
     * @throws AccountingException
     */
    public function changeStatus(int $documentId, PurchaseDocumentStatus $to, ?int $employeeId): PurchaseDocumentStatus
    {
        $this->guard->require($this->kind->permission(self::EDIT), $employeeId);
        $this->assertOn();

        if (! $this->settings->allowsManualStatus($this->kind)) {
            throw new AccountingException(AccountingError::PurchaseDocumentManualStatusOff, 'The statuses set by hand are switched off for this document.');
        }

        return $this->transactional(function () use ($documentId, $to, $employeeId): PurchaseDocumentStatus {
            $document = $this->lock($documentId, $employeeId);
            $from     = $this->derive((string) $document->status, $documentId);

            if ($from === $to) {
                return $from;
            }

            if (! in_array($to, $from->manualNext(), true)) {
                throw new AccountingException(AccountingError::PurchaseDocumentStatusNotAllowed, 'A document cannot move from ' . $from->name . ' to ' . $to->name . '.', ['from' => lang($from->languageKey()), 'to' => lang($to->languageKey())]);
            }

            $this->db->table($this->kind->table())->where($this->kind->idColumn(), $documentId)->update(['status' => $to->value, 'updated_by' => $employeeId, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->record($documentId, 'status_changed', $employeeId, (int) $document->branch_id, ['from' => $from->name, 'to' => $to->name]);

            return $to;
        });
    }

    /**
     * Cancels a document with a reason. An order that was received in part is closed for the rest; the receivings stand as they are.
     *
     * @throws AccountingException
     */
    public function cancel(int $documentId, string $reason, ?int $employeeId): void
    {
        $this->guard->require($this->kind->permission(self::DELETE), $employeeId);
        $this->assertOn();

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason is required to cancel a document.', ['max' => self::REASON_MAX]);
        }

        $this->transactional(function () use ($documentId, $reason, $employeeId): void {
            $document = $this->lock($documentId, $employeeId);
            $from     = $this->derive((string) $document->status, $documentId);

            if (! $from->canCancel()) {
                throw new AccountingException(AccountingError::PurchaseDocumentStatusNotAllowed, 'A document that is ' . $from->name . ' is not cancelled.', ['from' => lang($from->languageKey()), 'to' => lang(PurchaseDocumentStatus::Cancelled->languageKey())]);
            }

            $this->db->table($this->kind->table())->where($this->kind->idColumn(), $documentId)->update([
                'status'        => PurchaseDocumentStatus::Cancelled->value,
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->record($documentId, 'cancelled', $employeeId, (int) $document->branch_id, ['from' => $from->name, 'reason' => $reason]);
        });
    }

    /**
     * Makes the next document of the chain from this one, with its lines copied forward, and returns its id. The document
     * made is a new one, open, in the same branch and for the same supplier; this one stays as it was and reads as converted for as long as the
     * new one is not cancelled. It is never done by itself.
     *
     * @throws AccountingException|BranchException
     */
    public function convert(int $documentId, ?int $employeeId, ?string $today = null): int
    {
        $this->guard->require($this->kind->permission(self::CONVERT), $employeeId);
        $this->assertOn();

        $next = $this->kind->next();

        if ($next === null) {
            throw new AccountingException(AccountingError::PurchaseDocumentNotConvertible, 'A purchase order is received, not converted.', ['name' => lang($this->kind->labelKey())]);
        }

        $target = new self($next, $this->db, $this->guard, $this->branches);

        return $this->transactional(function () use ($documentId, $employeeId, $today, $next, $target): int {
            $document = $this->lock($documentId, $employeeId);
            $status   = $this->derive((string) $document->status, $documentId);

            if (! $status->canConvert($this->kind)) {
                throw new AccountingException(AccountingError::PurchaseDocumentNotConvertible, 'A document that is ' . $status->name . ' does not become the next document.', ['name' => lang($status->languageKey())]);
            }

            $supplierId = $this->kind->hasSupplier() && $document->supplier_id !== null ? (int) $document->supplier_id : null;

            if ($next->needsSupplier()) {
                if ($supplierId === null) {
                    throw new AccountingException(AccountingError::PurchaseDocumentSupplierNeeded, 'The document has no supplier: choose one before it becomes the next document.');
                }

                $this->supplier($supplierId);
            }

            $lines = array_map(
                static fn (object $line): array => ['item_id' => (int) $line->item_id, 'description' => (string) $line->description, 'quantity' => (string) $line->quantity, 'unit_price' => (string) $line->unit_price],
                $this->db->table($this->kind->linesTable())->where($this->kind->idColumn(), $documentId)->orderBy('line_no', 'ASC')->get()->getResult(),
            );

            $newId = $target->createFromSource($documentId, [
                'branch_id'     => (int) $document->branch_id,
                'location_id'   => (int) $document->location_id,
                'supplier_id'   => $next->hasSupplier() ? $supplierId : null,
                'document_date' => $today ?? date('Y-m-d'),
                'due_date'      => null,
                'note'          => (string) $document->note,
            ], $lines, $employeeId);

            $this->record($documentId, 'converted', $employeeId, (int) $document->branch_id, ['to' => $next->value, 'document_id' => $newId]);

            return $newId;
        });
    }

    /**
     * Whether the employee may open the receiving register on this order now, with the reason when not: the cycle is on, they
     * may convert orders, the order is theirs to see (which is what keeps it to their branches and their own), and it is not cancelled,
     * received in full or already a document that was converted. It takes no lock: the receiving that is saved asks again under the lock of the order.
     *
     * @throws AccountingException
     */
    public function assertReceivable(int $orderId, ?int $employeeId): object
    {
        $this->guard->require($this->kind->permission(self::CONVERT), $employeeId);
        $this->assertOn();

        if ($this->kind !== PurchaseDocumentKind::Order) {
            throw new AccountingException(AccountingError::PurchaseDocumentNotConvertible, 'Only a purchase order is received.', ['name' => lang($this->kind->labelKey())]);
        }

        $order = $this->find($orderId, $employeeId);

        if ($order === null) {
            throw new AccountingException(AccountingError::PurchaseDocumentNotFound, 'There is no such purchase order.', ['name' => (string) $orderId]);
        }

        if (! $order->status_object->canConvert($this->kind)) {
            throw new AccountingException(AccountingError::PurchaseOrderNotReceivable, 'An order that is ' . $order->status_object->name . ' is not received.', ['name' => lang($order->status_object->languageKey())]);
        }

        $this->supplier((int) $order->supplier_id);

        return $order;
    }

    /**
     * The document as the viewer may see it, or null when it does not exist or is out of their reach.
     */
    public function find(int $documentId, ?int $viewerId): ?object
    {
        $reach   = $this->reach($viewerId);
        $builder = $this->selected();

        $this->limitTo($builder, $reach, $viewerId);

        $document = $builder->where('o.' . $this->kind->idColumn(), $documentId)->get()->getRow();

        if ($document === null) {
            return null;
        }

        $document->status_object = $this->derive((string) $document->status, $documentId);

        return $document;
    }

    /**
     * What a document holds, line by line. For an order each line also says what was received and what is left.
     *
     * @return list<object>
     */
    public function lines(int $documentId): array
    {
        $received = $this->kind === PurchaseDocumentKind::Order ? $this->receipts->receivedQuantities($documentId) : [];
        $lines    = $this->db->table($this->kind->linesTable() . ' l')
            ->select('l.*, i.name AS item_name, i.item_number')
            ->join('items i', 'i.item_id = l.item_id')
            ->where('l.' . $this->kind->idColumn(), $documentId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();

        foreach ($lines as $line) {
            $line->received  = $received[(int) $line->line_id] ?? '0.000';
            $line->remaining = bccomp($line->quantity, $line->received, 3) > 0 ? bcsub($line->quantity, $line->received, 3) : '0.000';
        }

        return $lines;
    }

    /**
     * The lines of an order with something left to receive, as the receiving register takes them up.
     *
     * @return list<object>
     */
    public function remainingLines(int $orderId): array
    {
        return array_values(array_filter($this->lines($orderId), static fn (object $line): bool => bccomp($line->remaining, '0', 3) > 0));
    }

    /**
     * Where the document stands now, read from the documents and the receivings that stand; null when there is no such document.
     */
    public function statusOf(int $documentId): ?PurchaseDocumentStatus
    {
        $row = $this->db->table($this->kind->table())->select('status')->where($this->kind->idColumn(), $documentId)->get()->getRow();

        return $row === null ? null : $this->derive((string) $row->status, $documentId);
    }

    /**
     * The documents of the next step that were made from this one, the newest first, with their numbers and statuses.
     *
     * @return list<object>
     */
    public function madeFrom(int $documentId): array
    {
        $next = $this->kind->next();

        if ($next === null) {
            return [];
        }

        $rows    = $this->db->table($next->table())->select($next->idColumn() . ' AS document_id, status')->where($next->sourceColumn(), $documentId)->orderBy($next->idColumn(), 'DESC')->get()->getResult();
        $numbers = $this->numbering->numbers($next->documentType(), $next->table(), array_map(static fn (object $row): int => (int) $row->document_id, $rows));

        foreach ($rows as $row) {
            $row->number = $numbers[(int) $row->document_id] ?? $next->documentType()->fallback((int) $row->document_id);
        }

        return $rows;
    }

    /**
     * The document of the step before that this one was made from, with its number, or null.
     */
    public function madeOf(object $document): ?object
    {
        $column = $this->kind->sourceColumn();

        if ($column === null || ($document->{$column} ?? null) === null) {
            return null;
        }

        $previous = $this->previousKind();

        return (object) ['kind' => $previous, 'document_id' => (int) $document->{$column}, 'number' => $this->numbering->label($previous->documentType(), $previous->table(), (int) $document->{$column})];
    }

    /**
     * The receivings made from an order that stand, for the page of the order.
     *
     * @return list<object>
     */
    public function receivings(int $orderId): array
    {
        return $this->kind === PurchaseDocumentKind::Order ? $this->receipts->receivingsOf($orderId) : [];
    }

    /**
     * The documents the viewer may see, the newest first.
     *
     * @param array{status?: string, supplier_id?: int, from?: string, to?: string, search?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, ?int $viewerId, int $limit = 25, int $offset = 0): array
    {
        $id      = $this->kind->idColumn();
        $lines   = $this->db->prefixTable($this->kind->linesTable());
        $derived = $this->derivedSql();

        $count = $this->searched($filters, $viewerId, $derived)->countAllResults();
        $rows  = $this->searched($filters, $viewerId, $derived)
            ->select("o.*, o.{$id} AS document_id, {$derived} AS derived_status, b.code AS branch_code, d.number AS document_number, (SELECT COALESCE(SUM(l.quantity * l.unit_price), 0) FROM {$lines} l WHERE l.{$id} = o.{$id}) AS gross" . ($this->kind->hasSupplier() ? ", COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS supplier_name" : ''), false)
            ->orderBy('o.document_date', 'DESC')
            ->orderBy("o.{$id}", 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $count, 'rows' => $rows];
    }

    public function numberOf(int $documentId): string
    {
        return $this->numbering->label($this->kind->documentType(), $this->kind->table(), $documentId);
    }

    public function peekNumber(): ?string
    {
        return $this->numbering->peek($this->kind->documentType());
    }

    public function isEnabled(): bool
    {
        return $this->settings->isEnabled();
    }

    public function allowsManualStatus(): bool
    {
        return $this->settings->allowsManualStatus($this->kind);
    }

    /**
     * Whether the viewer sees the documents of others: the system does, and so does an employee who holds the
     * permission to view every document of the family and is not narrowed to their own records.
     */
    public function mayViewAll(?int $viewerId): bool
    {
        return $viewerId === null || (model(Employee::class)->has_grant($this->kind->permission(self::VIEW_ALL), $viewerId) && ! $this->scope->ownOnly($viewerId));
    }

    private function previousKind(): PurchaseDocumentKind
    {
        foreach (PurchaseDocumentKind::cases() as $candidate) {
            if ($candidate->next() === $this->kind) {
                return $candidate;
            }
        }

        throw new AccountingException(AccountingError::PurchaseDocumentNotFound, 'The document has no document before it.');
    }

    /**
     * @param array{branch_id: int, location_id: int, supplier_id: int|null, document_date: string, due_date: string|null, note: string} $header
     * @param list<array{item_id: int, description: string, quantity: string, unit_price: string}>                                       $lines
     */
    private function store(array $header, array $lines, ?int $sourceId, ?int $employeeId): int
    {
        $row = [
            'company_id'    => CompanyContext::id(),
            'branch_id'     => $header['branch_id'],
            'location_id'   => $header['location_id'],
            'document_date' => $header['document_date'],
            'due_date'      => $header['due_date'],
            'status'        => PurchaseDocumentStatus::Open->value,
            'note'          => $header['note'],
            'created_by'    => $employeeId,
        ];

        if ($this->kind->hasSupplier()) {
            $row['supplier_id'] = $header['supplier_id'];
        }

        if ($sourceId !== null) {
            $row[(string) $this->kind->sourceColumn()] = $sourceId;
        }

        $this->db->table($this->kind->table())->insert($row);

        $documentId = (int) $this->db->insertID();

        $this->insertLines($documentId, $lines);
        $this->numbering->assign($this->kind->documentType(), $this->kind->table(), $documentId, $header['branch_id']);
        $this->record($documentId, 'created', $employeeId, $header['branch_id'], ['lines' => count($lines), 'source_id' => $sourceId]);

        return $documentId;
    }

    /**
     * @param list<array{item_id: int, description: string, quantity: string, unit_price: string}> $lines
     */
    private function insertLines(int $documentId, array $lines): void
    {
        foreach ($lines as $index => $line) {
            $this->db->table($this->kind->linesTable())->insert([...$line, $this->kind->idColumn() => $documentId, 'line_no' => $index + 1]);
        }
    }

    private function derive(string $stored, int $documentId): PurchaseDocumentStatus
    {
        if ($stored === PurchaseDocumentStatus::Cancelled->value) {
            return PurchaseDocumentStatus::Cancelled;
        }

        if ($this->kind === PurchaseDocumentKind::Order) {
            return $this->receipts->statusOf($stored, $documentId);
        }

        $next = $this->kind->next();

        if ($next !== null && $this->db->table($next->table())->where($next->sourceColumn(), $documentId)->where('status !=', PurchaseDocumentStatus::Cancelled->value)->countAllResults() > 0) {
            return PurchaseDocumentStatus::Converted;
        }

        return PurchaseDocumentStatus::from($stored);
    }

    private function derivedSql(): string
    {
        $id = $this->kind->idColumn();

        if ($this->kind === PurchaseDocumentKind::Order) {
            $lines    = $this->db->prefixTable($this->kind->linesTable());
            $taken    = $this->db->prefixTable('purchase_order_receiving_lines');
            $received = "COALESCE((SELECT SUM(t.quantity) FROM {$taken} t WHERE t.order_line_id = l.line_id), 0)";

            return "CASE WHEN o.status = 'cancelled' THEN 'cancelled' WHEN (SELECT COUNT(*) FROM {$lines} l WHERE l.{$id} = o.{$id}) > 0 AND NOT EXISTS (SELECT 1 FROM {$lines} l WHERE l.{$id} = o.{$id} AND {$received} < l.quantity) THEN 'received' WHEN EXISTS (SELECT 1 FROM {$lines} l WHERE l.{$id} = o.{$id} AND {$received} > 0) THEN 'partly_received' ELSE o.status END";
        }

        $next = $this->kind->next();
        $made = $this->db->prefixTable((string) $next?->table());

        return "CASE WHEN o.status = 'cancelled' THEN 'cancelled' WHEN EXISTS (SELECT 1 FROM {$made} x WHERE x.{$next?->sourceColumn()} = o.{$id} AND x.status <> 'cancelled') THEN 'converted' ELSE o.status END";
    }

    private function selected(): BaseBuilder
    {
        $builder = $this->db->table($this->kind->table() . ' o')
            ->select("o.*, o.{$this->kind->idColumn()} AS document_id, b.code AS branch_code, d.number AS document_number, TRIM(CONCAT(ep.first_name, ' ', ep.last_name)) AS creator_name, sl.location_name" . ($this->kind->hasSupplier() ? ", COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS supplier_name" : ''), false)
            ->join('branches b', 'b.branch_id = o.branch_id')
            ->join('stock_locations sl', 'sl.location_id = o.location_id', 'left')
            ->join('people ep', 'ep.person_id = o.created_by', 'left')
            ->join('document_numbers d', $this->numberJoin(), 'left')
            ->where('o.company_id', CompanyContext::id());

        if ($this->kind->hasSupplier()) {
            $builder->join('suppliers s', 's.person_id = o.supplier_id', 'left')->join('people p', 'p.person_id = o.supplier_id', 'left');
        }

        return $builder;
    }

    private function numberJoin(): string
    {
        return "d.doc_type = '" . $this->kind->documentType()->value . "' AND d.source_table = '" . $this->kind->table() . "' AND d.source_id = o." . $this->kind->idColumn();
    }

    /**
     * @param array{status?: string, supplier_id?: int, from?: string, to?: string, search?: string} $filters
     */
    private function searched(array $filters, ?int $viewerId, string $derived): BaseBuilder
    {
        $reach   = $this->reach($viewerId);
        $builder = $this->db->table($this->kind->table() . ' o')
            ->join('branches b', 'b.branch_id = o.branch_id')
            ->join('document_numbers d', $this->numberJoin(), 'left')
            ->where('o.company_id', CompanyContext::id());

        if ($this->kind->hasSupplier()) {
            $builder->join('suppliers s', 's.person_id = o.supplier_id', 'left')->join('people p', 'p.person_id = o.supplier_id', 'left');
        }

        $this->limitTo($builder, $reach, $viewerId);

        $status = (string) ($filters['status'] ?? '');

        if (PurchaseDocumentStatus::tryFrom($status) !== null) {
            $builder->where("({$derived}) = " . $this->db->escape($status), null, false);
        }

        if (! empty($filters['supplier_id']) && $this->kind->hasSupplier()) {
            $builder->where('o.supplier_id', (int) $filters['supplier_id']);
        }

        if (! empty($filters['from'])) {
            $builder->where('o.document_date >=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $builder->where('o.document_date <=', $filters['to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()->like('d.number', $search);

            if ($this->kind->hasSupplier()) {
                $builder->orLike('s.company_name', $search)->orLike("CONCAT(p.first_name, ' ', p.last_name)", $search);
            }

            $builder->groupEnd();
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
     * Keeps the documents of the branches the viewer works in, and of the viewer alone when they may not see every document.
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
     * Locks the row of the document, for one that exists and that the viewer may see: in a branch they work in and, when they may not see
     * every document, made by them. Anything else is a document that does not exist for them.
     *
     * @throws AccountingException
     */
    private function lock(int $documentId, ?int $employeeId): object
    {
        $document = LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable($this->kind->table()) . ' WHERE ' . $this->kind->idColumn() . ' = ? FOR UPDATE', [$documentId]);

        if ($document === null || (int) $document->company_id !== CompanyContext::id() || ! $this->branches->canAccess((int) $document->branch_id, $employeeId) || (! $this->mayViewAll($employeeId) && (int) $document->created_by !== $employeeId)) {
            throw new AccountingException(AccountingError::PurchaseDocumentNotFound, 'There is no such document.', ['name' => (string) $documentId]);
        }

        return $document;
    }

    /**
     * @throws AccountingException
     */
    private function assertOn(): void
    {
        if (! $this->settings->isEnabled()) {
            throw new AccountingException(AccountingError::PurchaseDocumentModuleOff, 'The purchase cycle is switched off.');
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{branch_id: int, location_id: int, supplier_id: int|null, document_date: string, due_date: string|null, note: string}
     *
     * @throws AccountingException|BranchException
     */
    private function header(array $input, ?int $employeeId, string $today): array
    {
        $date                    = $this->day($input['document_date'] ?? null, $today);
        $due                     = $this->optionalDay($input['due_date'] ?? null);
        [$locationId, $branchId] = $this->location($input['location_id'] ?? 0, $employeeId);

        if ($due !== null && $due < $date) {
            throw new AccountingException(AccountingError::PurchaseDocumentDateInvalid, "The date {$due} is before the date of the document.", ['date' => $due]);
        }

        return [
            'branch_id'     => $branchId,
            'location_id'   => $locationId,
            'supplier_id'   => $this->kind->hasSupplier() ? $this->optionalSupplier($input['supplier_id'] ?? null) : null,
            'document_date' => $date,
            'due_date'      => $due,
            'note'          => $this->noteText($input['note'] ?? ''),
        ];
    }

    /**
     * The supplier of the document: required for a quotation and an order, optional on a quotation request.
     *
     * @throws AccountingException
     */
    private function optionalSupplier(mixed $value): ?int
    {
        $supplierId = (int) $value;

        if ($supplierId <= 0) {
            if ($this->kind->needsSupplier()) {
                throw new AccountingException(AccountingError::PurchaseDocumentSupplierNeeded, 'The document needs a supplier.');
            }

            return null;
        }

        return $this->supplier($supplierId);
    }

    /**
     * @throws AccountingException
     */
    private function supplier(int $supplierId): int
    {
        if ($this->db->table('suppliers')->where(['person_id' => $supplierId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::PurchaseDocumentSupplierInvalid, 'The supplier does not exist.', ['name' => (string) $supplierId]);
        }

        return $supplierId;
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
            throw new AccountingException(AccountingError::PurchaseDocumentLocationInvalid, 'The stock location does not exist.', ['name' => (string) $locationId]);
        }

        $this->branches->requireBranch((int) $row->branch_id, $employeeId);

        return [(int) $row->location_id, (int) $row->branch_id];
    }

    /**
     * @throws AccountingException
     */
    private function day(mixed $value, string $default): string
    {
        return $this->optionalDay($value) ?? $default;
    }

    /**
     * @throws AccountingException
     */
    private function optionalDay(mixed $value): ?string
    {
        $text = trim(is_string($value) || is_int($value) ? (string) $value : '');

        if ($text === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($parsed === false || $parsed->format('Y-m-d') !== $text) {
            throw new AccountingException(AccountingError::PurchaseDocumentDateInvalid, "The date {$text} is not a day.", ['date' => $text]);
        }

        return $text;
    }

    /**
     * @throws AccountingException
     */
    private function noteText(mixed $value): string
    {
        $text = TextInput::of($value, AccountingError::DescriptionTooLong);

        if (mb_strlen($text) > self::NOTE_MAX) {
            throw new AccountingException(AccountingError::DescriptionTooLong, 'The note is too long.', ['max' => self::NOTE_MAX]);
        }

        return $text;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(int $documentId, string $action, ?int $employeeId, int $branchId, array $details): void
    {
        $this->audit->write($this->kind->value, $documentId, $action, $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, $details, $branchId);
    }
}
