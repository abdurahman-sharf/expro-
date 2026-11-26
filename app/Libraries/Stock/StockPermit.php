<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AccountType;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\InterBranchLegs;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PeriodGuard;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureLog;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockPostingPlan;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Models\Item;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\OSPOS;
use DateTimeImmutable;

/**
 * The numbered stock permits. An add permit brings units into a stock location, an issue permit takes
 * them out, and a transfer permit moves them from one stock location to another, in the same branch or
 * in two. Each is a document with a number of its own, a date, a note, lines of an item and a quantity
 * (and a cost on an add permit), and, on add and issue permits, an optional supplier or customer that
 * is only written on it and an optional account to set it against instead of the automatic one.
 *
 * Saving a permit moves the shelves and writes the inventory record. When the books follow the permit it
 * also posts the valued stock movements and the journal through the builders that already post stock,
 * in the same transaction: nothing is posted for a permit the books do not follow, which is every permit
 * while accounting is off and a permit dated before accounting starts. A posted permit is never changed
 * or deleted: it is cancelled by reversing its journal and its movements, or corrected by reversing them
 * and posting the corrected lines as the next revision. The shelves follow, and a change that would leave
 * a stock location with less than nothing of an item is refused, which is how a receiving is corrected.
 *
 * No treasury is involved and no party account is touched. The add and issue permits are always
 * available; the transfer permit works only while its setting is on.
 */
final class StockPermit
{
    use RunsInTransaction;

    public const NOTE_MAX   = 500;
    public const REASON_MAX = 255;
    public const LINES_MAX  = 200;
    private const QUANTITY  = '/^\d{1,9}(\.\d{1,3})?$/';
    private const COST      = '/^\d{1,9}(\.\d{1,6})?$/';
    private const ENTITY    = 'stock_permit';
    private const COMMENT   = 'STKP ';
    private const AUTOMATIC = ['other_payables', 'other_receivables'];

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AccountingSettings $settings;
    private StockDocumentSettings $stockSettings;
    private StockShelves $shelves;
    private StockAccess $access;
    private PeriodGuard $periods;
    private PostingService $posting;
    private JournalService $journals;
    private StockLedger $ledger;
    private StockPermitBuilder $builder;
    private InterBranchLegs $legs;
    private RoutingResolver $resolver;
    private AuditLog $audit;
    private DocumentNumbering $numbering;
    private UnitLines $units;
    private StockTracking $tracking;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db            = $db ?? Database::connect();
        $this->guard         = $guard ?? new AccountingGuard();
        $this->branches      = $branches ?? new BranchGuard($this->db);
        $this->settings      = new AccountingSettings($this->db);
        $this->stockSettings = new StockDocumentSettings($this->db, $this->guard);
        $this->shelves       = new StockShelves($this->db);
        $this->access        = new StockAccess($this->db, $this->branches, $this->guard);
        $this->periods       = new PeriodGuard($this->db);
        $this->posting       = new PostingService($this->db);
        $this->journals      = new JournalService($this->db);
        $this->ledger        = new StockLedger($this->db);
        $this->resolver      = new RoutingResolver($this->db);
        $this->builder       = new StockPermitBuilder($this->resolver, $this->ledger, new ReceivingPostingBuilder($this->resolver, $this->ledger));
        $this->legs          = new InterBranchLegs($this->db);
        $this->audit         = new AuditLog($this->db);
        $this->numbering     = new DocumentNumbering($this->db);
        $this->units         = new UnitLines($this->db);
        $this->tracking      = new StockTracking($this->db, $this->guard);
    }

    /**
     * Saves a permit: the shelves change and, when the books follow it, its movements and its journal
     * are posted.
     *
     * @param array{type: string, date: string, location_id: int, to_location_id?: int|null, supplier_id?: int|null, customer_id?: int|null, account_id?: int|null, note?: string, request_id?: int|null, lines: list<array{item_id: int|string, quantity: string, unit_cost?: string}>} $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function create(array $input, int $employeeId): int
    {
        $this->guard->require(StockPermissions::PERMITS_MANAGE, $employeeId);

        $data            = $this->checked($input, $employeeId);
        $ownsTransaction = $this->db->transDepth === 0;

        foreach (array_filter([$data['location_id'], $data['to_location_id']]) as $locationId) {
            $this->access->assertActive((int) $locationId);
        }

        try {
            return $this->transactional(function () use ($data, $employeeId): int {
                $this->shelves->lockItems(array_column($data['lines'], 'item_id'));

                $this->db->table('stock_permits')->insert([
                    'company_id'     => CompanyContext::id(),
                    'branch_id'      => $data['branch_id'],
                    'to_branch_id'   => $data['to_branch_id'],
                    'permit_type'    => $data['type']->value,
                    'permit_date'    => $data['date'],
                    'location_id'    => $data['location_id'],
                    'to_location_id' => $data['to_location_id'],
                    'supplier_id'    => $data['supplier_id'],
                    'customer_id'    => $data['customer_id'],
                    'account_id'     => $data['account_id'],
                    'note'           => $data['note'],
                    'request_id'     => $data['request_id'],
                    'created_by'     => $employeeId,
                ]);

                $permitId = (int) $this->db->insertID();

                $this->writeLines($permitId, $data['lines']);
                $this->numbering->assign($data['type']->documentType(), 'stock_permits', $permitId, $data['branch_id']);

                $booked = $this->put($permitId, $data, 1, $employeeId);

                $this->db->table('stock_permits')->where('permit_id', $permitId)->update(['booked' => $booked ? 1 : 0]);
                $this->audit->write(self::ENTITY, $permitId, 'stock_permit_created', $employeeId, JournalOrigin::User, $this->details($data), $data['branch_id']);

                return $permitId;
            });
        } catch (PostingException $failure) {
            if ($ownsTransaction) {
                (new PostingFailureLog($this->db))->recordUnbuilt(self::source($data['type']), null, 1, JournalEntryType::General, $failure, $employeeId, $data['branch_id']);
            }

            throw $failure;
        }
    }

    /**
     * Corrects a permit that stands: its date, its party, its account, its note and its lines. What
     * was posted is reversed and the permit is posted again as the next revision, and the shelves
     * change by the difference. The type and the stock locations of a permit do not change.
     *
     * @param array{date?: string, supplier_id?: int|null, customer_id?: int|null, account_id?: int|null, note?: string, lines: list<array{item_id: int|string, quantity: string, unit_cost?: string}>} $input
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function update(int $permitId, array $input, int $employeeId): void
    {
        $this->guard->require(StockPermissions::PERMITS_MANAGE, $employeeId);

        $ownsTransaction = $this->db->transDepth === 0;
        $type            = null;
        $branchId        = null;
        $items           = [...$this->itemsOf($permitId), ...$this->itemsTyped($input['lines'] ?? [])];

        try {
            $this->transactional(function () use ($permitId, $input, $employeeId, $items, &$type, &$branchId): void {
                $this->shelves->lockItems($items);

                $permit   = $this->standing($permitId);
                $type     = StockPermitType::from((string) $permit->permit_type);
                $branchId = (int) $permit->branch_id;
                $before   = $this->documentOf($permit);
                $data     = $this->checked([
                    'type'           => $permit->permit_type,
                    'date'           => $input['date'] ?? $permit->permit_date,
                    'location_id'    => (int) $permit->location_id,
                    'to_location_id' => $permit->to_location_id === null ? null : (int) $permit->to_location_id,
                    'supplier_id'    => array_key_exists('supplier_id', $input) ? $input['supplier_id'] : $permit->supplier_id,
                    'customer_id'    => array_key_exists('customer_id', $input) ? $input['customer_id'] : $permit->customer_id,
                    'account_id'     => array_key_exists('account_id', $input) ? $input['account_id'] : $permit->account_id,
                    'note'           => $input['note'] ?? (string) $permit->note,
                    'lines'          => $input['lines'] ?? [],
                ], $employeeId);

                $this->assertLocked($items, [...array_column($data['lines'], 'item_id'), ...array_map(static fn (StockPermitLine $line): int => $line->itemId, $before->lines)]);
                $this->shelves->assertStays([...$this->deltas($before, -1), ...$this->deltas($this->documentFrom($permitId, $data, $before->label), 1)]);

                $this->takeBack($permit, $before, 'Stock permit #' . $before->label . ' edited', $employeeId);

                $revision = (int) $permit->revision + 1;

                $this->db->table('stock_permit_lines')->where('permit_id', $permitId)->delete();
                $this->writeLines($permitId, $data['lines']);

                $booked = $this->put($permitId, $data, $revision, $employeeId);

                $this->db->table('stock_permits')->where('permit_id', $permitId)->update([
                    'permit_date' => $data['date'],
                    'supplier_id' => $data['supplier_id'],
                    'customer_id' => $data['customer_id'],
                    'account_id'  => $data['account_id'],
                    'note'        => $data['note'],
                    'booked'      => $booked ? 1 : 0,
                    'revision'    => $revision,
                    'updated_by'  => $employeeId,
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);

                $this->audit->write(self::ENTITY, $permitId, 'stock_permit_edited', $employeeId, JournalOrigin::User, [
                    'before' => $this->linesOf($before),
                    'after'  => array_map(static fn (array $line): array => ['item_id' => $line['item_id'], 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost']], $data['lines']),
                    'date'   => $data['date'],
                ], $branchId);
            });
        } catch (PostingException $failure) {
            if ($ownsTransaction && $type !== null) {
                (new PostingFailureLog($this->db))->recordUnbuilt(self::source($type), $permitId, 1, JournalEntryType::General, $failure, $employeeId, $branchId);
            }

            throw $failure;
        }
    }

    /**
     * Takes a permit back, with a reason: its journal and its movements are reversed, on their own day
     * or today when that day is in a closed period, and the shelves change back. Nothing is deleted.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $permitId, string $reason, int $employeeId): void
    {
        $this->guard->require(StockPermissions::PERMITS_MANAGE, $employeeId);

        $reason = $this->reason($reason);

        $items = $this->itemsOf($permitId);

        $this->transactional(function () use ($permitId, $reason, $employeeId, $items): void {
            $this->shelves->lockItems($items);

            $permit   = $this->standing($permitId);
            $document = $this->documentOf($permit);

            $this->assertLocked($items, array_map(static fn (StockPermitLine $line): int => $line->itemId, $document->lines));

            foreach (array_filter([$document->locationId, $document->toLocationId]) as $locationId) {
                $this->access->assertMayChange($locationId, $employeeId);
            }

            $this->shelves->assertStays($this->deltas($document, -1));
            $this->takeBack($permit, $document, $reason, $employeeId);

            $this->db->table('stock_permits')->where('permit_id', $permitId)->update([
                'status'        => 'cancelled',
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->audit->write(self::ENTITY, $permitId, 'stock_permit_cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason, 'lines' => $this->linesOf($document)], (int) $permit->branch_id);
        });
    }

    public function find(int $permitId): ?object
    {
        return $this->db->table('stock_permits p')
            ->select("p.*, b.code AS branch_code, tb.code AS to_branch_code, l.location_name, tl.location_name AS to_location_name, a.code AS account_code, a.name AS account_name, TRIM(CONCAT(sp.first_name, ' ', sp.last_name)) AS supplier_name, TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS customer_name, d.number AS document_number", false)
            ->join('branches b', 'b.branch_id = p.branch_id')
            ->join('branches tb', 'tb.branch_id = p.to_branch_id', 'left')
            ->join('stock_locations l', 'l.location_id = p.location_id')
            ->join('stock_locations tl', 'tl.location_id = p.to_location_id', 'left')
            ->join('accounting_accounts a', 'a.account_id = p.account_id', 'left')
            ->join('people sp', 'sp.person_id = p.supplier_id', 'left')
            ->join('people cp', 'cp.person_id = p.customer_id', 'left')
            ->join('document_numbers d', "d.source_table = 'stock_permits' AND d.source_id = p.permit_id AND d.doc_type IN (" . $this->numberedAs() . ')', 'left')
            ->where('p.company_id', CompanyContext::id())
            ->where('p.permit_id', $permitId)
            ->get()
            ->getRow();
    }

    /**
     * @return list<object>
     */
    public function lines(int $permitId): array
    {
        return $this->db->table('stock_permit_lines l')
            ->select('l.line_no, l.item_id, l.quantity, l.unit_cost, l.unit_id, l.unit_factor, l.unit_quantity, l.unit_cost_entered, i.name AS item_name, i.item_number')
            ->join('items i', 'i.item_id = l.item_id')
            ->where('l.permit_id', $permitId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The permits of the branches the employee works in, the newest first. A transfer between two
     * branches belongs to both.
     *
     * @param array{status?: string, type?: string, search?: string, from?: string, to?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, ?int $employeeId, int $limit = 25, int $offset = 0): array
    {
        $total = $this->searched($filters, $employeeId)->countAllResults();
        $rows  = $this->searched($filters, $employeeId)
            ->select('p.*, b.code AS branch_code, tb.code AS to_branch_code, l.location_name, tl.location_name AS to_location_name, d.number AS document_number', false)
            ->orderBy('p.permit_date', 'DESC')
            ->orderBy('p.permit_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $row->label = $row->document_number ?? StockPermitType::from((string) $row->permit_type)->documentType()->fallback((int) $row->permit_id);
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @param array{status?: string, type?: string, search?: string, from?: string, to?: string} $filters
     */
    private function searched(array $filters, ?int $employeeId): BaseBuilder
    {
        $branchIds = $this->branches->allowedBranchIds($employeeId);

        $builder = $this->db->table('stock_permits p')
            ->join('branches b', 'b.branch_id = p.branch_id')
            ->join('branches tb', 'tb.branch_id = p.to_branch_id', 'left')
            ->join('stock_locations l', 'l.location_id = p.location_id')
            ->join('stock_locations tl', 'tl.location_id = p.to_location_id', 'left')
            ->join('document_numbers d', "d.source_table = 'stock_permits' AND d.source_id = p.permit_id AND d.doc_type IN (" . $this->numberedAs() . ')', 'left')
            ->where('p.company_id', CompanyContext::id());

        if ($branchIds !== null) {
            if ($branchIds === []) {
                $builder->where('1 = 0', null, false);
            } else {
                $builder->groupStart()->whereIn('p.branch_id', $branchIds)->orWhereIn('p.to_branch_id', $branchIds)->groupEnd();
            }
        }

        if (in_array($filters['status'] ?? '', ['posted', 'cancelled'], true)) {
            $builder->where('p.status', $filters['status']);
        }

        if (StockPermitType::tryFrom((string) ($filters['type'] ?? '')) !== null) {
            $builder->where('p.permit_type', $filters['type']);
        }

        if (! empty($filters['from'])) {
            $builder->where('p.permit_date >=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $builder->where('p.permit_date <=', $filters['to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()->like('d.number', $search)->orLike('p.note', $search);

            $labelled = StockDocumentSearch::labelled($search, array_map(static fn (StockPermitType $type): DocumentType => $type->documentType(), StockPermitType::cases()));

            if ($labelled !== null) {
                $builder->orGroupStart()->where('p.permit_id', $labelled['id'])->where('d.number IS NULL', null, false);

                if ($labelled['type'] !== null) {
                    $builder->where('p.permit_type', array_values(array_filter(StockPermitType::cases(), static fn (StockPermitType $type): bool => $type->documentType() === $labelled['type']))[0]->value);
                }

                $builder->groupEnd();
            }

            $builder->groupEnd();
        }

        return $builder;
    }

    /**
     * The journals a permit has posted, oldest first, those it took back included.
     *
     * @return list<object>
     */
    public function journals(int $permitId): array
    {
        return $this->db->table('accounting_journals')
            ->select('journal_id, number, journal_date, status, source_type, revision, total_debit')
            ->where('company_id', CompanyContext::id())
            ->whereIn('source_type', [StockPermitSources::PERMIT, StockPermitSources::TRANSFER, StockPermitSources::TRANSFER_RECEIPT])
            ->where('source_id', $permitId)
            ->orderBy('journal_id', 'ASC')
            ->get()
            ->getResult();
    }

    private function numberedAs(): string
    {
        return implode(', ', array_map(fn (StockPermitType $type): string => $this->db->escape($type->documentType()->value), StockPermitType::cases()));
    }

    public function numberOf(int $permitId): string
    {
        $type = $this->db->table('stock_permits')->select('permit_type')->where('permit_id', $permitId)->get()->getRow();

        return $this->numbering->label(StockPermitType::from((string) ($type->permit_type ?? 'add'))->documentType(), 'stock_permits', $permitId);
    }

    public function peekNumber(StockPermitType $type): ?string
    {
        return $this->numbering->peek($type->documentType());
    }

    /**
     * The accounts a permit may be set against instead of its automatic account: open accounts that
     * take postings, belong to no customer, supplier, stock location or treasury, and are of a kind that
     * fits the side of the permit. An account of the chart that the routing owns is left out, apart from
     * the automatic account of the permit itself.
     *
     * @return list<object>
     */
    public function allowedAccounts(StockPermitType $type): array
    {
        $types = array_map(static fn (AccountType $accountType): string => $accountType->value, $type->accountTypes());

        if ($types === []) {
            return [];
        }

        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('company_id', CompanyContext::id())
            ->where(['is_group' => 0, 'active' => 1])
            ->where('entity_type', null)
            ->whereIn('account_type', $types)
            ->groupStart()->where('system_key', null)->orWhereIn('system_key', self::AUTOMATIC)->groupEnd()
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Everything a permit says, checked, before anything is written.
     *
     * @param array<string, mixed> $input
     *
     * @return array{type: StockPermitType, date: string, location_id: int, to_location_id: ?int, branch_id: int, to_branch_id: ?int, supplier_id: ?int, customer_id: ?int, account_id: ?int, note: string, request_id: ?int, lines: list<array{item_id: int, quantity: string, unit_cost: string}>}
     *
     * @throws AccountingException|BranchException
     */
    private function checked(array $input, int $employeeId): array
    {
        $type = StockPermitType::tryFrom((string) ($input['type'] ?? ''));

        if ($type === null) {
            throw new AccountingException(AccountingError::StockPermitTypeInvalid, 'A stock permit is an add permit, an issue permit or a transfer.');
        }

        if ($type === StockPermitType::Transfer && ! $this->stockSettings->transferPermitEnabled()) {
            throw new AccountingException(AccountingError::StockDocumentOff, 'The transfer permit is not turned on.');
        }

        $date   = $this->day((string) ($input['date'] ?? ''));
        $source = $this->access->assertMayChange((int) ($input['location_id'] ?? 0), $employeeId);

        $toLocationId = $input['to_location_id'] ?? null;
        $toBranchId   = null;

        if ($type === StockPermitType::Transfer) {
            $toLocationId = (int) $toLocationId;

            if ($toLocationId === (int) $source->location_id) {
                throw new AccountingException(AccountingError::StockPermitLocationInvalid, 'A transfer goes from one stock location to another.', ['location_id' => $toLocationId]);
            }

            $destination = $this->access->assertMayChange($toLocationId, $employeeId);
            $toBranchId  = (int) $destination->branch_id === (int) $source->branch_id ? null : (int) $destination->branch_id;
        } elseif ($toLocationId !== null && (int) $toLocationId !== 0) {
            throw new AccountingException(AccountingError::StockPermitLocationInvalid, 'Only a transfer names a second stock location.', ['location_id' => (int) $toLocationId]);
        } else {
            $toLocationId = null;
        }

        $supplierId = $this->party($input['supplier_id'] ?? null, 'suppliers', $type === StockPermitType::Add);
        $customerId = $this->party($input['customer_id'] ?? null, 'customers', $type === StockPermitType::Issue);
        $accountId  = $this->account($input['account_id'] ?? null, $type);
        $note       = trim((string) ($input['note'] ?? ''));

        if (mb_strlen($note) > self::NOTE_MAX) {
            throw new AccountingException(AccountingError::StockPermitNoteTooLong, 'The note is longer than 500 characters.', ['max' => self::NOTE_MAX]);
        }

        return [
            'type'           => $type,
            'date'           => $date,
            'location_id'    => (int) $source->location_id,
            'to_location_id' => $toLocationId,
            'branch_id'      => (int) $source->branch_id,
            'to_branch_id'   => $toBranchId,
            'supplier_id'    => $supplierId,
            'customer_id'    => $customerId,
            'account_id'     => $accountId,
            'note'           => $note,
            'request_id'     => isset($input['request_id']) && (int) $input['request_id'] > 0 ? (int) $input['request_id'] : null,
            'lines'          => $this->lineList($input['lines'] ?? [], $type),
        ];
    }

    /**
     * @return list<array{item_id: int, quantity: string, unit_cost: string}>
     *
     * @throws AccountingException
     */
    private function lineList(mixed $lines, StockPermitType $type): array
    {
        if (! is_array($lines) || $lines === [] || count($lines) > self::LINES_MAX) {
            throw new AccountingException(AccountingError::StockPermitLinesInvalid, 'A permit has from one to 200 lines.', ['line' => 0]);
        }

        $list = [];

        foreach (array_values($lines) as $index => $line) {
            $number   = $index + 1;
            $itemId   = is_array($line) ? (int) ($line['item_id'] ?? 0) : 0;
            $unitId   = is_array($line) && isset($line['unit_id']) && $line['unit_id'] !== '' ? (int) $line['unit_id'] : null;
            $quantity = is_array($line) ? trim((string) ($line[$unitId === null ? 'quantity' : 'unit_quantity'] ?? '')) : '';
            $cost     = is_array($line) && trim((string) ($line[$unitId === null ? 'unit_cost' : 'unit_price'] ?? '')) !== '' ? trim((string) $line[$unitId === null ? 'unit_cost' : 'unit_price']) : '0';

            if (preg_match(self::QUANTITY, $quantity) !== 1 || bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, '999999999', 3) > 0) {
                throw new AccountingException(AccountingError::StockPermitLinesInvalid, "The quantity of line {$number} must be more than zero.", ['line' => $number]);
            }

            if (preg_match(self::COST, $cost) !== 1) {
                throw new AccountingException(AccountingError::StockPermitLinesInvalid, "The cost of line {$number} is not a number.", ['line' => $number]);
            }

            if ($itemId <= 0 || $this->db->table('items')->where(['item_id' => $itemId, 'deleted' => 0, 'stock_type' => HAS_STOCK])->countAllResults() === 0) {
                throw new AccountingException(AccountingError::StockPermitLinesInvalid, "Line {$number} is not an item that keeps stock.", ['line' => $number]);
            }

            $unit = UnitLine::base($quantity, $cost);

            if ($unitId !== null) {
                $unit     = $this->units->convert($itemId, $unitId, $quantity, $type === StockPermitType::Add ? $cost : '0', $number, UnitLine::COST_SCALE);
                $quantity = $unit->baseQuantity;
                $cost     = $unit->basePrice;

                if (bccomp($quantity, '0', 3) <= 0 || bccomp($quantity, '999999999', 3) > 0) {
                    throw new AccountingException(AccountingError::StockPermitLinesInvalid, "The quantity of line {$number} must be more than zero.", ['line' => $number]);
                }
            }

            $entry = TrackingEntry::ofLine(is_array($line) ? $line : [])->filledFor(bcadd($quantity, '0', 3));

            $this->tracking->assertSerialEntry($itemId, $number, bcadd($quantity, '0', 3), $entry);

            $list[] = ['item_id' => $itemId, 'quantity' => bcadd($quantity, '0', 3), 'unit_cost' => $type === StockPermitType::Add ? bcadd($cost, '0', 6) : '0.000000', 'unit' => $unit->columns('unit_cost_entered'), 'tracking' => $entry];
        }

        return $list;
    }

    /**
     * @throws AccountingException
     */
    private function party(mixed $personId, string $table, bool $allowed): ?int
    {
        $personId = $personId === null || $personId === '' ? 0 : (int) $personId;

        if ($personId === 0) {
            return null;
        }

        if (! $allowed || $this->db->table($table)->where(['person_id' => $personId, 'deleted' => 0])->countAllResults() === 0) {
            throw new AccountingException(AccountingError::StockPermitPartyInvalid, 'An add permit may name a supplier and an issue permit a customer, and the party must exist.', ['name' => $table]);
        }

        return $personId;
    }

    /**
     * @throws AccountingException
     */
    private function account(mixed $accountId, StockPermitType $type): ?int
    {
        $accountId = $accountId === null || $accountId === '' ? 0 : (int) $accountId;

        if ($accountId === 0) {
            return null;
        }

        foreach ($this->allowedAccounts($type) as $account) {
            if ((int) $account->account_id === $accountId) {
                return $accountId;
            }
        }

        throw new AccountingException(AccountingError::StockPermitAccountInvalid, 'The account of the permit must be an open account without a party of its own, of a kind that fits the permit.', ['account_id' => $accountId]);
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
            throw new AccountingException(AccountingError::InvalidDate, "A stock permit is dated up to today, not {$date}.", ['date' => $date]);
        }

        return $date;
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

    /**
     * The items on the lines of a permit, read before the transaction that locks them. Items are locked
     * before anything else is read in it, so that what the books say about them is what has been
     * committed when the lock is had.
     *
     * @return list<int>
     */
    private function itemsOf(int $permitId): array
    {
        return array_map('intval', array_column($this->db->table('stock_permit_lines')->select('item_id')->where('permit_id', $permitId)->get()->getResultArray(), 'item_id'));
    }

    /**
     * @return list<int>
     */
    private function itemsTyped(mixed $lines): array
    {
        return is_array($lines) ? array_values(array_filter(array_map(static fn (mixed $line): int => is_array($line) ? (int) ($line['item_id'] ?? 0) : 0, $lines), static fn (int $itemId): bool => $itemId > 0)) : [];
    }

    /**
     * What was locked must hold the items the document has now: a permit corrected between the read
     * and the lock changes them, and the change is asked to be made again.
     *
     * @param list<int> $locked
     * @param list<int> $needed
     *
     * @throws AccountingException
     */
    private function assertLocked(array $locked, array $needed): void
    {
        if (array_diff(array_map('intval', $needed), $locked) !== []) {
            throw new AccountingException(AccountingError::WriteFailed, 'The permit was changed by someone else while this change was prepared.');
        }
    }

    /**
     * The permit as it stands now, read under a lock. A permit that is cancelled does not stand.
     *
     * @throws AccountingException
     */
    private function standing(int $permitId): object
    {
        $table  = $this->db->prefixTable('stock_permits');
        $permit = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE permit_id = ? AND company_id = ? FOR UPDATE", [$permitId, CompanyContext::id()]);

        if ($permit === null) {
            throw new AccountingException(AccountingError::StockPermitNotFound, "Stock permit {$permitId} does not exist.", ['permit_id' => $permitId]);
        }

        if ($permit->status !== 'posted') {
            throw new AccountingException(AccountingError::StockPermitCancelled, 'This stock permit is already cancelled.', ['permit_id' => $permitId]);
        }

        return $permit;
    }

    /**
     * Posts a permit as a revision and moves the shelves. Returns whether the books followed it.
     *
     * @param array{type: StockPermitType, date: string, location_id: int, to_location_id: ?int, branch_id: int, to_branch_id: ?int, supplier_id: ?int, customer_id: ?int, account_id: ?int, note: string, request_id: ?int, lines: list<array{item_id: int, quantity: string, unit_cost: string}>} $data
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function put(int $permitId, array $data, int $revision, int $employeeId): bool
    {
        $document = $this->documentFrom($permitId, $data, $this->numberOf($permitId));
        $follows  = $this->follows($data['date']);
        $deltas   = $this->deltas($document, 1);

        $this->shelves->assertStays($deltas);

        if ($follows) {
            $this->assertOpen($document);
        }

        $plan = $follows ? $this->builder->plan($document, $revision, $employeeId) : null;

        if ($data['type'] === StockPermitType::Add && ($follows || $this->averagesCost())) {
            $this->averageIn($document);
        }

        if ($plan !== null) {
            $this->write($plan, $document, $revision);
        }

        if ($data['type'] === StockPermitType::Issue) {
            $this->keepIssuedCosts($permitId, $data['lines'], $plan);
        }

        $this->applyTracking($permitId, $data, $employeeId);
        $this->shelves->move($deltas, self::COMMENT . $permitId, $employeeId, $this->trackedItems($data['lines']));

        return $follows;
    }

    /**
     * Brings in, takes out or moves the serial numbers and the batches of the tracked lines of a permit,
     * which changes the shelves of those items itself.
     *
     * @param array{type: StockPermitType, date: string, location_id: int, to_location_id: ?int, lines: list<array<string, mixed>>} $data
     *
     * @throws AccountingException
     */
    private function applyTracking(int $permitId, array $data, int $employeeId): void
    {
        foreach (array_values($data['lines']) as $index => $line) {
            $itemId = (int) $line['item_id'];
            $entry  = $line['tracking'] ?? TrackingEntry::none();

            if (! $this->tracking->handles($itemId)) {
                if (! $entry->isEmpty()) {
                    throw new AccountingException(AccountingError::TrackedEntryNotAllowed, 'The item is not tracked.', ['line' => $index + 1, 'name' => (string) $this->db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow()->name]);
                }

                continue;
            }

            match ($data['type']) {
                StockPermitType::Add      => $this->tracking->receive('stock_permit', $permitId, $index + 1, $itemId, $data['location_id'], $line['quantity'], $entry),
                StockPermitType::Issue    => $this->tracking->issue('stock_permit', $permitId, $index + 1, $itemId, $data['location_id'], $line['quantity'], $entry, $data['date'], StockTracking::ISSUE, $employeeId),
                StockPermitType::Transfer => $this->tracking->transfer('stock_permit', $permitId, $index + 1, $itemId, $data['location_id'], (int) $data['to_location_id'], $line['quantity'], $entry, $employeeId),
            };
        }
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return list<int>
     */
    private function trackedItems(array $lines): array
    {
        return array_values(array_filter(array_unique(array_map(static fn (array $line): int => (int) $line['item_id'], $lines)), fn (int $itemId): bool => $this->tracking->handles($itemId)));
    }

    /**
     * What an issue permit took its units out at, kept on its lines for the screen.
     *
     * @param list<array{item_id: int, quantity: string, unit_cost: string}> $lines
     */
    private function keepIssuedCosts(int $permitId, array $lines, ?StockPostingPlan $plan): void
    {
        if ($plan === null) {
            return;
        }

        foreach (array_values($lines) as $index => $line) {
            $movement = $plan->movements[$index];

            $this->db->table('stock_permit_lines')->where(['permit_id' => $permitId, 'line_no' => $index + 1])->update([
                'unit_cost' => SaleAmounts::round(bcdiv(SaleAmounts::absolute($movement->value), $line['quantity'], 8), 6),
            ]);
        }
    }

    /**
     * @throws PostingException
     */
    private function write(StockPostingPlan $plan, StockPermitDocument $document, int $revision): void
    {
        if ($document->type !== StockPermitType::Transfer || $document->toBranchId === null) {
            $source    = $document->type === StockPermitType::Transfer ? StockPermitSources::TRANSFER : StockPermitSources::PERMIT;
            $journalId = $plan->journal === null ? null : $this->posting->post($plan->journal)->journalId;

            $this->ledger->record($source, $document->permitId, JournalEntryType::General, $revision, $document->date, $document->branchId, $plan->movements, $journalId);

            return;
        }

        $legs = [
            $document->branchId   => [StockPermitSources::TRANSFER, $plan->journal, $plan->movements],
            $document->toBranchId => [StockPermitSources::TRANSFER_RECEIPT, $plan->receipt, $plan->receiptMovements],
        ];

        ksort($legs);

        foreach ($legs as $branchId => [$source, $draft, $movements]) {
            $journalId = $draft === null ? null : $this->posting->post($draft)->journalId;

            $this->ledger->record($source, $document->permitId, JournalEntryType::General, $revision, $document->date, $branchId, $movements, $journalId);

            if ($journalId !== null) {
                $sending = $source === StockPermitSources::TRANSFER;

                $this->legs->record(
                    $journalId,
                    (int) $this->resolver->accountFor($sending ? RoutingKey::InterBranchReceivable : RoutingKey::InterBranchPayable),
                    $sending ? InterBranchLegs::RECEIVABLE : InterBranchLegs::PAYABLE,
                    $sending ? (int) $document->toBranchId : $document->branchId,
                    StockPermitSources::LEGS_DOCUMENT,
                    $document->permitId,
                    $revision,
                );
            }
        }
    }

    /**
     * Reverses what the permit posted, journals and movements, and takes the cost it carried back out.
     * The shelves change back.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    private function takeBack(object $permit, StockPermitDocument $document, string $reason, int $employeeId): void
    {
        $permitId = (int) $permit->permit_id;
        $sources  = $document->type === StockPermitType::Transfer ? [StockPermitSources::TRANSFER, StockPermitSources::TRANSFER_RECEIPT] : [StockPermitSources::PERMIT];

        if ($document->type === StockPermitType::Add && ((bool) $permit->booked || $this->averagesCost())) {
            $this->averageOut($document);
        }

        $standing = $this->db->table('accounting_journals')
            ->select('journal_id, journal_date, branch_id, source_type')
            ->where('company_id', CompanyContext::id())
            ->whereIn('source_type', $sources)
            ->where(['source_id' => $permitId, 'entry_type' => JournalEntryType::General->value, 'status' => JournalStatus::Posted->value])
            ->orderBy('branch_id', 'ASC')
            ->orderBy('journal_id', 'ASC')
            ->get()
            ->getResult();

        foreach ($standing as $journal) {
            if ($this->isPosted((int) $journal->journal_id)) {
                $this->journals->reverseAutomatic((int) $journal->journal_id, $this->dayOfReversal((int) $journal->branch_id, (string) $journal->journal_date), $reason, $employeeId);
            }
        }

        $fallback = $this->dayOfReversal($document->branchId, $document->date);

        foreach ($sources as $source) {
            $original = null;

            foreach ($standing as $journal) {
                if ($journal->source_type === $source) {
                    $original = $journal;
                }
            }

            $reversal = $original === null ? null : $this->db->table('accounting_journals')->select('journal_id, journal_date')->where('reverses_journal_id', (int) $original->journal_id)->get()->getRow();

            $this->ledger->reverseSource($source, $permitId, JournalEntryType::General, $reversal === null ? $fallback : (string) $reversal->journal_date, $reversal === null ? null : (int) $reversal->journal_id);
        }

        $reversed = $this->tracking->reverseSource('stock_permit', $permitId);

        $this->shelves->move($this->deltas($document, -1), self::COMMENT . $permitId . ' reversed', $employeeId, $reversed);
    }

    private function isPosted(int $journalId): bool
    {
        return $this->db->table('accounting_journals')->where(['journal_id' => $journalId, 'status' => JournalStatus::Posted->value])->countAllResults() > 0;
    }

    private function dayOfReversal(int $branchId, string $date): string
    {
        $cutoff = $this->periods->effectiveCutoff($branchId, true);

        return $cutoff !== null && $date <= $cutoff['date'] ? date('Y-m-d') : $date;
    }

    /**
     * @throws PostingException
     */
    private function assertOpen(StockPermitDocument $document): void
    {
        $this->periods->prepare($document->date);

        $branchIds = array_values(array_unique(array_filter([$document->branchId, $document->toBranchId])));
        sort($branchIds);

        foreach ($branchIds as $branchId) {
            $this->periods->enforce($document->date, $branchId);
        }
    }

    private function follows(string $date): bool
    {
        if (! $this->settings->isEnabled()) {
            return false;
        }

        $start = $this->settings->startDate();

        return $start === null || $date >= $start;
    }

    private function averagesCost(): bool
    {
        return (bool) (config(OSPOS::class)->settings['receiving_calculate_average_price'] ?? false);
    }

    /**
     * Brings the cost each item carries to the weighted average once the units of an add permit are in,
     * each at what it costs on the permit. It runs before the shelves change.
     */
    private function averageIn(StockPermitDocument $document): void
    {
        $item = model(Item::class);

        foreach ($this->figures($document) as $itemId => [$units, $value]) {
            $item->applyPurchaseCost($itemId, $units, bcdiv($value, $units, 8));
        }
    }

    /**
     * Takes the units of an add permit out of the cost each item carries, at what they cost on the
     * permit. It runs before the shelves change.
     */
    private function averageOut(StockPermitDocument $document): void
    {
        $item = model(Item::class);

        foreach ($this->figures($document) as $itemId => [$units, $value]) {
            $item->correctPurchaseCost($itemId, $units, $value, '0', '0');
        }
    }

    /**
     * @return array<int, array{string, string}> The units and the value of each item of an add permit.
     */
    private function figures(StockPermitDocument $document): array
    {
        $figures = [];

        foreach ($document->lines as $line) {
            $figures[$line->itemId] ??= ['0', '0'];
            $figures[$line->itemId][0] = bcadd($figures[$line->itemId][0], $line->quantity, 3);
            $figures[$line->itemId][1] = bcadd($figures[$line->itemId][1], SaleAmounts::round(bcmul($line->quantity, $line->unitCost, 8), StockLedger::VALUE_SCALE), StockLedger::VALUE_SCALE);
        }

        ksort($figures);

        return $figures;
    }

    /**
     * What the shelves change by when the permit is in force (sign 1) or taken back (sign -1).
     *
     * @return list<array{itemId: int, locationId: int, delta: string}>
     */
    private function deltas(StockPermitDocument $document, int $sign): array
    {
        $deltas = [];

        foreach ($document->lines as $line) {
            $units = $sign > 0 ? bcadd($line->quantity, '0', 3) : bcsub('0', $line->quantity, 3);
            $out   = bcsub('0', $units, 3);

            if ($document->type === StockPermitType::Add) {
                $deltas[] = ['itemId' => $line->itemId, 'locationId' => $document->locationId, 'delta' => $units];
            } elseif ($document->type === StockPermitType::Issue) {
                $deltas[] = ['itemId' => $line->itemId, 'locationId' => $document->locationId, 'delta' => $out];
            } else {
                $deltas[] = ['itemId' => $line->itemId, 'locationId' => $document->locationId, 'delta' => $out];
                $deltas[] = ['itemId' => $line->itemId, 'locationId' => (int) $document->toLocationId, 'delta' => $units];
            }
        }

        return $deltas;
    }

    /**
     * @param array{type: StockPermitType, date: string, location_id: int, to_location_id: ?int, branch_id: int, to_branch_id: ?int, account_id: ?int, lines: list<array{item_id: int, quantity: string, unit_cost: string}>} $data
     */
    private function documentFrom(int $permitId, array $data, string $label): StockPermitDocument
    {
        $costs = [];

        foreach ($this->db->table('items')->select('item_id, cost_price')->whereIn('item_id', array_column($data['lines'], 'item_id'))->get()->getResult() as $row) {
            $costs[(int) $row->item_id] = (string) $row->cost_price;
        }

        return new StockPermitDocument(
            permitId: $permitId,
            type: $data['type'],
            date: $data['date'],
            branchId: $data['branch_id'],
            toBranchId: $data['to_branch_id'],
            locationId: $data['location_id'],
            toLocationId: $data['to_location_id'],
            accountId: $data['account_id'],
            label: $label,
            lines: array_map(static fn (array $line, int $index): StockPermitLine => new StockPermitLine((int) $line['item_id'], $index + 1, $line['quantity'], $line['unit_cost'], $costs[(int) $line['item_id']] ?? '0'), $data['lines'], array_keys($data['lines'])),
            branchCodes: $this->branchCodes(),
        );
    }

    private function documentOf(object $permit): StockPermitDocument
    {
        $lines = array_map(static fn (object $line): array => ['item_id' => (int) $line->item_id, 'quantity' => bcadd((string) $line->quantity, '0', 3), 'unit_cost' => bcadd((string) $line->unit_cost, '0', 6)], $this->lines((int) $permit->permit_id));

        return $this->documentFrom((int) $permit->permit_id, [
            'type'           => StockPermitType::from((string) $permit->permit_type),
            'date'           => (string) $permit->permit_date,
            'location_id'    => (int) $permit->location_id,
            'to_location_id' => $permit->to_location_id === null ? null : (int) $permit->to_location_id,
            'branch_id'      => (int) $permit->branch_id,
            'to_branch_id'   => $permit->to_branch_id === null ? null : (int) $permit->to_branch_id,
            'account_id'     => $permit->account_id === null ? null : (int) $permit->account_id,
            'lines'          => $lines,
        ], $this->numberOf((int) $permit->permit_id));
    }

    /**
     * @return array<int, string>
     */
    private function branchCodes(): array
    {
        $codes = [];

        foreach ($this->db->table('branches')->select('branch_id, code')->where('company_id', CompanyContext::id())->get()->getResult() as $branch) {
            $codes[(int) $branch->branch_id] = (string) $branch->code;
        }

        return $codes;
    }

    /**
     * @param list<array{item_id: int, quantity: string, unit_cost: string}> $lines
     */
    private function writeLines(int $permitId, array $lines): void
    {
        $rows = [];

        foreach (array_values($lines) as $index => $line) {
            $rows[] = ['permit_id' => $permitId, 'line_no' => $index + 1, 'item_id' => $line['item_id'], 'quantity' => $line['quantity'], 'unit_cost' => $line['unit_cost'], ...($line['unit'] ?? [])];
        }

        $this->db->table('stock_permit_lines')->insertBatch($rows);
    }

    /**
     * @return list<array{item_id: int, quantity: string, unit_cost: string}>
     */
    private function linesOf(StockPermitDocument $document): array
    {
        return array_map(static fn (StockPermitLine $line): array => ['item_id' => $line->itemId, 'quantity' => $line->quantity, 'unit_cost' => $line->unitCost], $document->lines);
    }

    /**
     * @param array{type: StockPermitType, date: string, location_id: int, to_location_id: ?int, lines: list<array{item_id: int, quantity: string, unit_cost: string}>} $data
     *
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        return [
            'type'        => $data['type']->value,
            'date'        => $data['date'],
            'location_id' => $data['location_id'],
            'to_location' => $data['to_location_id'],
            'lines'       => $data['lines'],
        ];
    }

    private static function source(StockPermitType $type): string
    {
        return $type === StockPermitType::Transfer ? StockPermitSources::TRANSFER : StockPermitSources::PERMIT;
    }
}
