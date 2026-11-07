<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * A stock request: a location asks another location for stock. It posts nothing and moves nothing. It
 * is requested by someone who works in the branch of the location that asks, approved by someone who
 * works in the branch of the location that gives, and fulfilled by making the transfer permit that
 * moves the stock, so the permit carries every rule and every check of a transfer. A request is
 * cancelled while it is requested or approved, and what has been fulfilled stays so: the transfer is
 * cancelled on its permit. It works only while its setting is on, and so does the transfer permit it
 * needs. How the reference system switches requests on, and the states it gives them, were not
 * observed, so the settings, the states and the roles are an assumption of the design.
 */
final class StockRequest
{
    use RunsInTransaction;

    public const NOTE_MAX   = 500;
    public const REASON_MAX = 255;
    public const LINES_MAX  = 200;
    private const QUANTITY  = '/^\d{1,9}(\.\d{1,3})?$/';
    private const ENTITY    = 'stock_request';

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private StockDocumentSettings $settings;
    private StockAccess $access;
    private AuditLog $audit;
    private DocumentNumbering $numbering;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->guard     = $guard ?? new AccountingGuard();
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->settings  = new StockDocumentSettings($this->db, $this->guard);
        $this->access    = new StockAccess($this->db, $this->branches, $this->guard);
        $this->audit     = new AuditLog($this->db);
        $this->numbering = new DocumentNumbering($this->db);
    }

    /**
     * Asks the location that gives for stock for the location that asks.
     *
     * @param array{from_location_id: int, to_location_id: int, date?: string, note?: string, lines: list<array{item_id: int|string, quantity: string}>} $input
     *
     * @throws AccountingException|BranchException
     */
    public function create(array $input, int $employeeId): int
    {
        $this->guard->require(StockPermissions::REQUESTS_MANAGE, $employeeId);
        $this->assertOn();

        $from = $this->access->location((int) ($input['from_location_id'] ?? 0));
        $to   = $this->access->location((int) ($input['to_location_id'] ?? 0));

        if ((int) $from->location_id === (int) $to->location_id) {
            throw new AccountingException(AccountingError::StockRequestLocationsInvalid, 'A request goes from one stock location to another.', ['location_id' => (int) $to->location_id]);
        }

        $this->branches->requireBranch((int) $to->branch_id, $employeeId);

        $date  = $this->day((string) ($input['date'] ?? date('Y-m-d')));
        $note  = $this->note((string) ($input['note'] ?? ''));
        $lines = $this->lineList($input['lines'] ?? []);

        return $this->transactional(function () use ($from, $to, $date, $note, $lines, $employeeId): int {
            $this->db->table('stock_requests')->insert([
                'company_id'       => CompanyContext::id(),
                'branch_id'        => (int) $to->branch_id,
                'from_branch_id'   => (int) $from->branch_id,
                'from_location_id' => (int) $from->location_id,
                'to_location_id'   => (int) $to->location_id,
                'request_date'     => $date,
                'note'             => $note,
                'created_by'       => $employeeId,
            ]);

            $requestId = (int) $this->db->insertID();
            $rows      = [];

            foreach ($lines as $index => $line) {
                $rows[] = ['request_id' => $requestId, 'line_no' => $index + 1, 'item_id' => $line['item_id'], 'quantity' => $line['quantity']];
            }

            $this->db->table('stock_request_lines')->insertBatch($rows);
            $this->numbering->assign(DocumentType::StockRequest, 'stock_requests', $requestId, (int) $to->branch_id);
            $this->audit->write(self::ENTITY, $requestId, 'stock_request_created', $employeeId, JournalOrigin::User, ['from' => (int) $from->location_id, 'to' => (int) $to->location_id, 'lines' => $lines], (int) $to->branch_id);

            return $requestId;
        });
    }

    /**
     * Approves a request that was made, for someone who works in the branch of the location that gives.
     *
     * @throws AccountingException|BranchException
     */
    public function approve(int $requestId, int $employeeId): void
    {
        $this->guard->require(StockPermissions::REQUESTS_APPROVE, $employeeId);
        $this->assertOn();

        $this->transactional(function () use ($requestId, $employeeId): void {
            $request = $this->standing($requestId, 'requested');

            $this->branches->requireBranch((int) $request->from_branch_id, $employeeId);

            $this->db->table('stock_requests')->where('request_id', $requestId)->update(['status' => 'approved', 'approved_by' => $employeeId, 'approved_at' => date('Y-m-d H:i:s')]);
            $this->audit->write(self::ENTITY, $requestId, 'stock_request_approved', $employeeId, JournalOrigin::User, [], (int) $request->branch_id);
        });
    }

    /**
     * Fulfils an approved request by making the transfer permit that moves its stock, today, from the
     * location that gives to the location that asks. The permit is checked as any transfer permit is,
     * and a refusal leaves the request approved.
     *
     * @return int The transfer permit.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function fulfil(int $requestId, int $employeeId): int
    {
        $this->guard->require(StockPermissions::REQUESTS_APPROVE, $employeeId);
        $this->assertOn();

        $items = array_map(static fn (object $line): int => (int) $line->item_id, $this->lines($requestId));

        return $this->transactional(function () use ($requestId, $employeeId, $items): int {
            (new StockShelves($this->db))->lockItems($items);

            $request = $this->standing($requestId, 'approved');

            $this->branches->requireBranch((int) $request->from_branch_id, $employeeId);

            $permitId = (new StockPermit($this->db, $this->guard, $this->branches))->create([
                'type'           => StockPermitType::Transfer->value,
                'date'           => date('Y-m-d'),
                'location_id'    => (int) $request->from_location_id,
                'to_location_id' => (int) $request->to_location_id,
                'note'           => 'Stock request #' . $this->numberOf($requestId),
                'request_id'     => $requestId,
                'lines'          => array_map(static fn (object $line): array => ['item_id' => (int) $line->item_id, 'quantity' => bcadd((string) $line->quantity, '0', 3)], $this->lines($requestId)),
            ], $employeeId);

            $this->db->table('stock_requests')->where('request_id', $requestId)->update(['status' => 'fulfilled', 'permit_id' => $permitId, 'fulfilled_by' => $employeeId, 'fulfilled_at' => date('Y-m-d H:i:s')]);
            $this->audit->write(self::ENTITY, $requestId, 'stock_request_fulfilled', $employeeId, JournalOrigin::User, ['permit_id' => $permitId], (int) $request->branch_id);

            return $permitId;
        });
    }

    /**
     * Cancels a request that is requested or approved, with a reason.
     *
     * @throws AccountingException|BranchException
     */
    public function cancel(int $requestId, string $reason, int $employeeId): void
    {
        if (! $this->guard->has(StockPermissions::REQUESTS_MANAGE, $employeeId)) {
            $this->guard->require(StockPermissions::REQUESTS_APPROVE, $employeeId);
        }

        $reason = $this->reason($reason);

        $this->transactional(function () use ($requestId, $reason, $employeeId): void {
            $table   = $this->db->prefixTable('stock_requests');
            $request = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE request_id = ? AND company_id = ? FOR UPDATE", [$requestId, CompanyContext::id()]);

            if ($request === null) {
                throw new AccountingException(AccountingError::StockRequestNotFound, "Stock request {$requestId} does not exist.", ['request_id' => $requestId]);
            }

            if (! in_array($request->status, ['requested', 'approved'], true)) {
                throw new AccountingException(AccountingError::StockRequestStatusInvalid, 'Only a request that was made or approved is cancelled.', ['request_id' => $requestId]);
            }

            if (! $this->branches->canAccess((int) $request->branch_id, $employeeId)) {
                $this->branches->requireBranch((int) $request->from_branch_id, $employeeId);
            }

            $this->db->table('stock_requests')->where('request_id', $requestId)->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->audit->write(self::ENTITY, $requestId, 'stock_request_cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason, 'was' => $request->status], (int) $request->branch_id);
        });
    }

    public function find(int $requestId): ?object
    {
        return $this->db->table('stock_requests r')
            ->select('r.*, b.code AS branch_code, fb.code AS from_branch_code, fl.location_name AS from_location_name, tl.location_name AS to_location_name, d.number AS document_number', false)
            ->join('branches b', 'b.branch_id = r.branch_id')
            ->join('branches fb', 'fb.branch_id = r.from_branch_id')
            ->join('stock_locations fl', 'fl.location_id = r.from_location_id')
            ->join('stock_locations tl', 'tl.location_id = r.to_location_id')
            ->join('document_numbers d', "d.doc_type = '" . DocumentType::StockRequest->value . "' AND d.source_table = 'stock_requests' AND d.source_id = r.request_id", 'left')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.request_id', $requestId)
            ->get()
            ->getRow();
    }

    /**
     * @return list<object>
     */
    public function lines(int $requestId): array
    {
        return $this->db->table('stock_request_lines l')
            ->select('l.line_no, l.item_id, l.quantity, i.name AS item_name, i.item_number')
            ->join('items i', 'i.item_id = l.item_id')
            ->where('l.request_id', $requestId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The requests of the branches the employee works in, the newest first. A request belongs to the
     * branch that asks and to the branch that gives.
     *
     * @param array{status?: string, search?: string, from?: string, to?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, ?int $employeeId, int $limit = 25, int $offset = 0): array
    {
        $total = $this->searched($filters, $employeeId)->countAllResults();
        $rows  = $this->searched($filters, $employeeId)
            ->select('r.*, b.code AS branch_code, fb.code AS from_branch_code, fl.location_name AS from_location_name, tl.location_name AS to_location_name, d.number AS document_number', false)
            ->orderBy('r.request_date', 'DESC')
            ->orderBy('r.request_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $row->label = $row->document_number ?? DocumentType::StockRequest->fallback((int) $row->request_id);
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @param array{status?: string, search?: string, from?: string, to?: string} $filters
     */
    private function searched(array $filters, ?int $employeeId): BaseBuilder
    {
        $branchIds = $this->branches->allowedBranchIds($employeeId);

        $builder = $this->db->table('stock_requests r')
            ->join('branches b', 'b.branch_id = r.branch_id')
            ->join('branches fb', 'fb.branch_id = r.from_branch_id')
            ->join('stock_locations fl', 'fl.location_id = r.from_location_id')
            ->join('stock_locations tl', 'tl.location_id = r.to_location_id')
            ->join('document_numbers d', "d.doc_type = '" . DocumentType::StockRequest->value . "' AND d.source_table = 'stock_requests' AND d.source_id = r.request_id", 'left')
            ->where('r.company_id', CompanyContext::id());

        if ($branchIds !== null) {
            if ($branchIds === []) {
                $builder->where('1 = 0', null, false);
            } else {
                $builder->groupStart()->whereIn('r.branch_id', $branchIds)->orWhereIn('r.from_branch_id', $branchIds)->groupEnd();
            }
        }

        if (in_array($filters['status'] ?? '', ['requested', 'approved', 'fulfilled', 'cancelled'], true)) {
            $builder->where('r.status', $filters['status']);
        }

        if (! empty($filters['from'])) {
            $builder->where('r.request_date >=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $builder->where('r.request_date <=', $filters['to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $labelled = StockDocumentSearch::labelled($search, [DocumentType::StockRequest]);

            $builder->groupStart()->like('d.number', $search)->orLike('r.note', $search);

            if ($labelled !== null) {
                $builder->orGroupStart()->where('r.request_id', $labelled['id'])->where('d.number IS NULL', null, false)->groupEnd();
            }

            $builder->groupEnd();
        }

        return $builder;
    }

    public function numberOf(int $requestId): string
    {
        return $this->numbering->label(DocumentType::StockRequest, 'stock_requests', $requestId);
    }

    public function permitNumberOf(int $permitId): string
    {
        return $this->numbering->label(DocumentType::StockPermitTransfer, 'stock_permits', $permitId);
    }

    public function peekNumber(): ?string
    {
        return $this->numbering->peek(DocumentType::StockRequest);
    }

    /**
     * The request as it stands now, read under a lock, in the status it must be in for the change.
     *
     * @throws AccountingException
     */
    private function standing(int $requestId, string $status): object
    {
        $table   = $this->db->prefixTable('stock_requests');
        $request = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE request_id = ? AND company_id = ? FOR UPDATE", [$requestId, CompanyContext::id()]);

        if ($request === null) {
            throw new AccountingException(AccountingError::StockRequestNotFound, "Stock request {$requestId} does not exist.", ['request_id' => $requestId]);
        }

        if ($request->status !== $status) {
            throw new AccountingException(AccountingError::StockRequestStatusInvalid, "The request is {$request->status}, not {$status}.", ['request_id' => $requestId]);
        }

        return $request;
    }

    /**
     * @throws AccountingException
     */
    private function assertOn(): void
    {
        if (! $this->settings->requestsEnabled()) {
            throw new AccountingException(AccountingError::StockDocumentOff, 'Stock requests are not turned on.');
        }
    }

    /**
     * @return list<array{item_id: int, quantity: string}>
     *
     * @throws AccountingException
     */
    private function lineList(mixed $lines): array
    {
        if (! is_array($lines) || $lines === [] || count($lines) > self::LINES_MAX) {
            throw new AccountingException(AccountingError::StockRequestLinesInvalid, 'A request has from one to 200 lines.', ['line' => 0]);
        }

        $list = [];

        foreach (array_values($lines) as $index => $line) {
            $number   = $index + 1;
            $itemId   = is_array($line) ? (int) ($line['item_id'] ?? 0) : 0;
            $quantity = is_array($line) ? trim((string) ($line['quantity'] ?? '')) : '';

            if (preg_match(self::QUANTITY, $quantity) !== 1 || bccomp($quantity, '0', 3) <= 0) {
                throw new AccountingException(AccountingError::StockRequestLinesInvalid, "The quantity of line {$number} must be more than zero.", ['line' => $number]);
            }

            if ($itemId <= 0 || $this->db->table('items')->where(['item_id' => $itemId, 'deleted' => 0, 'stock_type' => HAS_STOCK])->countAllResults() === 0) {
                throw new AccountingException(AccountingError::StockRequestLinesInvalid, "Line {$number} is not an item that keeps stock.", ['line' => $number]);
            }

            $list[] = ['item_id' => $itemId, 'quantity' => bcadd($quantity, '0', 3)];
        }

        return $list;
    }

    /**
     * @throws AccountingException
     */
    private function day(string $date): string
    {
        $date = trim($date);
        $day  = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        if ($date > date('Y-m-d')) {
            throw new AccountingException(AccountingError::InvalidDate, "A request is dated up to today, not {$date}.", ['date' => $date]);
        }

        return $date;
    }

    /**
     * @throws AccountingException
     */
    private function note(string $note): string
    {
        $note = trim($note);

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new AccountingException(AccountingError::StockPermitNoteTooLong, 'The note is longer than 500 characters.', ['max' => self::NOTE_MAX]);
        }

        return $note;
    }

    /**
     * @throws AccountingException
     */
    private function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        return $reason;
    }
}
