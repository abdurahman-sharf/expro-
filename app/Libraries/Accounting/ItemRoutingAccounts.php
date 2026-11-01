<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The account an item names for a routing row that takes the accounts of products: where the revenue
 * of its sales and the returns of them are posted when the row is set to per item. They are kept in a
 * table of their own, by item and routing key, so that the table of the items stays as it is. Reading
 * is open to posting and to the screens, writing needs the routing permission and every branch, as the
 * routing itself does, and every change is audited.
 */
final class ItemRoutingAccounts
{
    use RunsInTransaction;

    private BaseConnection $db;
    private AuditLog $audit;
    private ?AccountingGuard $guard;
    private ?BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard;
        $this->branches = $branches;
    }

    /**
     * The accounts the item names, by routing key.
     *
     * @return array<string, object{account_id: int, code: string, name: string, active: int, is_group: int}>
     */
    public function ofItem(int $itemId): array
    {
        $rows  = $this->builder()->where('i.item_id', $itemId)->get()->getResult();
        $byKey = [];

        foreach ($rows as $row) {
            $byKey[(string) $row->routing_key] = $row;
        }

        return $byKey;
    }

    /**
     * The accounts the items name for one row, with what posting needs to know to refuse one that
     * cannot take entries any more.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, object> By item. An item that names nothing is not in it.
     */
    public function forItems(RoutingKey $key, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $byItem = [];

        foreach ($this->builder()->where('i.routing_key', $key->value)->whereIn('i.item_id', $itemIds)->get()->getResult() as $row) {
            $byItem[(int) $row->item_id] = $row;
        }

        return $byItem;
    }

    /**
     * Sets the accounts an item names. An empty account takes the one it had away, so that the
     * item falls back to the account of the row. Nothing is written when one of them is refused.
     *
     * @param array<string, int|string|null> $accounts By routing key.
     *
     * @throws AccountingException|BranchException|RoutingException
     */
    public function save(int $itemId, array $accounts, ?int $employeeId = null): void
    {
        $this->guard ??= new AccountingGuard();
        $this->branches ??= new BranchGuard($this->db);

        $this->guard->require(AccountingGuard::ROUTING_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        if ($this->db->table('items')->where('item_id', $itemId)->countAllResults() === 0) {
            throw new AccountingException(AccountingError::ItemRoutingItemNotFound, "Item {$itemId} does not exist.", ['item_id' => $itemId]);
        }

        $stored   = $this->ofItem($itemId);
        $existing = $this->accountsById();
        $errors   = [];
        $changes  = [];

        foreach ($accounts as $keyName => $value) {
            $keyName = (string) $keyName;
            $key     = RoutingKey::tryFrom($keyName);

            if ($key === null || ! $key->takesItemAccounts()) {
                $errors[$keyName] = AccountingError::ItemRoutingKeyInvalid;

                continue;
            }

            $accountId = $this->accountId($value);
            $problem   = $accountId === false ? AccountingError::AccountNotFound : $this->problem($key, $accountId, $existing);

            if ($problem !== null) {
                $errors[$keyName] = $problem;

                continue;
            }

            $before = isset($stored[$keyName]) ? (int) $stored[$keyName]->account_id : null;

            if ($before !== $accountId) {
                $changes[$keyName] = ['before' => $before, 'after' => $accountId];
            }
        }

        if ($errors !== []) {
            throw new RoutingException($errors);
        }

        if ($changes === []) {
            return;
        }

        $this->transactional(function () use ($itemId, $changes, $existing, $employeeId): void {
            foreach ($changes as $keyName => $change) {
                if ($change['after'] === null) {
                    $this->db->table('item_routing_accounts')->where('item_id', $itemId)->where('routing_key', $keyName)->delete();
                } elseif ($change['before'] === null) {
                    $this->db->table('item_routing_accounts')->insert(['item_id' => $itemId, 'routing_key' => $keyName, 'account_id' => $change['after']]);
                } else {
                    $this->db->table('item_routing_accounts')->where('item_id', $itemId)->where('routing_key', $keyName)->update(['account_id' => $change['after']]);
                }

                $this->audit->write('item', $itemId, 'item_routing_accounts_changed', $employeeId, JournalOrigin::User, [
                    'key'    => $keyName,
                    'before' => $this->describe($change['before'], $existing),
                    'after'  => $this->describe($change['after'], $existing),
                ]);
            }
        });
    }

    /**
     * The accounts an item may name for the row: open revenue accounts that take entries, leaving out
     * the ones the chart reserves for another row and the ones of a customer, a supplier or a location.
     *
     * @return list<object{account_id: int, code: string, name: string}>
     */
    public function candidates(RoutingKey $key): array
    {
        $types = array_map(static fn (AccountType $type): string => $type->value, $key->allowedAccountTypes());

        if (! $key->takesItemAccounts() || $types === []) {
            return [];
        }

        $accepted = $key->acceptedSystemKeys();

        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('company_id', CompanyContext::id())
            ->where('is_group', 0)
            ->where('active', 1)
            ->where('entity_type IS NULL')
            ->whereIn('account_type', $types)
            ->groupStart()
            ->where('system_key IS NULL')
            ->orWhereIn('system_key', array_values(array_filter($accepted, static fn (?string $systemKey): bool => $systemKey !== null)) ?: [''])
            ->groupEnd()
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The items that name an account which cannot take entries any more: it was deactivated, turned into
     * a group, or reserved for another purpose after the item named it. Posting stops on such an item.
     *
     * @return list<array{item_id: int, name: string, routing_key: string, account_id: int, code: string, problem: string}>
     */
    public function problems(): array
    {
        $existing = $this->accountsById();
        $rows     = $this->builder()->select('it.name AS item_name')->join('items it', 'it.item_id = i.item_id')->where('it.deleted', 0)->orderBy('i.item_id', 'ASC')->orderBy('i.routing_key', 'ASC')->get()->getResult();
        $problems = [];

        foreach ($rows as $row) {
            $key = RoutingKey::tryFrom((string) $row->routing_key);

            if ($key === null || ! $key->takesItemAccounts()) {
                $problems[] = $this->described($row, AccountingError::ItemRoutingKeyInvalid);

                continue;
            }

            $problem = $this->problem($key, (int) $row->account_id, $existing);

            if ($problem !== null) {
                $problems[] = $this->described($row, $problem);
            }
        }

        return $problems;
    }

    /**
     * How many items name an account of their own, by routing key.
     *
     * @return array<string, int>
     */
    public function counts(): array
    {
        $rows = $this->db->table('item_routing_accounts i')
            ->select('i.routing_key, COUNT(*) AS items', false)
            ->join('items it', 'it.item_id = i.item_id')
            ->where('it.deleted', 0)
            ->groupBy('i.routing_key')
            ->get()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->routing_key] = (int) $row->items;
        }

        return $counts;
    }

    private function builder(): BaseBuilder
    {
        return $this->db->table('item_routing_accounts i')
            ->select('i.item_id, i.routing_key, a.account_id, a.company_id, a.code, a.name, a.account_type, a.is_group, a.active, a.entity_type, a.system_key')
            ->join('accounting_accounts a', 'a.account_id = i.account_id');
    }

    /**
     * Why an account an item names cannot take the entries of the row, or null when it can: it has to
     * be one of this company, open, take entries, be of a type the row takes, and not be reserved for
     * another row or belong to a customer, a supplier or a location.
     */
    public function refusal(RoutingKey $key, object $account): ?AccountingError
    {
        if ((int) $account->company_id !== CompanyContext::id()) {
            return AccountingError::AccountNotFound;
        }

        $problem = RoutingService::accountProblem((int) $account->account_id, [(int) $account->account_id => $account], false, $key->allowedAccountTypes(), $key->acceptedSystemKeys(), false);

        return $problem ?? ($account->entity_type === null ? null : AccountingError::RoutingAccountReserved);
    }

    /**
     * @param array<int, object> $accounts
     */
    private function problem(RoutingKey $key, ?int $accountId, array $accounts): ?AccountingError
    {
        if ($accountId === null) {
            return null;
        }

        return isset($accounts[$accountId]) ? $this->refusal($key, $accounts[$accountId]) : AccountingError::AccountNotFound;
    }

    /**
     * @return array{item_id: int, name: string, routing_key: string, account_id: int, code: string, problem: string}
     */
    private function described(object $row, AccountingError $problem): array
    {
        return [
            'item_id'     => (int) $row->item_id,
            'name'        => (string) $row->item_name,
            'routing_key' => (string) $row->routing_key,
            'account_id'  => (int) $row->account_id,
            'code'        => (string) $row->code,
            'problem'     => $problem->value,
        ];
    }

    /**
     * @return array<int, object>
     */
    private function accountsById(): array
    {
        $rows = $this->db->table('accounting_accounts')
            ->select('account_id, company_id, code, name, account_type, is_group, active, system_key, entity_type')
            ->where('company_id', CompanyContext::id())
            ->get()
            ->getResult();

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row->account_id] = $row;
        }

        return $byId;
    }

    /**
     * @return false|int|null The account, null for an empty value, which clears it, and false for a value that is not an account.
     */
    private function accountId(mixed $value): false|int|null
    {
        if ($value === null || (is_scalar($value) && trim((string) $value) === '')) {
            return null;
        }

        if (! is_scalar($value) || ! ctype_digit(trim((string) $value)) || (int) trim((string) $value) < 1) {
            return false;
        }

        return (int) trim((string) $value);
    }

    /**
     * @param array<int, object> $accounts
     *
     * @return array{account_id: int, code: string, name: string}|null
     */
    private function describe(?int $accountId, array $accounts): ?array
    {
        $account = $accountId === null ? null : ($accounts[$accountId] ?? null);

        return $account === null ? null : ['account_id' => (int) $account->account_id, 'code' => (string) $account->code, 'name' => (string) $account->name];
    }
}
