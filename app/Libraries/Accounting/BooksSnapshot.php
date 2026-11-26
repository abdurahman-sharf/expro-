<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the books stand at, account by account, and how the stock ties to them, so that the state
 * before a migration can be set beside the state after it. Accounts are matched by their id: a
 * migration may change the code or the place of an account, never what was posted to it.
 */
final class BooksSnapshot
{
    private const SCALE = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array{
     *     accounts: array<int, array{code: string, name: string, debit: string, credit: string}>,
     *     totals: array{debit: string, credit: string},
     *     stock: array<int, array{account_id: ?int, account_balance: string, movements_value: string, unattributed: string}>
     * }
     */
    public function take(): array
    {
        $rows = $this->db->table('accounting_accounts a')
            ->select('a.account_id, a.code, a.name, COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit', false)
            ->join('accounting_journal_lines l', 'l.account_id = a.account_id')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('a.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->groupBy('a.account_id, a.code, a.name')
            ->orderBy('a.account_id', 'ASC')
            ->get()
            ->getResult();

        $accounts = [];
        $totals   = ['debit' => '0.0000', 'credit' => '0.0000'];

        foreach ($rows as $row) {
            $debit  = bcadd((string) $row->debit, '0', self::SCALE);
            $credit = bcadd((string) $row->credit, '0', self::SCALE);

            $accounts[(int) $row->account_id] = ['code' => (string) $row->code, 'name' => (string) $row->name, 'debit' => $debit, 'credit' => $credit];
            $totals                           = ['debit' => bcadd($totals['debit'], $debit, self::SCALE), 'credit' => bcadd($totals['credit'], $credit, self::SCALE)];
        }

        $stock = [];

        foreach ((new StockLedger($this->db))->reconcile() as $location) {
            $locationId = $location['location_id'];
            unset($location['location_id']);

            $stock[$locationId] = $location;
        }

        return ['accounts' => $accounts, 'totals' => $totals, 'stock' => $stock];
    }

    /**
     * Every figure of the two snapshots that is not the same, an account or a stock location that
     * only one of them has included. The code and the name of an account are left out.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<array{what: string, id: int|string, field: string, before: ?string, after: ?string}>
     */
    public static function differences(array $before, array $after): array
    {
        $differences = [];

        foreach (['debit', 'credit'] as $side) {
            self::compare($differences, 'totals', 'all', $side, $before['totals'][$side] ?? null, $after['totals'][$side] ?? null);
        }

        foreach ([['accounts', 'account', ['debit', 'credit']], ['stock', 'stock_location', ['account_balance', 'movements_value', 'unattributed']]] as [$part, $what, $fields]) {
            $ids = array_unique([...array_keys($before[$part] ?? []), ...array_keys($after[$part] ?? [])]);
            sort($ids);

            foreach ($ids as $id) {
                foreach ($fields as $field) {
                    self::compare($differences, $what, $id, $field, $before[$part][$id][$field] ?? null, $after[$part][$id][$field] ?? null);
                }
            }
        }

        return $differences;
    }

    /**
     * The stock locations whose account does not stand at what their valued movements add up to.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return list<int>
     */
    public static function untiedStock(array $snapshot): array
    {
        return array_values(array_filter(
            array_keys($snapshot['stock'] ?? []),
            static fn (int|string $id): bool => bccomp((string) $snapshot['stock'][$id]['unattributed'], '0', self::SCALE) !== 0,
        ));
    }

    /**
     * @param list<array{what: string, id: int|string, field: string, before: ?string, after: ?string}> $differences
     */
    private static function compare(array &$differences, string $what, int|string $id, string $field, mixed $before, mixed $after): void
    {
        $before = $before === null ? null : (string) $before;
        $after  = $after === null ? null : (string) $after;

        if ($before === null || $after === null ? $before !== $after : bccomp($before, $after, self::SCALE) !== 0) {
            $differences[] = ['what' => $what, 'id' => $id, 'field' => $field, 'before' => $before, 'after' => $after];
        }
    }
}
