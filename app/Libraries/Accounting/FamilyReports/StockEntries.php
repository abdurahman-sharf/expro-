<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Generator;

/**
 * The movements of stock that reached the books, as the valued movements of the stock ledger hold
 * them, cut down to the branches of a scope, a stretch of days, a location, an item or a category of
 * items. A posting and its reversal are two movements here, as they are in the ledger.
 */
final class StockEntries
{
    public const CHUNK             = 2000;
    private const MOVEMENT_COLUMNS = 'e.entry_id, e.source_type, e.source_id, e.entry_type, e.revision, e.line_no, e.kind, e.entry_date, e.item_id, e.location_id, e.branch_id, e.quantity, e.value, i.name AS item_name, i.item_number, i.category, s.location_name';
    private const FIRST_SEEN       = "MIN(CONCAT(e.entry_date, LPAD(e.entry_id, 10, '0'))) AS first_seen";

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly int $chunk = self::CHUNK)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return list<object>
     */
    public function movements(ReportScope $scope, ?string $from, string $to, array $filters = []): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $builder = $this->base($scope, $filters)
            ->select(self::MOVEMENT_COLUMNS)
            ->where('e.entry_date <=', $to)
            ->orderBy('e.entry_date', 'ASC')
            ->orderBy('e.entry_id', 'ASC');

        if ($from !== null) {
            $builder->where('e.entry_date >=', $from);
        }

        return $builder->get()->getResult();
    }

    /**
     * The quantity the movements add up to in some locations, whichever branch wrote them: the shelves of
     * a location do not remember the branch it was in when it was moved, so a location that changed
     * branch is compared with all its movements.
     *
     * @param list<int> $locationIds
     *
     * @return numeric-string
     */
    public function quantityIn(ReportScope $scope, array $locationIds): string
    {
        if ($scope->isEmpty() || $locationIds === []) {
            return '0.000';
        }

        $row = $this->db->table('accounting_stock_entries e')
            ->select('COALESCE(SUM(e.quantity), 0) AS quantity', false)
            ->where('e.company_id', CompanyContext::id())
            ->whereIn('e.location_id', $locationIds)
            ->get()
            ->getRow();

        return bcadd((string) $row->quantity, '0', 3);
    }

    /**
     * What the movements before a day add up to, by item and location.
     *
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return list<object>
     */
    public function totals(ReportScope $scope, ?string $before, ?string $until, array $filters = []): array
    {
        if ($scope->isEmpty() || ($before === null && $until === null)) {
            return [];
        }

        $builder = $this->base($scope, $filters)
            ->select('e.item_id, e.location_id, i.name AS item_name, i.item_number, i.category, s.location_name, SUM(e.quantity) AS quantity, SUM(e.value) AS value', false)
            ->groupBy('e.item_id, e.location_id, i.name, i.item_number, i.category, s.location_name');

        if ($before !== null) {
            $builder->where('e.entry_date <', $before);
        }

        if ($until !== null) {
            $builder->where('e.entry_date <=', $until);
        }

        return $builder->get()->getResult();
    }

    /**
     * The items and locations the movements of the days touch, with how many movements each has and when its
     * first one was written (the day and the number of the entry, which is how the movements are ordered).
     *
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return list<object{item_id: int, location_id: int, item_name: string, item_number: ?string, category: string, location_name: ?string, found: int, first_seen: string}>
     */
    public function pairs(ReportScope $scope, ?string $from, string $to, array $filters = []): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $builder = $this->base($scope, $filters)
            ->select('e.item_id, e.location_id, i.name AS item_name, i.item_number, i.category, s.location_name, COUNT(*) AS found, ' . self::FIRST_SEEN, false)
            ->where('e.entry_date <=', $to)
            ->groupBy('e.item_id, e.location_id, i.name, i.item_number, i.category, s.location_name')
            ->orderBy('e.item_id', 'ASC')
            ->orderBy('e.location_id', 'ASC');

        if ($from !== null) {
            $builder->where('e.entry_date >=', $from);
        }

        return array_map(static function (object $row): object {
            $row->found = (int) $row->found;

            return $row;
        }, $builder->get()->getResult());
    }

    /**
     * The movements of the pairs of items and locations given, a pair after the other in the order given, each
     * in the order of its days and entries, as movements() gives them. A few thousand are held at a time: the
     * pairs are read together, and a pair of more movements than a chunk is read by its days.
     *
     * @param list<object>                                               $pairs   What pairs() gave, in the order they are wanted.
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return Generator<int, array{0: object, 1: iterable<object>}>
     */
    public function stream(ReportScope $scope, ?string $from, string $to, array $filters, array $pairs): Generator
    {
        $batch = [];
        $held  = 0;

        foreach ($pairs as $pair) {
            if ($pair->found > $this->chunk) {
                yield from $this->together($scope, $from, $to, $filters, $batch);

                $batch = [];
                $held  = 0;

                yield [$pair, $this->byDay($scope, $from, $to, $filters, (int) $pair->item_id, (int) $pair->location_id)];

                continue;
            }

            if ($held + $pair->found > $this->chunk && $batch !== []) {
                yield from $this->together($scope, $from, $to, $filters, $batch);

                $batch = [];
                $held  = 0;
            }

            $batch[] = $pair;
            $held += $pair->found;
        }

        yield from $this->together($scope, $from, $to, $filters, $batch);
    }

    /**
     * What the movements before a day add up to for each item, all its locations together, in the order of
     * the items.
     *
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return list<object{item_id: int, item_name: string, item_number: ?string, category: string, quantity: string, value: string}>
     */
    public function totalsByItem(ReportScope $scope, ?string $before, ?string $until, array $filters = []): array
    {
        if ($scope->isEmpty() || ($before === null && $until === null)) {
            return [];
        }

        $builder = $this->base($scope, $filters)
            ->select('e.item_id, i.name AS item_name, i.item_number, i.category, SUM(e.quantity) AS quantity, SUM(e.value) AS value', false)
            ->groupBy('e.item_id, i.name, i.item_number, i.category')
            ->orderBy('e.item_id', 'ASC');

        if ($before !== null) {
            $builder->where('e.entry_date <', $before);
        }

        if ($until !== null) {
            $builder->where('e.entry_date <=', $until);
        }

        return $builder->get()->getResult();
    }

    /**
     * What came in and what went out of each item in the days, in quantity and in value taken without its
     * sign, a movement of nothing counting as going out, with how many movements there were of each way.
     *
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return list<object{item_id: int, item_name: string, item_number: ?string, in_count: int, in_quantity: string, in_value: string, out_count: int, out_quantity: string, out_value: string, first_seen: string}>
     */
    public function inAndOutByItem(ReportScope $scope, ?string $from, string $to, array $filters = []): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $builder = $this->base($scope, $filters)
            ->select('e.item_id, i.name AS item_name, i.item_number, '
                . 'SUM(CASE WHEN e.quantity > 0 THEN 1 ELSE 0 END) AS in_count, SUM(CASE WHEN e.quantity > 0 THEN e.quantity ELSE 0 END) AS in_quantity, SUM(CASE WHEN e.quantity > 0 THEN ABS(e.value) ELSE 0 END) AS in_value, '
                . 'SUM(CASE WHEN e.quantity > 0 THEN 0 ELSE 1 END) AS out_count, SUM(CASE WHEN e.quantity > 0 THEN 0 ELSE ABS(e.quantity) END) AS out_quantity, SUM(CASE WHEN e.quantity > 0 THEN 0 ELSE ABS(e.value) END) AS out_value, '
                . self::FIRST_SEEN, false)
            ->where('e.entry_date <=', $to)
            ->groupBy('e.item_id, i.name, i.item_number')
            ->orderBy('e.item_id', 'ASC');

        if ($from !== null) {
            $builder->where('e.entry_date >=', $from);
        }

        return $builder->get()->getResult();
    }

    /**
     * What the sales of the days took out of the stock of each item, as a positive amount, with the day and
     * entry of the first of them.
     *
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return list<object{item_id: int, item_name: string, item_number: ?string, category: string, cost: string, first_seen: string}>
     */
    public function costOfSalesByItem(ReportScope $scope, ?string $from, string $to, string $sourceType, array $filters = []): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $builder = $this->base($scope, $filters)
            ->select('e.item_id, i.name AS item_name, i.item_number, i.category, SUM(0 - e.value) AS cost, ' . self::FIRST_SEEN, false)
            ->where('e.source_type', $sourceType)
            ->where('e.entry_date <=', $to)
            ->groupBy('e.item_id, i.name, i.item_number, i.category')
            ->orderBy('first_seen', 'ASC');

        if ($from !== null) {
            $builder->where('e.entry_date >=', $from);
        }

        return $builder->get()->getResult();
    }

    /**
     * What the movements of each item add up to, by the document they came from, the way they went and whether
     * they were a posting or a reversal, with the day and entry of the first.
     *
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return list<object{item_id: int, item_name: string, item_number: ?string, source_type: string, kind: string, positive: int, quantity: string, first_seen: string}>
     */
    public function byItemAndOperation(ReportScope $scope, ?string $from, string $to, array $filters = []): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $builder = $this->base($scope, $filters)
            ->select('e.item_id, i.name AS item_name, i.item_number, e.source_type, e.kind, (e.quantity > 0) AS positive, SUM(e.quantity) AS quantity, ' . self::FIRST_SEEN, false)
            ->where('e.entry_date <=', $to)
            ->groupBy('e.item_id, i.name, i.item_number, e.source_type, e.kind, (e.quantity > 0)')
            ->orderBy('e.item_id', 'ASC');

        if ($from !== null) {
            $builder->where('e.entry_date >=', $from);
        }

        return $builder->get()->getResult();
    }

    /**
     * @param list<object>                                               $batch
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return Generator<int, array{0: object, 1: iterable<object>}>
     */
    private function together(ReportScope $scope, ?string $from, string $to, array $filters, array $batch): Generator
    {
        if ($batch === []) {
            return;
        }

        $wanted = [];

        foreach ($batch as $pair) {
            $wanted[$pair->item_id . ':' . $pair->location_id] = true;
        }

        $builder = $this->base($scope, $filters)
            ->select(self::MOVEMENT_COLUMNS)
            ->whereIn('e.item_id', array_values(array_unique(array_map(static fn (object $pair): int => (int) $pair->item_id, $batch))))
            ->where('e.entry_date <=', $to)
            ->orderBy('e.entry_date', 'ASC')
            ->orderBy('e.entry_id', 'ASC');

        if ($from !== null) {
            $builder->where('e.entry_date >=', $from);
        }

        $found = [];

        foreach ($builder->get()->getResult() as $entry) {
            $key = $entry->item_id . ':' . $entry->location_id;

            if (isset($wanted[$key])) {
                $found[$key][] = $entry;
            }
        }

        foreach ($batch as $pair) {
            yield [$pair, $found[$pair->item_id . ':' . $pair->location_id] ?? []];
        }
    }

    /**
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     *
     * @return Generator<int, object>
     */
    private function byDay(ReportScope $scope, ?string $from, string $to, array $filters, int $itemId, int $locationId): Generator
    {
        $date    = '';
        $entryId = 0;

        while (true) {
            $builder = $this->base($scope, $filters)
                ->select(self::MOVEMENT_COLUMNS)
                ->where('e.item_id', $itemId)
                ->where('e.location_id', $locationId)
                ->where('e.entry_date <=', $to)
                ->orderBy('e.entry_date', 'ASC')
                ->orderBy('e.entry_id', 'ASC')
                ->limit($this->chunk);

            if ($from !== null) {
                $builder->where('e.entry_date >=', $from);
            }

            if ($date !== '') {
                $builder->where('(e.entry_date > ' . $this->db->escape($date) . ' OR (e.entry_date = ' . $this->db->escape($date) . ' AND e.entry_id > ' . $entryId . '))', null, false);
            }

            $entries = $builder->get()->getResult();

            if ($entries === []) {
                return;
            }

            yield from $entries;

            $date    = (string) end($entries)->entry_date;
            $entryId = (int) end($entries)->entry_id;
        }
    }

    /**
     * @param array{location_id?: int, item_id?: int, category?: string} $filters
     */
    private function base(ReportScope $scope, array $filters): BaseBuilder
    {
        $builder = $this->db->table('accounting_stock_entries e')
            ->join('items i', 'i.item_id = e.item_id')
            ->join('stock_locations s', 's.location_id = e.location_id', 'left')
            ->where('e.company_id', CompanyContext::id())
            ->whereIn('e.branch_id', $scope->ids);

        if (($filters['location_id'] ?? 0) > 0) {
            $builder->where('e.location_id', (int) $filters['location_id']);
        }

        if (($filters['item_id'] ?? 0) > 0) {
            $builder->where('e.item_id', (int) $filters['item_id']);
        }

        if (($filters['category'] ?? '') !== '') {
            $builder->where('i.category', $filters['category']);
        }

        return $builder;
    }
}
