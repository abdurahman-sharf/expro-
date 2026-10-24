<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The record of what stock is worth, movement by movement. Every stock movement that reaches
 * the books is written here with its quantity and its value, in the same transaction as its
 * journal, so that what an item is worth comes from what happened to it and ties to the account
 * of the stock location. A movement is written once for a source and a revision, and taken back
 * by a movement of the opposite sign, never changed or deleted.
 */
final class StockLedger
{
    public const VALUE_SCALE    = 4;
    public const QUANTITY_SCALE = 3;
    private const COST_SCALE    = 8;
    private const POSTING       = 'posting';
    private const REVERSAL      = 'reversal';
    private const TOLERANCE     = '0.0005';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Writes the movements of a posted source. Writing the same source and revision again leaves
     * what is there alone.
     *
     * @param list<StockMovement> $movements
     *
     * @throws PostingException
     */
    public function record(string $sourceType, int $sourceId, JournalEntryType $entryType, int $revision, string $date, int $branchId, array $movements, ?int $journalId): void
    {
        if ($movements === [] || $this->exists($sourceType, $sourceId, $entryType, $revision)) {
            return;
        }

        $rows = [];

        foreach (array_values($movements) as $index => $movement) {
            $rows[] = [
                'company_id'  => CompanyContext::id(),
                'branch_id'   => $branchId,
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
                'entry_type'  => $entryType->value,
                'revision'    => $revision,
                'line_no'     => $index + 1,
                'kind'        => self::POSTING,
                'entry_date'  => $date,
                'item_id'     => $movement->itemId,
                'location_id' => $movement->locationId,
                'quantity'    => bcadd($movement->quantity, '0', self::QUANTITY_SCALE),
                'value'       => bcadd($movement->value, '0', self::VALUE_SCALE),
                'journal_id'  => $journalId,
            ];
        }

        if ($this->db->table('accounting_stock_entries')->insertBatch($rows) === false) {
            throw new PostingException(PostingError::WriteFailed, 'The stock movements could not be written.', ['source_type' => $sourceType, 'source_id' => $sourceId]);
        }
    }

    /**
     * Takes back every movement of a source that has not been taken back yet, whichever revision
     * wrote it, by one of the opposite sign on the date given.
     *
     * @throws PostingException
     */
    public function reverseSource(string $sourceType, int $sourceId, JournalEntryType $entryType, string $date, ?int $journalId): void
    {
        $table = $this->db->prefixTable('accounting_stock_entries');
        $open  = $this->db->query(
            "SELECT p.revision, p.line_no, p.branch_id, p.item_id, p.location_id, p.quantity, p.value
             FROM {$table} p
             LEFT JOIN {$table} r ON r.source_type = p.source_type AND r.source_id = p.source_id AND r.entry_type = p.entry_type
                 AND r.revision = p.revision AND r.line_no = p.line_no AND r.kind = ?
             WHERE p.source_type = ? AND p.source_id = ? AND p.entry_type = ? AND p.kind = ? AND r.entry_id IS NULL
             ORDER BY p.revision, p.line_no",
            [self::REVERSAL, $sourceType, $sourceId, $entryType->value, self::POSTING],
        )->getResult();

        if ($open === []) {
            return;
        }

        $rows = [];

        foreach ($open as $entry) {
            $rows[] = [
                'company_id'  => CompanyContext::id(),
                'branch_id'   => (int) $entry->branch_id,
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
                'entry_type'  => $entryType->value,
                'revision'    => (int) $entry->revision,
                'line_no'     => (int) $entry->line_no,
                'kind'        => self::REVERSAL,
                'entry_date'  => $date,
                'item_id'     => (int) $entry->item_id,
                'location_id' => (int) $entry->location_id,
                'quantity'    => bcsub('0', $entry->quantity, self::QUANTITY_SCALE),
                'value'       => bcsub('0', $entry->value, self::VALUE_SCALE),
                'journal_id'  => $journalId,
            ];
        }

        if ($this->db->table('accounting_stock_entries')->insertBatch($rows) === false) {
            throw new PostingException(PostingError::WriteFailed, 'The stock movements could not be taken back.', ['source_type' => $sourceType, 'source_id' => $sourceId]);
        }
    }

    /**
     * The quantity and the value the movements add up to for an item in a stock location.
     *
     * @return array{quantity: string, value: string}
     */
    public function position(int $itemId, int $locationId): array
    {
        $row = $this->db->table('accounting_stock_entries')
            ->select('COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(value), 0) AS value', false)
            ->where('company_id', CompanyContext::id())
            ->where('item_id', $itemId)
            ->where('location_id', $locationId)
            ->get()
            ->getRow();

        return ['quantity' => bcadd((string) $row->quantity, '0', self::QUANTITY_SCALE), 'value' => bcadd((string) $row->value, '0', self::VALUE_SCALE)];
    }

    /**
     * What a unit of an item leaving a stock location is worth. When the movements account for
     * every unit that is there, it is what they say a unit is worth: the stock is valued from
     * what happened to it, as the reference system does. When they do not (stock from before
     * the books began, or from movements that were never posted), or nothing is left to divide
     * by, it is the cost the item carries. When the shelves have already changed by a quantity
     * that the movements do not have yet, say so, so that it is not counted as stock the movements
     * miss.
     *
     * @param numeric-string $pendingQuantity What the shelves changed by that is not yet a movement.
     *
     * @return numeric-string
     */
    public function unitCost(int $itemId, int $locationId, string $itemCost, string $pendingQuantity = '0'): string
    {
        $position = $this->position($itemId, $locationId);

        if (bccomp($position['quantity'], '0', self::QUANTITY_SCALE) > 0) {
            $unposted = bcsub(bcsub($this->onHand($itemId, $locationId), $pendingQuantity, self::QUANTITY_SCALE), $position['quantity'], self::QUANTITY_SCALE);

            if (bccomp(ltrim($unposted, '-') === '' ? '0' : ltrim($unposted, '-'), self::TOLERANCE, 4) <= 0) {
                return bcdiv($position['value'], $position['quantity'], self::COST_SCALE);
            }
        }

        return bcadd($itemCost, '0', self::COST_SCALE);
    }

    /**
     * Every item that has stock in the books or on the shelves, in every stock location, or in
     * the stock locations given, with what the movements say and what the shelves hold.
     *
     * @param list<int>|null $locationIds Null counts every stock location.
     *
     * @return list<StockPosition>
     */
    public function positions(?array $locationIds = null, ?int $itemId = null): array
    {
        if ($locationIds === []) {
            return [];
        }

        $entries    = $this->db->prefixTable('accounting_stock_entries');
        $quantities = $this->db->prefixTable('item_quantities');
        $items      = $this->db->prefixTable('items');
        $params     = [CompanyContext::id(), CompanyContext::id(), HAS_STOCK];
        $scope      = '';

        if ($locationIds !== null) {
            $scope .= ' AND k.location_id IN (' . implode(',', array_map('intval', $locationIds)) . ')';
        }

        if ($itemId !== null) {
            $scope .= ' AND k.item_id = ?';
            $params[] = $itemId;
        }

        $rows = $this->db->query(
            "SELECT k.item_id, k.location_id, i.name, i.cost_price,
                    COALESCE(e.quantity, 0) AS entry_quantity, COALESCE(e.value, 0) AS entry_value, COALESCE(q.quantity, 0) AS on_hand
             FROM (
                 SELECT item_id, location_id FROM {$entries} WHERE company_id = ?
                 UNION
                 SELECT item_id, location_id FROM {$quantities} WHERE quantity <> 0
             ) k
             JOIN {$items} i ON i.item_id = k.item_id
             LEFT JOIN (
                 SELECT item_id, location_id, SUM(quantity) AS quantity, SUM(value) AS value FROM {$entries} WHERE company_id = ? GROUP BY item_id, location_id
             ) e ON e.item_id = k.item_id AND e.location_id = k.location_id
             LEFT JOIN {$quantities} q ON q.item_id = k.item_id AND q.location_id = k.location_id
             WHERE i.stock_type = ?{$scope}
             ORDER BY i.name, k.item_id, k.location_id",
            $params,
        )->getResult();

        return array_map(static fn (object $row): StockPosition => new StockPosition(
            itemId: (int) $row->item_id,
            itemName: (string) $row->name,
            locationId: (int) $row->location_id,
            quantity: bcadd((string) $row->entry_quantity, '0', self::QUANTITY_SCALE),
            value: bcadd((string) $row->entry_value, '0', self::VALUE_SCALE),
            onHand: bcadd((string) $row->on_hand, '0', self::QUANTITY_SCALE),
            itemCost: (string) $row->cost_price,
        ), $rows);
    }

    /**
     * How the books and the movements compare for each stock location: what the account of the
     * location holds, what its movements add up to, and the rest, which is what reached the
     * account without a movement (a manual journal, or an opening balance).
     *
     * @param list<int>|null $locationIds Null counts every stock location.
     *
     * @return list<array{location_id: int, account_id: ?int, account_balance: string, movements_value: string, unattributed: string}>
     */
    public function reconcile(?array $locationIds = null): array
    {
        if ($locationIds === []) {
            return [];
        }

        $entities = new EntityAccountService($this->db);
        $ids      = $locationIds ?? array_map(
            'intval',
            array_column($this->db->table('stock_locations')->select('location_id')->where('deleted', 0)->get()->getResultArray(), 'location_id'),
        );

        $report = [];

        foreach ($ids as $locationId) {
            $accountId = $entities->accountFor(EntityAccountService::STOCK_LOCATION, $locationId);
            $balance   = $accountId === null ? '0' : $this->accountBalance($accountId);
            $movements = $this->db->table('accounting_stock_entries')
                ->select('COALESCE(SUM(value), 0) AS value', false)
                ->where('company_id', CompanyContext::id())
                ->where('location_id', $locationId)
                ->get()
                ->getRow()
                ->value;

            $report[] = [
                'location_id'     => $locationId,
                'account_id'      => $accountId,
                'account_balance' => bcadd($balance, '0', self::VALUE_SCALE),
                'movements_value' => bcadd((string) $movements, '0', self::VALUE_SCALE),
                'unattributed'    => bcsub($balance, (string) $movements, self::VALUE_SCALE),
            ];
        }

        return $report;
    }

    /**
     * The journals whose stock movements do not add up to what the journal puts on the account
     * of a stock location. There should be none: a journal and its movements are written together.
     *
     * @return list<array{journal_id: int, location_id: int, journal: string, movements: string}>
     */
    public function mismatchedJournals(): array
    {
        $entries  = $this->db->prefixTable('accounting_stock_entries');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $accounts = $this->db->prefixTable('accounting_accounts');

        $rows = $this->db->query(
            "SELECT e.journal_id, e.location_id, SUM(e.value) AS movements,
                    COALESCE((SELECT SUM(l.debit - l.credit) FROM {$lines} l WHERE l.journal_id = e.journal_id AND l.account_id = a.account_id), 0) AS journal
             FROM {$entries} e
             JOIN {$accounts} a ON a.company_id = e.company_id AND a.entity_type = ? AND a.entity_id = e.location_id
             WHERE e.journal_id IS NOT NULL
             GROUP BY e.journal_id, e.location_id, a.account_id
             HAVING ABS(movements - journal) > 0.00005",
            [EntityAccountService::STOCK_LOCATION],
        )->getResult();

        return array_map(static fn (object $row): array => [
            'journal_id'  => (int) $row->journal_id,
            'location_id' => (int) $row->location_id,
            'journal'     => bcadd((string) $row->journal, '0', self::VALUE_SCALE),
            'movements'   => bcadd((string) $row->movements, '0', self::VALUE_SCALE),
        ], $rows);
    }

    private function exists(string $sourceType, int $sourceId, JournalEntryType $entryType, int $revision): bool
    {
        return $this->db->table('accounting_stock_entries')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', $entryType->value)
            ->where('revision', $revision)
            ->where('kind', self::POSTING)
            ->countAllResults() > 0;
    }

    private function onHand(int $itemId, int $locationId): string
    {
        $row = $this->db->table('item_quantities')->select('quantity')->where('item_id', $itemId)->where('location_id', $locationId)->get()->getRow();

        return $row === null ? '0' : (string) $row->quantity;
    }

    /**
     * Debit minus credit over what was posted, reversals included.
     */
    private function accountBalance(int $accountId): string
    {
        $row = $this->db->query(
            'SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance
             FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l
             JOIN ' . $this->db->prefixTable('accounting_journals') . ' j ON j.journal_id = l.journal_id
             WHERE j.status IN (?, ?) AND l.account_id = ?',
            [JournalStatus::Posted->value, JournalStatus::Reversed->value, $accountId],
        )->getRow();

        return (string) $row->balance;
    }
}
