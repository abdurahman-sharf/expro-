<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the books stand at, account by account and branch by branch, and how the stock ties to them, so that the state
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
     * The books of every company, each under its id.
     *
     * @return array{companies: array<int, array<string, mixed>>}
     */
    public function takeAll(): array
    {
        $companies = [];

        foreach ($this->db->table('companies')->select('company_id')->orderBy('company_id', 'ASC')->get()->getResult() as $company) {
            $companies[(int) $company->company_id] = $this->take((int) $company->company_id)['companies'][(int) $company->company_id];
        }

        return ['companies' => $companies];
    }

    /**
     * The books of one company, the one every request works in unless another is named.
     *
     * @return array{companies: array<int, array{
     *     accounts: array<int, array{code: string, name: string, debit: string, credit: string, branches: array<int, array{debit: string, credit: string}>}>,
     *     totals: array{debit: string, credit: string},
     *     stock: array<int, array{account_id: ?int, account_balance: string, movements_value: string, unattributed: string}>
     * }>}
     */
    public function take(?int $companyId = null): array
    {
        $companyId ??= CompanyContext::id();

        $rows = $this->db->table('accounting_accounts a')
            ->select('a.account_id, a.code, a.name, j.branch_id, COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit', false)
            ->join('accounting_journal_lines l', 'l.account_id = a.account_id')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('a.company_id', $companyId)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->groupBy('a.account_id, a.code, a.name, j.branch_id')
            ->orderBy('a.account_id', 'ASC')
            ->orderBy('j.branch_id', 'ASC')
            ->get()
            ->getResult();

        $accounts = [];
        $totals   = ['debit' => '0.0000', 'credit' => '0.0000'];

        foreach ($rows as $row) {
            $accountId = (int) $row->account_id;
            $debit     = bcadd((string) $row->debit, '0', self::SCALE);
            $credit    = bcadd((string) $row->credit, '0', self::SCALE);

            $accounts[$accountId] ??= ['code' => (string) $row->code, 'name' => (string) $row->name, 'debit' => '0.0000', 'credit' => '0.0000', 'branches' => []];

            $accounts[$accountId]['debit']                           = bcadd($accounts[$accountId]['debit'], $debit, self::SCALE);
            $accounts[$accountId]['credit']                          = bcadd($accounts[$accountId]['credit'], $credit, self::SCALE);
            $accounts[$accountId]['branches'][(int) $row->branch_id] = ['debit' => $debit, 'credit' => $credit];

            $totals = ['debit' => bcadd($totals['debit'], $debit, self::SCALE), 'credit' => bcadd($totals['credit'], $credit, self::SCALE)];
        }

        return ['companies' => [$companyId => ['accounts' => $accounts, 'totals' => $totals, 'stock' => $this->stock($companyId, $accounts)]]];
    }

    /**
     * For each stock location of the company that has an account or valued movements: what the
     * account stands at, what the movements add up to, and what is left between the two.
     *
     * @param array<int, array{debit: string, credit: string}> $accounts
     *
     * @return array<int, array{account_id: ?int, account_balance: string, movements_value: string, unattributed: string}>
     */
    private function stock(int $companyId, array $accounts): array
    {
        $stock = [];

        $owned = $this->db->table('accounting_accounts')
            ->select('account_id, entity_id')
            ->where('company_id', $companyId)
            ->where('entity_type', EntityAccountService::STOCK_LOCATION)
            ->get()
            ->getResult();

        foreach ($owned as $account) {
            $accountId = (int) $account->account_id;
            $balance   = isset($accounts[$accountId]) ? bcsub($accounts[$accountId]['debit'], $accounts[$accountId]['credit'], self::SCALE) : '0.0000';

            $stock[(int) $account->entity_id] = ['account_id' => $accountId, 'account_balance' => $balance, 'movements_value' => '0.0000', 'unattributed' => $balance];
        }

        $moved = $this->db->table('accounting_stock_entries')
            ->select('location_id, COALESCE(SUM(value), 0) AS value', false)
            ->where('company_id', $companyId)
            ->groupBy('location_id')
            ->get()
            ->getResult();

        foreach ($moved as $location) {
            $locationId = (int) $location->location_id;
            $value      = bcadd((string) $location->value, '0', self::SCALE);

            $stock[$locationId] ??= ['account_id' => null, 'account_balance' => '0.0000', 'movements_value' => '0.0000', 'unattributed' => '0.0000'];

            $stock[$locationId]['movements_value'] = $value;
            $stock[$locationId]['unattributed']    = bcsub($stock[$locationId]['account_balance'], $value, self::SCALE);
        }

        ksort($stock);

        return $stock;
    }

    /**
     * Every figure of the two snapshots that is not the same: of a company, an account, an account
     * in a branch or a stock location, those that only one of the two has included. The code and
     * the name of an account are left out.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<array{company: int|string, what: string, id: int|string, field: string, before: ?string, after: ?string}>
     */
    public static function differences(array $before, array $after): array
    {
        $differences = [];

        foreach (self::ids($before['companies'] ?? [], $after['companies'] ?? []) as $company) {
            $old = $before['companies'][$company] ?? [];
            $new = $after['companies'][$company] ?? [];

            foreach (['debit', 'credit'] as $side) {
                self::compare($differences, $company, 'totals', 'all', $side, $old['totals'][$side] ?? null, $new['totals'][$side] ?? null);
            }

            foreach (self::ids($old['accounts'] ?? [], $new['accounts'] ?? []) as $id) {
                foreach (['debit', 'credit'] as $side) {
                    self::compare($differences, $company, 'account', $id, $side, $old['accounts'][$id][$side] ?? null, $new['accounts'][$id][$side] ?? null);
                }

                foreach (self::ids($old['accounts'][$id]['branches'] ?? [], $new['accounts'][$id]['branches'] ?? []) as $branch) {
                    foreach (['debit', 'credit'] as $side) {
                        self::compare($differences, $company, 'account_branch', "{$id}/{$branch}", $side, $old['accounts'][$id]['branches'][$branch][$side] ?? null, $new['accounts'][$id]['branches'][$branch][$side] ?? null);
                    }
                }
            }

            foreach (self::ids($old['stock'] ?? [], $new['stock'] ?? []) as $id) {
                foreach (['account_balance', 'movements_value', 'unattributed'] as $field) {
                    self::compare($differences, $company, 'stock_location', $id, $field, $old['stock'][$id][$field] ?? null, $new['stock'][$id][$field] ?? null);
                }
            }
        }

        return $differences;
    }

    /**
     * The stock locations whose account does not stand at what their valued movements add up to,
     * each as company/location.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return list<string>
     */
    public static function untiedStock(array $snapshot): array
    {
        $untied = [];

        foreach ($snapshot['companies'] ?? [] as $company => $books) {
            foreach ($books['stock'] ?? [] as $id => $location) {
                if (bccomp((string) $location['unattributed'], '0', self::SCALE) !== 0) {
                    $untied[] = "{$company}/{$id}";
                }
            }
        }

        return $untied;
    }

    /**
     * @param array<int|string, mixed> $before
     * @param array<int|string, mixed> $after
     *
     * @return list<int|string>
     */
    private static function ids(array $before, array $after): array
    {
        $ids = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($ids);

        return $ids;
    }

    /**
     * @param list<array{company: int|string, what: string, id: int|string, field: string, before: ?string, after: ?string}> $differences
     */
    private static function compare(array &$differences, int|string $company, string $what, int|string $id, string $field, mixed $before, mixed $after): void
    {
        $before = $before === null ? null : (string) $before;
        $after  = $after === null ? null : (string) $after;

        if ($before === null || $after === null ? $before !== $after : bccomp($before, $after, self::SCALE) !== 0) {
            $differences[] = ['company' => $company, 'what' => $what, 'id' => $id, 'field' => $field, 'before' => $before, 'after' => $after];
        }
    }
}
