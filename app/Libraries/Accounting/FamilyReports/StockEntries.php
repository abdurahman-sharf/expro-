<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The movements of stock that reached the books, as the valued movements of the stock ledger hold
 * them, cut down to the branches of a scope, a stretch of days, a location, an item or a category of
 * items. A posting and its reversal are two movements here, as they are in the ledger.
 */
final class StockEntries
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
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
            ->select('e.entry_id, e.source_type, e.source_id, e.entry_type, e.revision, e.line_no, e.kind, e.entry_date, e.item_id, e.location_id, e.branch_id, e.quantity, e.value, i.name AS item_name, i.item_number, i.category, s.location_name')
            ->where('e.entry_date <=', $to)
            ->orderBy('e.entry_date', 'ASC')
            ->orderBy('e.entry_id', 'ASC');

        if ($from !== null) {
            $builder->where('e.entry_date >=', $from);
        }

        return $builder->get()->getResult();
    }

    /**
     * The quantity the movements of the branches add up to in some locations.
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
            ->whereIn('e.branch_id', $scope->ids)
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
