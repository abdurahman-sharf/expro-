<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PeriodGuard;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingFailureLog;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\StockAdjustment;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\StockMovement;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use DateTimeImmutable;

/**
 * The stocktake: a count of the stock of one location on one day. Starting it freezes what the system
 * holds of each item listed, and the counts are entered against that. Only the items listed are
 * counted, so a partial count is allowed, and a location has one stocktake for a day while it stands.
 * An item is on one count of a location at a time while it is being made, so that two counts never
 * apply the same difference. Approving it needs a permission of its own: each difference between the
 * count and the frozen quantity becomes a stock adjustment, up for what was found over and down for
 * what was found short, valued at what a unit is worth in that location now, and posted in one journal
 * against the accounts of the stock count that the routing names, the way a quantity adjustment is
 * posted. The shelves change by the difference, so what was sold or bought after the count began stays
 * counted.
 *
 * An approved stocktake is never edited or deleted. It is cancelled by reversing its journal and its
 * movements, which is refused when that would leave a location with less than nothing of an item, as a
 * correction of a receiving is. A stocktake that has not been approved is cancelled without any effect.
 * When the books do not follow it, which is while accounting is off, the shelves still change. Its
 * journal takes the day it is approved, not the day counted. Neither that day, nor the setting that the
 * reference system has for counting by the date of the stocktake, was observed.
 */
final class Stocktake
{
    use RunsInTransaction;

    public const NOTE_MAX         = 500;
    public const REASON_MAX       = 255;
    public const ITEMS_MAX        = 5000;
    private const QUANTITY        = '/^\d{1,9}(\.\d{1,3})?$/';
    private const ENTITY          = 'stocktake';
    private const COMMENT         = 'STKT ';
    private const DUPLICATE_ENTRY = 1062;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AccountingSettings $settings;
    private StockShelves $shelves;
    private StockAccess $access;
    private PeriodGuard $periods;
    private PostingService $posting;
    private JournalService $journals;
    private StockLedger $ledger;
    private StockAdjustment $adjustment;
    private RoutingResolver $resolver;
    private AuditLog $audit;
    private DocumentNumbering $numbering;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->guard      = $guard ?? new AccountingGuard();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->settings   = new AccountingSettings($this->db);
        $this->shelves    = new StockShelves($this->db);
        $this->access     = new StockAccess($this->db, $this->branches, $this->guard);
        $this->periods    = new PeriodGuard($this->db);
        $this->posting    = new PostingService($this->db);
        $this->journals   = new JournalService($this->db);
        $this->ledger     = new StockLedger($this->db);
        $this->adjustment = new StockAdjustment($this->db);
        $this->resolver   = new RoutingResolver($this->db);
        $this->audit      = new AuditLog($this->db);
        $this->numbering  = new DocumentNumbering($this->db);
    }

    /**
     * Starts a count: the location, the day and the items to count, whose quantities are frozen now.
     *
     * @param array{location_id: int, date?: string, note?: string, all_items?: bool, item_ids?: list<int|string>} $input
     *
     * @throws AccountingException|BranchException
     */
    public function create(array $input, int $employeeId): int
    {
        $this->guard->require(StockPermissions::STOCKTAKE_MANAGE, $employeeId);

        $location = $this->access->assertMayChange((int) ($input['location_id'] ?? 0), $employeeId);
        $date     = $this->day((string) ($input['date'] ?? date('Y-m-d')));
        $note     = $this->note((string) ($input['note'] ?? ''));
        $itemIds  = ! empty($input['all_items']) ? $this->itemsOf((int) $location->location_id) : $this->checkedItems($input['item_ids'] ?? []);

        return $this->transactional(function () use ($location, $date, $note, $itemIds, $employeeId): int {
            $this->lockLocation((int) $location->location_id);

            $slot = $location->location_id . '|' . $date;

            try {
                $inserted = $this->db->table('stocktakes')->insert([
                    'company_id'  => CompanyContext::id(),
                    'branch_id'   => (int) $location->branch_id,
                    'location_id' => (int) $location->location_id,
                    'count_date'  => $date,
                    'slot'        => $slot,
                    'note'        => $note,
                    'created_by'  => $employeeId,
                ]);
                $code = $inserted === false ? (int) ($this->db->error()['code'] ?? 0) : 0;
            } catch (DatabaseException $exception) {
                $inserted = false;
                $code     = (int) $exception->getCode();
            }

            if ($inserted === false) {
                throw $code === self::DUPLICATE_ENTRY
                    ? new AccountingException(AccountingError::StocktakeExists, 'This location already has a stocktake for this day.', ['date' => $date, 'name' => (string) $location->location_name])
                    : new AccountingException(AccountingError::WriteFailed, 'The stocktake could not be saved.');
            }

            $stocktakeId = (int) $this->db->insertID();

            $this->freeze($stocktakeId, (int) $location->location_id, $itemIds);
            $this->numbering->assign(DocumentType::Stocktake, 'stocktakes', $stocktakeId, (int) $location->branch_id);
            $this->audit->write(self::ENTITY, $stocktakeId, 'stocktake_started', $employeeId, JournalOrigin::User, ['location_id' => (int) $location->location_id, 'date' => $date, 'items' => count($itemIds)], (int) $location->branch_id);

            return $stocktakeId;
        });
    }

    /**
     * Lists more items on a count that is still being made, freezing what the system holds of each now.
     * An item that is listed already stays as it is.
     *
     * @param list<int|string> $itemIds
     *
     * @throws AccountingException|BranchException
     */
    public function addItems(int $stocktakeId, array $itemIds, int $employeeId): void
    {
        $this->guard->require(StockPermissions::STOCKTAKE_MANAGE, $employeeId);

        $itemIds    = $this->checkedItems($itemIds);
        $locationId = (int) ($this->db->table('stocktakes')->select('location_id')->where('stocktake_id', $stocktakeId)->get()->getRow()->location_id ?? 0);

        $this->transactional(function () use ($stocktakeId, $itemIds, $employeeId, $locationId): void {
            $this->lockLocation($locationId);

            $stocktake = $this->counting($stocktakeId, $employeeId);
            $added     = $this->freeze($stocktakeId, (int) $stocktake->location_id, $itemIds);

            if ($added !== []) {
                $this->audit->write(self::ENTITY, $stocktakeId, 'stocktake_items_added', $employeeId, JournalOrigin::User, ['items' => $added], (int) $stocktake->branch_id);
            }
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function removeItem(int $stocktakeId, int $itemId, int $employeeId): void
    {
        $this->guard->require(StockPermissions::STOCKTAKE_MANAGE, $employeeId);

        $this->transactional(function () use ($stocktakeId, $itemId, $employeeId): void {
            $stocktake = $this->counting($stocktakeId, $employeeId);
            $line      = $this->db->table('stocktake_lines')->where(['stocktake_id' => $stocktakeId, 'item_id' => $itemId])->get()->getRow();

            if ($line === null) {
                return;
            }

            $this->db->table('stocktake_lines')->where(['stocktake_id' => $stocktakeId, 'item_id' => $itemId])->delete();
            $this->audit->write(self::ENTITY, $stocktakeId, 'stocktake_item_removed', $employeeId, JournalOrigin::User, [
                'item_id'         => $itemId,
                'system_quantity' => bcadd((string) $line->system_quantity, '0', 3),
                'counted'         => $line->counted_quantity === null ? null : bcadd((string) $line->counted_quantity, '0', 3),
            ], (int) $stocktake->branch_id);
        });
    }

    /**
     * Enters what was counted for the items listed. An empty count takes a quantity back. When the
     * sheet says what it showed for an item, an item whose typed figure is the one shown is left as
     * it is, so that saving a sheet never blanks what another person counted after it was opened, and
     * a figure typed over a count that changed since is refused, whole.
     *
     * @param array<int|string, mixed> $counted The quantity counted, by the id of the item.
     * @param array<int|string, mixed> $seen    The quantity the sheet showed, by the id of the item.
     *
     * @throws AccountingException|BranchException
     */
    public function count(int $stocktakeId, array $counted, int $employeeId, array $seen = []): void
    {
        $this->guard->require(StockPermissions::STOCKTAKE_MANAGE, $employeeId);

        $this->transactional(function () use ($stocktakeId, $counted, $employeeId, $seen): void {
            $stocktake = $this->counting($stocktakeId, $employeeId);

            $stored = [];

            foreach ($this->db->table('stocktake_lines l')->select('l.item_id, l.counted_quantity, i.name')->join('items i', 'i.item_id = l.item_id')->where('l.stocktake_id', $stocktakeId)->get()->getResult() as $row) {
                $stored[(int) $row->item_id] = ['quantity' => $row->counted_quantity === null ? null : bcadd((string) $row->counted_quantity, '0', 3), 'name' => (string) $row->name];
            }

            $changes = [];

            foreach ($counted as $itemId => $typed) {
                $itemId = (int) $itemId;

                if (! array_key_exists($itemId, $stored)) {
                    throw new AccountingException(AccountingError::StocktakeLinesInvalid, 'The item is not on this stocktake.', ['line' => $itemId]);
                }

                $quantity = $this->countedQuantity($typed, $itemId);

                if ($quantity === $stored[$itemId]['quantity']) {
                    continue;
                }

                if (array_key_exists($itemId, $seen)) {
                    $shown = $this->countedQuantity($seen[$itemId], $itemId);

                    if ($shown === $quantity) {
                        continue;
                    }

                    if ($shown !== $stored[$itemId]['quantity']) {
                        throw new AccountingException(AccountingError::StocktakeCountChanged, 'The count of the item was changed after the sheet was opened.', ['name' => $stored[$itemId]['name']]);
                    }
                }

                $changes[$itemId] = ['from' => $stored[$itemId]['quantity'], 'to' => $quantity];
            }

            foreach ($changes as $itemId => $change) {
                $this->db->table('stocktake_lines')->where(['stocktake_id' => $stocktakeId, 'item_id' => $itemId])->update(['counted_quantity' => $change['to']]);
            }

            if ($changes !== []) {
                $this->audit->write(self::ENTITY, $stocktakeId, 'stocktake_counted', $employeeId, JournalOrigin::User, ['counts' => $changes], (int) $stocktake->branch_id);
            }
        });
    }

    /**
     * Approves the count: what it found over and short is posted as stock adjustments and the shelves
     * change by the difference.
     *
     * @param string|null $reviewed The digest of the lines the approver reviewed, as the sheet showed it:
     *                              an approval is refused when the lines are not those any more.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function approve(int $stocktakeId, int $employeeId, ?string $reviewed = null): void
    {
        $this->guard->require(StockPermissions::STOCKTAKE_APPROVE, $employeeId);

        $ownsTransaction = $this->db->transDepth === 0;
        $branchId        = null;
        $items           = $this->itemsOnCount($stocktakeId);

        try {
            $this->transactional(function () use ($stocktakeId, $employeeId, $items, $reviewed, &$branchId): void {
                $this->shelves->lockItems($items);

                $stocktake = $this->counting($stocktakeId, $employeeId);
                $branchId  = (int) $stocktake->branch_id;
                $lines     = $this->lines($stocktakeId);
                $digest    = $this->digest($stocktakeId);

                if ($reviewed !== null && ! hash_equals($digest, $reviewed)) {
                    throw new AccountingException(AccountingError::StocktakeChanged, 'The counts changed after the stocktake was opened.', ['stocktake_id' => $stocktakeId]);
                }

                if ($lines === []) {
                    throw new AccountingException(AccountingError::StocktakeNothingToApprove, 'A stocktake with no items has nothing to approve.');
                }

                $differences = [];

                foreach ($lines as $line) {
                    if ($line->counted_quantity === null) {
                        throw new AccountingException(AccountingError::StocktakeNotCounted, "The item {$line->item_name} has no counted quantity.", ['name' => (string) $line->item_name]);
                    }

                    $differences[(int) $line->item_id] = bcsub((string) $line->counted_quantity, (string) $line->system_quantity, 3);
                }

                $this->assertLocked($items, array_keys($differences));

                $deltas = [];

                foreach ($differences as $itemId => $difference) {
                    if (bccomp($difference, '0', 3) !== 0) {
                        $deltas[] = ['itemId' => $itemId, 'locationId' => (int) $stocktake->location_id, 'delta' => $difference];
                    }
                }

                $this->shelves->assertStays($deltas);

                $today   = date('Y-m-d');
                $follows = $this->follows($today);
                $journal = null;
                $costs   = [];

                if ($follows) {
                    $this->periods->prepare($today);
                    $this->periods->enforce($today, $branchId);

                    [$journal, $movements, $costs] = $this->plan($stocktake, $differences, $today, $employeeId);

                    $journalId = $journal === null ? null : $this->posting->post($journal)->journalId;

                    $this->ledger->record(StockPermitSources::STOCKTAKE, $stocktakeId, JournalEntryType::General, 1, $today, $branchId, $movements, $journalId);
                }

                $this->shelves->move($deltas, self::COMMENT . $stocktakeId, $employeeId);

                foreach ($differences as $itemId => $difference) {
                    $this->db->table('stocktake_lines')->where(['stocktake_id' => $stocktakeId, 'item_id' => $itemId])->update([
                        'difference' => $difference,
                        'unit_cost'  => $costs[$itemId] ?? null,
                    ]);
                }

                $this->db->table('stocktakes')->where('stocktake_id', $stocktakeId)->update([
                    'status'      => 'approved',
                    'booked'      => $follows ? 1 : 0,
                    'approved_by' => $employeeId,
                    'approved_at' => date('Y-m-d H:i:s'),
                ]);

                $this->audit->write(self::ENTITY, $stocktakeId, 'stocktake_approved', $employeeId, JournalOrigin::User, [
                    'location_id' => (int) $stocktake->location_id,
                    'items'       => count($lines),
                    'differences' => count($deltas),
                    'booked'      => $follows,
                    'digest'      => $digest,
                ], $branchId);
            });
        } catch (PostingException $failure) {
            if ($ownsTransaction) {
                (new PostingFailureLog($this->db))->recordUnbuilt(StockPermitSources::STOCKTAKE, $stocktakeId, 1, JournalEntryType::General, $failure, $employeeId, $branchId);
            }

            throw $failure;
        }
    }

    /**
     * Cancels a stocktake. One that is still being counted is discarded and nothing was posted. One that
     * was approved has its journal and its movements reversed and the shelves put back, unless that would
     * leave a location with less than nothing of an item.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $stocktakeId, string $reason, int $employeeId): void
    {
        $reason = $this->reason($reason);

        $items = $this->itemsOnCount($stocktakeId);

        $this->transactional(function () use ($stocktakeId, $reason, $employeeId, $items): void {
            $this->shelves->lockItems($items);

            $table     = $this->db->prefixTable('stocktakes');
            $stocktake = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE stocktake_id = ? AND company_id = ? FOR UPDATE", [$stocktakeId, CompanyContext::id()]);

            if ($stocktake === null) {
                throw new AccountingException(AccountingError::StocktakeNotFound, "Stocktake {$stocktakeId} does not exist.", ['stocktake_id' => $stocktakeId]);
            }

            if ($stocktake->status === 'cancelled') {
                throw new AccountingException(AccountingError::StocktakeCancelled, 'This stocktake is already cancelled.', ['stocktake_id' => $stocktakeId]);
            }

            $approved = $stocktake->status === 'approved';

            $this->guard->require($approved ? StockPermissions::STOCKTAKE_APPROVE : StockPermissions::STOCKTAKE_MANAGE, $employeeId);
            $this->access->assertMayChange((int) $stocktake->location_id, $employeeId);

            if ($approved) {
                $this->takeBack($stocktake, $reason, $employeeId, $items);
            }

            $this->db->table('stocktakes')->where('stocktake_id', $stocktakeId)->update([
                'status'        => 'cancelled',
                'slot'          => null,
                'cancelled_by'  => $employeeId,
                'cancelled_at'  => date('Y-m-d H:i:s'),
                'cancel_reason' => $reason,
            ]);

            $this->audit->write(self::ENTITY, $stocktakeId, 'stocktake_cancelled', $employeeId, JournalOrigin::User, ['reason' => $reason, 'was' => $stocktake->status], (int) $stocktake->branch_id);
        });
    }

    public function find(int $stocktakeId): ?object
    {
        return $this->db->table('stocktakes s')
            ->select('s.*, b.code AS branch_code, l.location_name, d.number AS document_number', false)
            ->join('branches b', 'b.branch_id = s.branch_id')
            ->join('stock_locations l', 'l.location_id = s.location_id')
            ->join('document_numbers d', "d.doc_type = '" . DocumentType::Stocktake->value . "' AND d.source_table = 'stocktakes' AND d.source_id = s.stocktake_id", 'left')
            ->where('s.company_id', CompanyContext::id())
            ->where('s.stocktake_id', $stocktakeId)
            ->get()
            ->getRow();
    }

    /**
     * A fingerprint of the lines as they stand, the items with the quantity frozen and the quantity
     * counted, that the sheet carries to the approval so that what is approved is what was reviewed.
     */
    public function digest(int $stocktakeId): string
    {
        $parts = [];

        foreach ($this->db->table('stocktake_lines')->select('item_id, system_quantity, counted_quantity')->where('stocktake_id', $stocktakeId)->orderBy('item_id', 'ASC')->get()->getResult() as $line) {
            $parts[] = $line->item_id . ':' . bcadd((string) $line->system_quantity, '0', 3) . ':' . ($line->counted_quantity === null ? '-' : bcadd((string) $line->counted_quantity, '0', 3));
        }

        return hash('sha256', implode('|', $parts));
    }

    /**
     * The items on the count, by name, with what the system held when they were listed, what was counted
     * and, once approved, the difference and what a unit was worth.
     *
     * @return list<object>
     */
    public function lines(int $stocktakeId): array
    {
        return $this->db->table('stocktake_lines l')
            ->select('l.item_id, l.system_quantity, l.counted_quantity, l.difference, l.unit_cost, i.name AS item_name, i.item_number')
            ->join('items i', 'i.item_id = l.item_id')
            ->where('l.stocktake_id', $stocktakeId)
            ->orderBy('i.name', 'ASC')
            ->orderBy('l.item_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The stocktakes of the branches the employee works in, the newest first.
     *
     * @param array{status?: string, location_id?: int, search?: string, from?: string, to?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, ?int $employeeId, int $limit = 25, int $offset = 0): array
    {
        $total = $this->searched($filters, $employeeId)->countAllResults();
        $rows  = $this->searched($filters, $employeeId)
            ->select('s.*, b.code AS branch_code, l.location_name, d.number AS document_number', false)
            ->orderBy('s.count_date', 'DESC')
            ->orderBy('s.stocktake_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $row->label = $row->document_number ?? DocumentType::Stocktake->fallback((int) $row->stocktake_id);
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @param array{status?: string, location_id?: int, search?: string, from?: string, to?: string} $filters
     */
    private function searched(array $filters, ?int $employeeId): BaseBuilder
    {
        $branchIds = $this->branches->allowedBranchIds($employeeId);

        $builder = $this->db->table('stocktakes s')
            ->join('branches b', 'b.branch_id = s.branch_id')
            ->join('stock_locations l', 'l.location_id = s.location_id')
            ->join('document_numbers d', "d.doc_type = '" . DocumentType::Stocktake->value . "' AND d.source_table = 'stocktakes' AND d.source_id = s.stocktake_id", 'left')
            ->where('s.company_id', CompanyContext::id());

        if ($branchIds !== null) {
            $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('s.branch_id', $branchIds);
        }

        if (in_array($filters['status'] ?? '', ['counting', 'approved', 'cancelled'], true)) {
            $builder->where('s.status', $filters['status']);
        }

        if (! empty($filters['location_id'])) {
            $builder->where('s.location_id', (int) $filters['location_id']);
        }

        if (! empty($filters['from'])) {
            $builder->where('s.count_date >=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $builder->where('s.count_date <=', $filters['to']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $labelled = StockDocumentSearch::labelled($search, [DocumentType::Stocktake]);

            $builder->groupStart()->like('d.number', $search)->orLike('s.note', $search);

            if ($labelled !== null) {
                $builder->orGroupStart()->where('s.stocktake_id', $labelled['id'])->where('d.number IS NULL', null, false)->groupEnd();
            }

            $builder->groupEnd();
        }

        return $builder;
    }

    /**
     * The journal an approved stocktake posted, and the one that took it back.
     *
     * @return list<object>
     */
    public function journals(int $stocktakeId): array
    {
        return $this->db->table('accounting_journals')
            ->select('journal_id, number, journal_date, status, source_type, revision, total_debit')
            ->where('company_id', CompanyContext::id())
            ->where(['source_type' => StockPermitSources::STOCKTAKE, 'source_id' => $stocktakeId])
            ->orderBy('journal_id', 'ASC')
            ->get()
            ->getResult();
    }

    public function numberOf(int $stocktakeId): string
    {
        return $this->numbering->label(DocumentType::Stocktake, 'stocktakes', $stocktakeId);
    }

    public function peekNumber(): ?string
    {
        return $this->numbering->peek(DocumentType::Stocktake);
    }

    /**
     * The stock items that have a shelf in a location, to count them all.
     *
     * @return list<int>
     */
    public function itemsOf(int $locationId): array
    {
        $rows = $this->db->table('item_quantities q')
            ->select('q.item_id')
            ->join('items i', 'i.item_id = q.item_id')
            ->where(['q.location_id' => $locationId, 'i.deleted' => 0, 'i.stock_type' => HAS_STOCK])
            ->orderBy('q.item_id', 'ASC')
            ->limit(self::ITEMS_MAX)
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($rows, 'item_id'));
    }

    /**
     * The journal of an approval: one for the whole count, the stock of the location up for what was
     * found over against the count account of gains, and down for what was found short against the
     * count account of losses. Nothing is posted for a count that found nothing worth anything.
     *
     * @param array<int, string> $differences The difference between the count and the frozen quantity, by item.
     *
     * @return array{0: ?JournalDraft, 1: list<StockMovement>, 2: array<int, string>} The journal, the movements and what a unit was worth, by item.
     *
     * @throws PostingException
     */
    private function plan(object $stocktake, array $differences, string $date, int $employeeId): array
    {
        $costs     = [];
        $movements = [];
        $gains     = '0';
        $losses    = '0';

        foreach ($differences as $itemId => $difference) {
            if (bccomp($difference, '0', 3) === 0) {
                continue;
            }

            $item     = $this->db->table('items')->select('cost_price')->where('item_id', $itemId)->get()->getRow();
            $movement = $this->adjustment->valued($itemId, (int) $stocktake->location_id, $difference, (string) ($item->cost_price ?? '0'));
            $worth    = SaleAmounts::absolute($movement->value);

            $movements[]    = $movement;
            $costs[$itemId] = SaleAmounts::round(bcdiv($worth, SaleAmounts::absolute($difference), 8), 6);

            if (bccomp($difference, '0', 3) > 0) {
                $gains = bcadd($gains, $worth, StockLedger::VALUE_SCALE);
            } else {
                $losses = bcadd($losses, $worth, StockLedger::VALUE_SCALE);
            }
        }

        $lines = [];

        if (bccomp($gains, '0', StockLedger::VALUE_SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->required(RoutingKey::StockLocations, (int) $stocktake->location_id), debit: $gains);
            $lines[] = new JournalLineDraft($this->required(RoutingKey::StockCountIn), credit: $gains);
        }

        if (bccomp($losses, '0', StockLedger::VALUE_SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->required(RoutingKey::StockCountOut), debit: $losses);
            $lines[] = new JournalLineDraft($this->required(RoutingKey::StockLocations, (int) $stocktake->location_id), credit: $losses);
        }

        $journal = $lines === [] ? null : new JournalDraft(
            date: $date,
            lines: $lines,
            sourceType: StockPermitSources::STOCKTAKE,
            sourceId: (int) $stocktake->stocktake_id,
            revision: 1,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: 'Stocktake #' . $this->numberOf((int) $stocktake->stocktake_id),
            branchId: (int) $stocktake->branch_id,
            entryType: JournalEntryType::General,
        );

        return [$journal, $movements, $costs];
    }

    /**
     * Reverses an approved stocktake: its journal and movements and the shelves, which are refused
     * when the units found over have gone since.
     *
     * @param list<int> $locked The items that were locked for the change.
     *
     * @throws AccountingException|PostingException
     */
    private function takeBack(object $stocktake, string $reason, int $employeeId, array $locked): void
    {
        $stocktakeId = (int) $stocktake->stocktake_id;
        $lines       = $this->lines($stocktakeId);
        $deltas      = [];

        foreach ($lines as $line) {
            if ($line->difference !== null && bccomp((string) $line->difference, '0', 3) !== 0) {
                $deltas[] = ['itemId' => (int) $line->item_id, 'locationId' => (int) $stocktake->location_id, 'delta' => bcsub('0', (string) $line->difference, 3)];
            }
        }

        $this->assertLocked($locked, array_map(static fn (object $line): int => (int) $line->item_id, $lines));
        $this->shelves->assertStays($deltas);

        $journal = $this->db->table('accounting_journals')
            ->select('journal_id, journal_date, branch_id')
            ->where(['company_id' => CompanyContext::id(), 'source_type' => StockPermitSources::STOCKTAKE, 'source_id' => $stocktakeId, 'entry_type' => JournalEntryType::General->value, 'status' => JournalStatus::Posted->value])
            ->get()
            ->getRow();

        $date     = $this->dayOfReversal((int) $stocktake->branch_id, $journal === null ? date('Y-m-d') : (string) $journal->journal_date);
        $reversal = null;

        if ($journal !== null) {
            $reversal = $this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);
        }

        $this->ledger->reverseSource(StockPermitSources::STOCKTAKE, $stocktakeId, JournalEntryType::General, $date, $reversal);
        $this->shelves->move($deltas, self::COMMENT . $stocktakeId . ' reversed', $employeeId);
    }

    /**
     * The items listed on a count, read before the transaction that locks them, so that what the books
     * say about them is what has been committed when the lock is had.
     *
     * @return list<int>
     */
    private function itemsOnCount(int $stocktakeId): array
    {
        return array_map('intval', array_column($this->db->table('stocktake_lines')->select('item_id')->where('stocktake_id', $stocktakeId)->get()->getResultArray(), 'item_id'));
    }

    /**
     * What was locked must hold the items listed now: a count whose items changed between the read and
     * the lock is asked to be approved again.
     *
     * @param list<int> $locked
     * @param list<int> $needed
     *
     * @throws AccountingException
     */
    private function assertLocked(array $locked, array $needed): void
    {
        if (array_diff(array_map('intval', $needed), $locked) !== []) {
            throw new AccountingException(AccountingError::WriteFailed, 'The stocktake was changed by someone else while this change was prepared.');
        }
    }

    private function dayOfReversal(int $branchId, string $date): string
    {
        $cutoff = $this->periods->effectiveCutoff($branchId, true);

        return $cutoff !== null && $date <= $cutoff['date'] ? date('Y-m-d') : $date;
    }

    private function follows(string $date): bool
    {
        if (! $this->settings->isEnabled()) {
            return false;
        }

        $start = $this->settings->startDate();

        return $start === null || $date >= $start;
    }

    /**
     * The stocktake while it is being counted, read under a lock, for an employee who may change the
     * stock of its location.
     *
     * @throws AccountingException|BranchException
     */
    private function counting(int $stocktakeId, int $employeeId): object
    {
        $table     = $this->db->prefixTable('stocktakes');
        $stocktake = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE stocktake_id = ? AND company_id = ? FOR UPDATE", [$stocktakeId, CompanyContext::id()]);

        if ($stocktake === null) {
            throw new AccountingException(AccountingError::StocktakeNotFound, "Stocktake {$stocktakeId} does not exist.", ['stocktake_id' => $stocktakeId]);
        }

        $this->access->assertMayChange((int) $stocktake->location_id, $employeeId);

        if ($stocktake->status !== 'counting') {
            throw new AccountingException(AccountingError::StocktakeNotCounting, 'This stocktake is no longer being counted.', ['stocktake_id' => $stocktakeId]);
        }

        return $stocktake;
    }

    /**
     * Lists the items on a count with what the system holds of each now, the ones listed already left
     * alone, and says which items it listed. An item that is on another count of the same location that
     * is still being made is refused: both counts would apply the same difference to the shelf.
     *
     * @param list<int> $itemIds
     *
     * @return list<int>
     *
     * @throws AccountingException
     */
    private function freeze(int $stocktakeId, int $locationId, array $itemIds): array
    {
        $listed = array_map('intval', array_column($this->db->table('stocktake_lines')->select('item_id')->where('stocktake_id', $stocktakeId)->get()->getResultArray(), 'item_id'));
        $fresh  = array_values(array_diff($itemIds, $listed));

        if ($fresh === []) {
            return [];
        }

        $this->assertOnNoOtherCount($stocktakeId, $locationId, $fresh);

        $rows = [];

        foreach ($fresh as $itemId) {
            $rows[] = ['stocktake_id' => $stocktakeId, 'item_id' => $itemId, 'system_quantity' => $this->shelves->onHand($itemId, $locationId)];
        }

        $this->db->table('stocktake_lines')->insertBatch($rows);

        return $fresh;
    }

    /**
     * @param list<int> $itemIds
     *
     * @throws AccountingException
     */
    private function assertOnNoOtherCount(int $stocktakeId, int $locationId, array $itemIds): void
    {
        $other = $this->db->table('stocktake_lines l')
            ->select('l.stocktake_id, l.item_id')
            ->join('stocktakes s', 's.stocktake_id = l.stocktake_id')
            ->where(['s.company_id' => CompanyContext::id(), 's.location_id' => $locationId, 's.status' => 'counting'])
            ->where('s.stocktake_id !=', $stocktakeId)
            ->whereIn('l.item_id', $itemIds)
            ->orderBy('l.item_id', 'ASC')
            ->limit(1)
            ->get()
            ->getRow();

        if ($other !== null) {
            $item = $this->db->table('items')->select('name')->where('item_id', (int) $other->item_id)->get()->getRow();

            throw new AccountingException(AccountingError::StocktakeItemOpen, 'The item is on another stocktake of this location that is still being counted.', [
                'code' => $this->numberOf((int) $other->stocktake_id),
                'name' => $item === null ? (string) $other->item_id : (string) $item->name,
            ]);
        }
    }

    private function lockLocation(int $locationId): void
    {
        LockedRead::row($this->db, 'SELECT location_id FROM ' . $this->db->prefixTable('stock_locations') . ' WHERE location_id = ? FOR UPDATE', [$locationId]);
    }

    private function countedQuantity(mixed $typed, int $itemId): ?string
    {
        if ($typed !== null && ! is_scalar($typed)) {
            throw new AccountingException(AccountingError::StocktakeLinesInvalid, 'A counted quantity is a number of zero or more.', ['line' => $itemId]);
        }

        $text = trim((string) $typed);

        if ($text === '') {
            return null;
        }

        if (preg_match(self::QUANTITY, $text) !== 1) {
            throw new AccountingException(AccountingError::StocktakeLinesInvalid, 'A counted quantity is a number of zero or more.', ['line' => $itemId]);
        }

        return bcadd($text, '0', 3);
    }

    /**
     * @return list<int>
     *
     * @throws AccountingException
     */
    private function checkedItems(mixed $itemIds): array
    {
        if (! is_array($itemIds)) {
            throw new AccountingException(AccountingError::StocktakeItemsInvalid, 'The items to count are a list.', ['max' => self::ITEMS_MAX]);
        }

        $ids = array_values(array_unique(array_map('intval', $itemIds)));

        if (count($ids) > self::ITEMS_MAX) {
            throw new AccountingException(AccountingError::StocktakeItemsInvalid, 'A stocktake lists up to 5000 items.', ['max' => self::ITEMS_MAX]);
        }

        foreach ($ids as $itemId) {
            if ($itemId <= 0 || $this->db->table('items')->where(['item_id' => $itemId, 'deleted' => 0, 'stock_type' => HAS_STOCK])->countAllResults() === 0) {
                throw new AccountingException(AccountingError::StocktakeLinesInvalid, "The item {$itemId} is not an item that keeps stock.", ['line' => $itemId]);
            }
        }

        return $ids;
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
            throw new AccountingException(AccountingError::InvalidDate, "A stocktake is dated up to today, not {$date}.", ['date' => $date]);
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

    /**
     * @throws PostingException
     */
    private function required(RoutingKey $key, ?int $entityId = null): int
    {
        $account = $this->resolver->accountFor($key, $entityId);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a stocktake cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }
}
