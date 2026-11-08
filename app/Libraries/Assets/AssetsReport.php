<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The assets report: the assets of the branches of a scope, filtered by the custody employee, the date of
 * purchase, the months whose depreciation is wanted, the status, the account and the category, and
 * grouped by status, day of going into service or day of purchase. The columns are an assumption (the
 * reference system showed none). What the register has on the books ties to the books: the cost of the
 * assets on the books is what the debit side of their cost accounts holds, and their accumulated
 * depreciation what the credit side of theirs holds, so that cost less accumulated depreciation is the
 * balance of the two accounts and the tie says where it does not hold.
 */
final class AssetsReport
{
    private const SCALE = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function build(ReportScope $scope, AssetsFilter $filter): AssetsReportResult
    {
        $asOf = $filter->asOf ?? date('Y-m-d');
        $rows = [];

        foreach ($this->collect($scope, $filter, $asOf) as $asset) {
            $rows[] = $this->rowOf($asset);
        }

        return new AssetsReportResult($asOf, $this->groups($rows, $filter->group), ...$this->totals($rows), tie: $this->tie($scope, $asOf));
    }

    /**
     * What the register holds against what the books hold, account by account, for every asset of the
     * scope that is on the books on the day.
     *
     * @return list<AssetsTieRow>
     */
    public function tie(ReportScope $scope, ?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $held = ['cost' => [], 'accumulated' => []];

        foreach ($this->collect($scope, new AssetsFilter(), $asOf) as $asset) {
            $held['cost'][(int) $asset->cost_account_id] ??= '0';
            $row = $this->rowOf($asset);

            if ($asset->accumulated_account_id !== null) {
                $held['accumulated'][(int) $asset->accumulated_account_id] ??= '0';
            }

            if (! $row->onTheBooks) {
                continue;
            }

            $held['cost'][(int) $asset->cost_account_id] = bcadd($held['cost'][(int) $asset->cost_account_id], $row->cost, self::SCALE);

            if ($asset->accumulated_account_id !== null) {
                $held['accumulated'][(int) $asset->accumulated_account_id] = bcadd($held['accumulated'][(int) $asset->accumulated_account_id], $row->accumulated, self::SCALE);
            }
        }

        $ids = [...array_keys($held['cost']), ...array_keys($held['accumulated'])];

        if ($ids === [] || $scope->isEmpty()) {
            return [];
        }

        $accounts = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, code, name')->whereIn('account_id', $ids)->get()->getResult() as $account) {
            $accounts[(int) $account->account_id] = $account;
        }

        $ledger = $this->ledger($scope, $ids, $asOf);
        $rows   = [];

        foreach (['cost', 'accumulated'] as $kind) {
            foreach ($held[$kind] as $accountId => $register) {
                $moved = $ledger[$accountId] ?? ['debit' => '0.0000', 'credit' => '0.0000'];

                $rows[] = new AssetsTieRow(
                    $accountId,
                    (string) $accounts[$accountId]->code,
                    (string) $accounts[$accountId]->name,
                    $kind,
                    bcadd($register, '0', self::SCALE),
                    $kind === 'cost' ? bcsub($moved['debit'], $moved['credit'], self::SCALE) : bcsub($moved['credit'], $moved['debit'], self::SCALE),
                );
            }
        }

        usort($rows, static fn (AssetsTieRow $a, AssetsTieRow $b): int => [$a->kind, $a->code] <=> [$b->kind, $b->code]);

        return $rows;
    }

    /**
     * @return list<object>
     */
    private function collect(ReportScope $scope, AssetsFilter $filter, string $asOf): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $assets = $this->db->prefixTable('assets');
        $deps   = $this->db->prefixTable('asset_depreciations');
        $ops    = $this->db->prefixTable('asset_operations');
        $units  = $this->db->prefixTable('asset_units');
        $from   = $filter->depreciationFrom ?? '1900-01-01';
        $to     = $filter->depreciationTo ?? $asOf;
        $args   = [$asOf, $from, $to, CompanyContext::id()];
        $where  = [];

        $where[] = 'a.branch_id IN (' . implode(', ', array_map('intval', $scope->ids)) . ')';

        if ($filter->statuses !== []) {
            $where[] = 'a.status IN (' . implode(', ', array_fill(0, count($filter->statuses), '?')) . ')';

            foreach ($filter->statuses as $status) {
                $args[] = $status->value;
            }
        }

        foreach (['purchaseFrom' => 'a.purchase_date >= ?', 'purchaseTo' => 'a.purchase_date <= ?'] as $property => $condition) {
            if ($filter->{$property} !== null) {
                $where[] = $condition;
                $args[]  = $filter->{$property};
            }
        }

        if ($filter->employeeId !== null) {
            $where[] = "EXISTS (SELECT 1 FROM {$units} u WHERE u.asset_id = a.asset_id AND u.employee_id = ?)";
            $args[]  = $filter->employeeId;
        }

        if ($filter->accountId !== null) {
            $where[] = '(a.cost_account_id = ? OR a.accumulated_account_id = ?)';
            $args[]  = $filter->accountId;
            $args[]  = $filter->accountId;
        }

        if ($filter->categoryId !== null) {
            $where[] = 'a.category_id = ?';
            $args[]  = $filter->categoryId;
        }

        $sql = "SELECT a.*, b.code AS branch_code, c.name AS category_name, o.operation_date, o.cost_removed, o.accumulated_removed,
                    COALESCE((SELECT SUM(d.amount) FROM {$deps} d WHERE d.asset_id = a.asset_id AND d.active_key = 1 AND LAST_DAY(d.period_start) <= ?), 0) AS posted,
                    COALESCE((SELECT SUM(d.amount) FROM {$deps} d WHERE d.asset_id = a.asset_id AND d.active_key = 1 AND LAST_DAY(d.period_start) BETWEEN ? AND ?), 0) AS in_range
                FROM {$assets} a
                JOIN " . $this->db->prefixTable('branches') . ' b ON b.branch_id = a.branch_id
                LEFT JOIN ' . $this->db->prefixTable('asset_categories') . " c ON c.category_id = a.category_id
                LEFT JOIN {$ops} o ON o.asset_id = a.asset_id AND o.active_key = 1
                WHERE a.company_id = ? AND a.deleted = 0 AND " . implode(' AND ', $where) . ' ORDER BY a.code ASC, a.asset_id ASC';

        $found = $this->db->query($sql, $args)->getResult();

        foreach ($found as $asset) {
            $asset->as_of = $asOf;
        }

        return $found;
    }

    private function rowOf(object $asset): AssetsReportRow
    {
        $asOf     = (string) $asset->as_of;
        $disposed = $asset->operation_date !== null && (string) $asset->operation_date <= $asOf;
        $started  = in_array((string) $asset->funding, ['opening', 'none'], true) || (string) $asset->purchase_date <= $asOf;
        $cost     = $disposed ? bcadd((string) $asset->cost_removed, '0', self::SCALE) : bcadd((string) $asset->cost, '0', self::SCALE);
        $accum    = $disposed ? bcadd((string) $asset->accumulated_removed, '0', self::SCALE) : bcadd(bcadd((string) $asset->opening_accumulated, (string) $asset->posted, self::SCALE), '0', self::SCALE);

        return new AssetsReportRow(
            (int) $asset->asset_id,
            (string) $asset->code,
            (string) $asset->name,
            (string) $asset->branch_code,
            $asset->category_name === null ? null : (string) $asset->category_name,
            AssetStatus::from((string) $asset->status),
            AssetMethod::from((string) $asset->method),
            (string) $asset->purchase_date,
            (string) $asset->in_service_date,
            $cost,
            $accum,
            bcadd((string) $asset->in_range, '0', self::SCALE),
            bcsub($cost, $accum, self::SCALE),
            ! $disposed && $started,
            $asset->disposed_at === null ? null : (string) $asset->disposed_at,
        );
    }

    /**
     * @param list<AssetsReportRow> $rows
     *
     * @return list<AssetsReportGroup>
     */
    private function groups(array $rows, string $by): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $key = match ($by) {
                'status'          => $row->status->value,
                'in_service_date' => $row->inServiceDate,
                'purchase_date'   => $row->purchaseDate,
                default           => '',
            };

            $keyed[$key][] = $row;
        }

        $order = array_map(static fn (AssetStatus $status): string => $status->value, AssetStatus::cases());

        uksort($keyed, static fn (string $a, string $b): int => $by === 'status' ? array_search($a, $order, true) <=> array_search($b, $order, true) : strcmp($a, $b));

        $groups = [];

        foreach ($keyed as $key => $members) {
            $groups[] = new AssetsReportGroup((string) $key, $members, ...$this->totals($members));
        }

        return $groups;
    }

    /**
     * What the assets on the books come to, and the depreciation of the range of every asset.
     *
     * @param list<AssetsReportRow> $rows
     *
     * @return array{cost: numeric-string, accumulated: numeric-string, depreciation: numeric-string, bookValue: numeric-string}
     */
    private function totals(array $rows): array
    {
        $totals = ['cost' => '0.0000', 'accumulated' => '0.0000', 'depreciation' => '0.0000', 'bookValue' => '0.0000'];

        foreach ($rows as $row) {
            $totals['depreciation'] = bcadd($totals['depreciation'], $row->depreciation, self::SCALE);

            if ($row->onTheBooks) {
                $totals['cost']        = bcadd($totals['cost'], $row->cost, self::SCALE);
                $totals['accumulated'] = bcadd($totals['accumulated'], $row->accumulated, self::SCALE);
                $totals['bookValue']   = bcadd($totals['bookValue'], $row->bookValue, self::SCALE);
            }
        }

        return $totals;
    }

    /**
     * @param list<int> $accountIds
     *
     * @return array<int, array{debit: numeric-string, credit: numeric-string}>
     */
    private function ledger(ReportScope $scope, array $accountIds, string $asOf): array
    {
        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $rows     = $this->db->query(
            "SELECT l.account_id, COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit FROM {$lines} l JOIN {$journals} j ON j.journal_id = l.journal_id
             WHERE j.company_id = ? AND j.status IN (?, ?) AND j.journal_date <= ? AND j.branch_id IN (" . implode(', ', array_map('intval', $scope->ids)) . ') AND l.account_id IN (' . implode(', ', array_map('intval', $accountIds)) . ') GROUP BY l.account_id',
            [CompanyContext::id(), JournalStatus::Posted->value, JournalStatus::Reversed->value, $asOf],
        )->getResult();

        $found = [];

        foreach ($rows as $row) {
            $found[(int) $row->account_id] = ['debit' => bcadd((string) $row->debit, '0', self::SCALE), 'credit' => bcadd((string) $row->credit, '0', self::SCALE)];
        }

        return $found;
    }
}
