<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Where an account sits in the chart of the company: the accounts the chart names by their system
 * key, and every account under a main account. It is what a report needs to say that a line of a
 * journal belongs to a customer, a supplier, a treasury or a bank.
 */
final class ChartLookup
{
    private BaseConnection $db;

    /**
     * @var array<int, object{account_id: int, parent_id: ?int, code: string, name: string, account_type: string, is_group: int, system_key: ?string}>|null
     */
    private ?array $accounts = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array<int, object{account_id: int, parent_id: ?int, code: string, name: string, account_type: string, is_group: int, system_key: ?string}> The accounts of the company by id, in the order of their codes.
     */
    public function accounts(): array
    {
        if ($this->accounts === null) {
            $this->accounts = [];

            foreach ($this->db->table('accounting_accounts')->select('account_id, parent_id, code, name, account_type, is_group, system_key')->where('company_id', CompanyContext::id())->orderBy('code', 'ASC')->get()->getResult() as $account) {
                $account->account_id = (int) $account->account_id;
                $account->parent_id  = $account->parent_id === null ? null : (int) $account->parent_id;

                $this->accounts[$account->account_id] = $account;
            }
        }

        return $this->accounts;
    }

    /**
     * @return list<int> The accounts the chart names by these system keys.
     */
    public function idsOfKeys(string ...$systemKeys): array
    {
        $ids = [];

        foreach ($this->accounts() as $account) {
            if ($account->system_key !== null && in_array($account->system_key, $systemKeys, true)) {
                $ids[] = $account->account_id;
            }
        }

        return $ids;
    }

    /**
     * @return list<int> The accounts with these codes.
     */
    public function idsOfCodes(string ...$codes): array
    {
        $ids = [];

        foreach ($this->accounts() as $account) {
            if (in_array((string) $account->code, $codes, true)) {
                $ids[] = $account->account_id;
            }
        }

        return $ids;
    }

    /**
     * Each account asked for and every account under it.
     *
     * @param list<int> $roots
     *
     * @return array<int, true>
     */
    public function subtreeOf(array $roots): array
    {
        $accounts = $this->accounts();
        $children = [];

        foreach ($accounts as $account) {
            if ($account->parent_id !== null && isset($accounts[$account->parent_id])) {
                $children[$account->parent_id][] = $account->account_id;
            }
        }

        $found = [];
        $queue = [];

        foreach ($roots as $root) {
            if (isset($accounts[$root])) {
                $found[$root] = true;
                $queue[]      = $root;
            }
        }

        while ($queue !== []) {
            foreach ($children[array_pop($queue)] ?? [] as $childId) {
                if (! isset($found[$childId])) {
                    $found[$childId] = true;
                    $queue[]         = $childId;
                }
            }
        }

        return $found;
    }

    /**
     * The accounts of the treasuries and the banks: what the company holds in money.
     *
     * @return array<int, true>
     */
    public function cashAccounts(): array
    {
        return $this->subtreeOf($this->idsOfKeys('treasuries', 'banks'));
    }
}
