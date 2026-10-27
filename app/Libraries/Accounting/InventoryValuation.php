<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the stock is worth, from the movements that reached the books and never from the cost an
 * item carries times its quantity: for each location and each item the units, the average cost
 * and the value, with the total of every location, beside what the account of the location holds.
 *
 * The report adds up the branches of a scope and the movements and journals of those branches
 * only, up to a day, so that it can be set beside the trial balance of the same branches and day.
 * The shelves are compared with the movements only for the stock as it stands now, because what a
 * shelf held on a past day is not recorded: the units there that no movement accounts for are then
 * listed as not in the books, and are not given a value. Reading changes nothing.
 */
final class InventoryValuation
{
    private const SCALE = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param string|null $asOf      The last day counted. Nothing, or a day that is not past, is the stock as it stands now.
     * @param bool        $showEmpty Whether items and locations with nothing in them have a row.
     *
     * @throws AccountingException
     */
    public function build(ReportScope $scope, ?string $asOf = null, bool $showEmpty = false): InventoryValuationReport
    {
        $day     = $asOf === null || trim($asOf) === '' ? null : ReportPeriod::of(null, $asOf)->to;
        $current = $day === null  || $day >= date('Y-m-d');
        $until   = $current ? null : $day;
        $control = $this->stockAccounts();

        if ($scope->isEmpty()) {
            return new InventoryValuationReport([], $scope, $day, $current, $control['code'], $control['code'] === null ? null : '0.0000', '0.0000');
        }

        $entries   = $this->entries($scope, $until);
        $shelves   = $current ? $this->shelves($scope, $entries) : [];
        $locations = $this->locations($scope, array_values(array_unique([...array_keys($entries), ...array_keys($shelves)])));
        $balances  = $this->balances($scope, $until, array_merge($control['ids'], array_column($locations, 'account_id')));
        $built     = [];
        $total     = '0';

        foreach ($locations as $locationId => $location) {
            $lines = $this->lines($entries[$locationId] ?? [], $shelves[$locationId] ?? [], $current, $showEmpty);
            $value = array_reduce($entries[$locationId] ?? [], static fn (string $sum, object $row): string => bcadd($sum, (string) $row->value, self::SCALE), '0');
            $total = bcadd($total, $value, self::SCALE);

            $accountId = $location['account_id'];
            $balance   = $accountId === null ? '0' : ($balances[$accountId] ?? '0');

            if (! $showEmpty && $lines === [] && bccomp($balance, '0', self::SCALE) === 0) {
                continue;
            }

            $built[] = new InventoryValuationLocation(
                $locationId,
                $location['name'],
                $location['branch_id'],
                $location['branch_code'],
                $accountId,
                $location['account_code'],
                $lines,
                bcadd($value, '0', self::SCALE),
                bcadd($balance, '0', self::SCALE),
            );
        }

        usort($built, static fn (InventoryValuationLocation $first, InventoryValuationLocation $second): int => [$first->name, $first->locationId] <=> [$second->name, $second->locationId]);

        $stockBalance = $control['code'] === null ? null : array_reduce($control['ids'], static fn (string $sum, int $accountId): string => bcadd($sum, $balances[$accountId] ?? '0', self::SCALE), '0');

        return new InventoryValuationReport($built, $scope, $day, $current, $control['code'], $stockBalance, bcadd($total, '0', self::SCALE));
    }

    /**
     * What the movements say for each item of each location: units, value and what the item is.
     *
     * @return array<int, array<int, object>> By location, then by item.
     */
    private function entries(ReportScope $scope, ?string $until): array
    {
        $builder = $this->db->table('accounting_stock_entries e')
            ->select('e.location_id, e.item_id, i.name, i.item_number, i.cost_price, SUM(e.quantity) AS quantity, SUM(e.value) AS value', false)
            ->join('items i', 'i.item_id = e.item_id')
            ->where('e.company_id', CompanyContext::id())
            ->whereIn('e.branch_id', $scope->ids)
            ->groupBy('e.location_id, e.item_id, i.name, i.item_number, i.cost_price');

        if ($until !== null) {
            $builder->where('e.entry_date <=', $until);
        }

        $found = [];

        foreach ($builder->get()->getResult() as $row) {
            $found[(int) $row->location_id][(int) $row->item_id] = $row;
        }

        return $found;
    }

    /**
     * What the shelves hold of every item that has stock there or has movements, in the locations
     * of the branches and in those the movements are in.
     *
     * @param array<int, array<int, object>> $entries
     *
     * @return array<int, array<int, object>> By location, then by item.
     */
    private function shelves(ReportScope $scope, array $entries): array
    {
        $builder = $this->db->table('item_quantities q')
            ->select('q.location_id, q.item_id, q.quantity, i.name, i.item_number, i.cost_price')
            ->join('items i', 'i.item_id = q.item_id')
            ->join('stock_locations s', 's.location_id = q.location_id')
            ->where('i.stock_type', HAS_STOCK)
            ->groupStart()
            ->groupStart()->whereIn('s.branch_id', $scope->ids)->where('s.deleted', 0)->groupEnd();

        if ($entries !== []) {
            $builder->orWhereIn('q.location_id', array_keys($entries));
        }

        $found = [];

        foreach ($builder->groupEnd()->get()->getResult() as $row) {
            $locationId = (int) $row->location_id;
            $itemId     = (int) $row->item_id;

            if (bccomp((string) $row->quantity, '0', 3) !== 0 || isset($entries[$locationId][$itemId])) {
                $found[$locationId][$itemId] = $row;
            }
        }

        return $found;
    }

    /**
     * The locations the report may show: those of the branches, and those with movements or
     * shelves in them, with the account each has.
     *
     * @param list<int> $withStock
     *
     * @return array<int, array{name: string, branch_id: ?int, branch_code: ?string, account_id: ?int, account_code: ?string}>
     */
    private function locations(ReportScope $scope, array $withStock): array
    {
        $builder = $this->db->table('stock_locations s')
            ->select('s.location_id, s.location_name, s.branch_id, b.code AS branch_code, a.account_id, a.code AS account_code')
            ->join('branches b', 'b.branch_id = s.branch_id', 'left')
            ->join('accounting_accounts a', 'a.company_id = ' . CompanyContext::id() . ' AND a.entity_type = ' . $this->db->escape(EntityAccountService::STOCK_LOCATION) . ' AND a.entity_id = s.location_id', 'left')
            ->groupStart()
            ->groupStart()->whereIn('s.branch_id', $scope->ids)->where('s.deleted', 0)->groupEnd();

        if ($withStock !== []) {
            $builder->orWhereIn('s.location_id', $withStock);
        }

        $found = [];

        foreach ($builder->groupEnd()->get()->getResult() as $row) {
            $found[(int) $row->location_id] = [
                'name'         => (string) $row->location_name,
                'branch_id'    => $row->branch_id === null ? null : (int) $row->branch_id,
                'branch_code'  => $row->branch_code === null ? null : (string) $row->branch_code,
                'account_id'   => $row->account_id === null ? null : (int) $row->account_id,
                'account_code' => $row->account_code === null ? null : (string) $row->account_code,
            ];
        }

        return $found;
    }

    /**
     * The main stock account and every account under it.
     *
     * @return array{code: ?string, ids: list<int>}
     */
    private function stockAccounts(): array
    {
        $main = $this->db->table('accounting_routing r')
            ->select('a.account_id, a.code')
            ->join('accounting_accounts a', 'a.account_id = r.account_id')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.routing_key', RoutingKey::StockLocations->value)
            ->get()
            ->getRow();

        if ($main === null) {
            return ['code' => null, 'ids' => []];
        }

        $children = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, parent_id')->where('company_id', CompanyContext::id())->where('parent_id IS NOT NULL', null, false)->get()->getResult() as $account) {
            $children[(int) $account->parent_id][] = (int) $account->account_id;
        }

        $found = [(int) $main->account_id => true];
        $queue = [(int) $main->account_id];

        while ($queue !== []) {
            foreach ($children[array_pop($queue)] ?? [] as $child) {
                if (! isset($found[$child])) {
                    $found[$child] = true;
                    $queue[]       = $child;
                }
            }
        }

        return ['code' => (string) $main->code, 'ids' => array_keys($found)];
    }

    /**
     * What each account holds in the branches of the scope, debit minus credit, over the journals
     * that are in the books, a reversed one with its reversal.
     *
     * @param list<int|null> $accountIds
     *
     * @return array<int, string>
     */
    private function balances(ReportScope $scope, ?string $until, array $accountIds): array
    {
        $ids = array_values(array_unique(array_filter($accountIds, static fn (?int $accountId): bool => $accountId !== null)));

        if ($ids === []) {
            return [];
        }

        $builder = $this->db->table('accounting_journal_lines l')
            ->select('l.account_id, SUM(l.debit - l.credit) AS balance', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('l.account_id', $ids)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->groupBy('l.account_id');

        if ($until !== null) {
            $builder->where('j.journal_date <=', $until);
        }

        $found = [];

        foreach ($builder->get()->getResult() as $row) {
            $found[(int) $row->account_id] = bcadd((string) $row->balance, '0', self::SCALE);
        }

        return $found;
    }

    /**
     * @param array<int, object> $entries By item.
     * @param array<int, object> $shelves By item.
     *
     * @return list<InventoryValuationLine>
     */
    private function lines(array $entries, array $shelves, bool $current, bool $showEmpty): array
    {
        $lines = [];

        foreach ($entries + $shelves as $itemId => $row) {
            $entry = $entries[$itemId] ?? null;
            $shelf = $shelves[$itemId] ?? null;

            $line = new InventoryValuationLine(
                new StockPosition(
                    itemId: $itemId,
                    itemName: (string) $row->name,
                    locationId: (int) $row->location_id,
                    quantity: bcadd((string) ($entry->quantity ?? '0'), '0', StockLedger::QUANTITY_SCALE),
                    value: bcadd((string) ($entry->value ?? '0'), '0', StockLedger::VALUE_SCALE),
                    onHand: bcadd((string) ($shelf->quantity ?? '0'), '0', StockLedger::QUANTITY_SCALE),
                    itemCost: (string) $row->cost_price,
                ),
                $row->item_number === null || $row->item_number === '' ? null : (string) $row->item_number,
                $current,
            );

            if ($showEmpty || ! $line->isEmpty()) {
                $lines[] = $line;
            }
        }

        usort($lines, static fn (InventoryValuationLine $first, InventoryValuationLine $second): int => [$first->position->itemName, $first->position->itemId] <=> [$second->position->itemName, $second->position->itemId]);

        return $lines;
    }
}
