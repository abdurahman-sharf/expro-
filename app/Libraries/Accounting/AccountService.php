<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class AccountService
{
    use RunsInTransaction;

    private const CODE_PATTERN = '/^[0-9][0-9\-]{0,31}$/';
    private const NAME_MAX     = 255;

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
    }

    /**
     * @param array{parent_id?: int|string|null, code?: string, name?: string, is_group?: mixed, contra?: mixed} $input
     *
     * @throws AccountingException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->guard->require(AccountingGuard::CHART_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $parent = $this->requireParent((int) ($input['parent_id'] ?? 0));
        $code   = $this->normalizeCode((string) ($input['code'] ?? ''));
        $name   = $this->normalizeName((string) ($input['name'] ?? ''));

        $this->assertCodeFree($code, null);
        $this->assertNameFree($name, (int) $parent->account_id, null);

        $type          = AccountType::from($parent->account_type);
        $normalBalance = $this->normalBalanceFor($type, $this->flag($input['contra'] ?? false));

        return $this->transactional(function () use ($parent, $code, $name, $type, $normalBalance, $input, $employeeId): int {
            $this->db->table('accounting_accounts')->insert([
                'parent_id'      => (int) $parent->account_id,
                'code'           => $code,
                'name'           => $name,
                'account_type'   => $type->value,
                'normal_balance' => $normalBalance->value,
                'is_group'       => (int) $this->flag($input['is_group'] ?? false),
                'is_system'      => 0,
                'active'         => 1,
            ]);

            $accountId = (int) $this->db->insertID();

            $this->audit->write('account', $accountId, 'created', $employeeId, JournalOrigin::User, [
                'code'      => $code,
                'name'      => $name,
                'parent_id' => (int) $parent->account_id,
            ]);

            return $accountId;
        });
    }

    /**
     * The parent and the type never change. The code and the contra flag change only while
     * the account has no journal lines, and the code of a system account never changes.
     *
     * @param array{code?: string, name?: string, contra?: mixed} $input
     *
     * @throws AccountingException
     */
    public function update(int $accountId, array $input, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHART_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $account = $this->requireAccount($accountId);
        $used    = $this->isUsed($accountId);
        $changes = [];

        if (array_key_exists('name', $input)) {
            $name = $this->normalizeName((string) $input['name']);

            if ($name !== $account->name) {
                if ($account->entity_type !== null) {
                    throw new AccountingException(AccountingError::SystemAccountLocked, 'The name of an entity account follows the entity.');
                }

                $this->assertNameFree($name, $account->parent_id === null ? null : (int) $account->parent_id, $accountId);
                $changes['name'] = $name;
            }
        }

        if (array_key_exists('code', $input)) {
            $code = $this->normalizeCode((string) $input['code']);

            if ($code !== $account->code) {
                if ((int) $account->is_system === 1) {
                    throw new AccountingException(AccountingError::SystemAccountLocked, 'The code of a system account cannot change.');
                }

                if ($used) {
                    throw new AccountingException(AccountingError::AccountInUse, 'The code of an account with journal lines cannot change.');
                }

                $this->assertCodeFree($code, $accountId);
                $changes['code'] = $code;
            }
        }

        if (array_key_exists('contra', $input)) {
            $normalBalance = $this->normalBalanceFor(AccountType::from($account->account_type), $this->flag($input['contra']));

            if ($normalBalance->value !== $account->normal_balance) {
                if ($used) {
                    throw new AccountingException(AccountingError::AccountInUse, 'The direction of an account with journal lines cannot change.');
                }

                $changes['normal_balance'] = $normalBalance->value;
            }
        }

        if ($changes === []) {
            return;
        }

        $this->transactional(function () use ($accountId, $changes, $account, $employeeId): void {
            $this->db->table('accounting_accounts')->where('account_id', $accountId)->update($changes);

            $this->audit->write('account', $accountId, 'updated', $employeeId, JournalOrigin::User, [
                'before' => array_intersect_key((array) $account, $changes),
                'after'  => $changes,
            ]);
        });
    }

    /**
     * @throws AccountingException
     */
    public function deactivate(int $accountId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHART_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $account = $this->requireAccount($accountId);

        if ((int) $account->active === 0) {
            throw new AccountingException(AccountingError::AlreadyInactive, 'The account is already inactive.');
        }

        if ((int) $account->is_system === 1) {
            throw new AccountingException(AccountingError::SystemAccountLocked, 'A system account cannot be deactivated.');
        }

        if ($this->isRouted($accountId)) {
            throw new AccountingException(AccountingError::AccountRouted, 'The account is a routing target.');
        }

        if ($this->db->table('accounting_accounts')->where('parent_id', $accountId)->where('active', 1)->countAllResults() > 0) {
            throw new AccountingException(AccountingError::HasActiveChildren, 'The account has active children.');
        }

        $this->setActive($accountId, 0, 'deactivated', $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function activate(int $accountId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHART_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $account = $this->requireAccount($accountId);

        if ((int) $account->active === 1) {
            throw new AccountingException(AccountingError::AlreadyActive, 'The account is already active.');
        }

        if ($account->parent_id !== null && (int) $this->requireAccount((int) $account->parent_id)->active === 0) {
            throw new AccountingException(AccountingError::ParentInactive, 'The parent account is inactive.');
        }

        $this->setActive($accountId, 1, 'activated', $employeeId);
    }

    /**
     * Only a custom account with no children, no journal lines and no routing can be deleted.
     *
     * @throws AccountingException
     */
    public function delete(int $accountId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::CHART_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $account = $this->requireAccount($accountId);

        if ((int) $account->is_system === 1) {
            throw new AccountingException(AccountingError::SystemAccountLocked, 'A system account cannot be deleted.');
        }

        if ($this->db->table('accounting_accounts')->where('parent_id', $accountId)->countAllResults() > 0) {
            throw new AccountingException(AccountingError::HasChildren, 'The account has children.');
        }

        if ($this->isUsed($accountId)) {
            throw new AccountingException(AccountingError::AccountInUse, 'The account has journal lines.');
        }

        if ($this->isRouted($accountId)) {
            throw new AccountingException(AccountingError::AccountRouted, 'The account is a routing target.');
        }

        $this->transactional(function () use ($accountId, $account, $employeeId): void {
            $this->db->table('accounting_accounts')->where('account_id', $accountId)->delete();

            $this->audit->write('account', $accountId, 'deleted', $employeeId, JournalOrigin::User, [
                'code' => $account->code,
                'name' => $account->name,
            ]);
        });
    }

    public function find(int $accountId): ?object
    {
        return $this->db->table('accounting_accounts')->where('account_id', $accountId)->get()->getRow();
    }

    public function isUsed(int $accountId): bool
    {
        return $this->db->table('accounting_journal_lines')->where('account_id', $accountId)->countAllResults() > 0;
    }

    public function isRouted(int $accountId): bool
    {
        return $this->db->table('accounting_routing')->where('account_id', $accountId)->countAllResults() > 0
            || $this->db->table('expense_categories')->where('account_id', $accountId)->countAllResults() > 0;
    }

    /**
     * Every account in code order, for the tree.
     *
     * @return list<object>
     */
    public function all(): array
    {
        return $this->db->table('accounting_accounts')
            ->select('account_id, parent_id, code, name, account_type, normal_balance, is_group, is_system, active')
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Direct children of an account (the roots when null) with what the screen needs to
     * decide which actions to offer.
     *
     * @return list<object>
     */
    public function children(?int $parentId): array
    {
        return $parentId === null
            ? $this->flagged('a.parent_id IS NULL', [])
            : $this->flagged('a.parent_id = ?', [$parentId]);
    }

    /**
     * @return list<object>
     */
    public function search(string $term, int $limit = 50): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $escaped = $this->db->escapeLikeString($term);

        return $this->flagged(
            "(a.code LIKE '{$escaped}%' ESCAPE '!' OR a.name LIKE '%{$escaped}%' ESCAPE '!')",
            [],
            $limit,
        );
    }

    /**
     * An account with the same flags the lists carry.
     */
    public function findFlagged(int $accountId): ?object
    {
        return $this->flagged('a.account_id = ?', [$accountId])[0] ?? null;
    }

    /**
     * Balances of posted and reversed journals, each account including its descendants.
     * The balance is signed to the account's normal direction. With a list of branches only the
     * journals of those branches count, so a reader never sees another branch's activity.
     *
     * @param list<int>|null $branchIds Null counts every branch.
     *
     * @return array<int, array{debit: string, credit: string, balance: string}>
     */
    public function balances(?array $branchIds = null): array
    {
        $accounts = $this->all();
        $own      = [];
        $scope    = '';

        if ($branchIds !== null) {
            $scope = $branchIds === [] ? ' AND 1 = 0' : ' AND j.branch_id IN (' . implode(',', array_map('intval', $branchIds)) . ')';
        }

        $rows = $this->db->query(
            'SELECT l.account_id, SUM(l.debit) AS debit, SUM(l.credit) AS credit
             FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l
             JOIN ' . $this->db->prefixTable('accounting_journals') . ' j ON j.journal_id = l.journal_id
             WHERE j.status IN (?, ?)' . $scope . '
             GROUP BY l.account_id',
            [JournalStatus::Posted->value, JournalStatus::Reversed->value],
        )->getResult();

        foreach ($rows as $row) {
            $own[(int) $row->account_id] = [$row->debit, $row->credit];
        }

        $parents = [];
        $totals  = [];

        foreach ($accounts as $account) {
            $parents[(int) $account->account_id] = $account->parent_id === null ? null : (int) $account->parent_id;
            $totals[(int) $account->account_id]  = ['0.0000', '0.0000'];
        }

        foreach ($own as $accountId => [$debit, $credit]) {
            for ($current = $accountId; $current !== null; $current = $parents[$current] ?? null) {
                $totals[$current][0] = bcadd($totals[$current][0], $debit, 4);
                $totals[$current][1] = bcadd($totals[$current][1], $credit, 4);
            }
        }

        $balances = [];

        foreach ($accounts as $account) {
            [$debit, $credit] = $totals[(int) $account->account_id];

            $balances[(int) $account->account_id] = [
                'debit'   => $debit,
                'credit'  => $credit,
                'balance' => $account->normal_balance === NormalBalance::Debit->value
                    ? bcsub($debit, $credit, 4)
                    : bcsub($credit, $debit, 4),
            ];
        }

        return $balances;
    }

    /**
     * The next free code among the children of a parent, following the chart's own pattern
     * of one or two more digits than the parent.
     */
    public function suggestCode(int $parentId): string
    {
        $parent = $this->requireAccount($parentId);

        $siblings = $this->db->table('accounting_accounts')->select('code')->where('parent_id', $parentId)->get()->getResultArray();
        $numeric  = array_filter(array_column($siblings, 'code'), static fn (string $code): bool => ctype_digit($code));

        $candidate = $numeric === []
            ? $parent->code . (strlen($parent->code) === 1 ? '1' : '01')
            : (string) (max(array_map('intval', $numeric)) + 1);

        while ($this->codeExists($candidate)) {
            $candidate = (string) ((int) $candidate + 1);
        }

        return $candidate;
    }

    /**
     * @param list<int> $params
     *
     * @return list<object>
     */
    private function flagged(string $where, array $params, ?int $limit = null): array
    {
        $accounts = $this->db->prefixTable('accounting_accounts');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $routing  = $this->db->prefixTable('accounting_routing');
        $limitSql = $limit === null ? '' : ' LIMIT ' . max(1, $limit);

        return $this->db->query(
            "SELECT a.account_id, a.parent_id, a.code, a.name, a.account_type, a.normal_balance, a.is_group, a.is_system, a.active,
                EXISTS (SELECT 1 FROM {$accounts} c WHERE c.parent_id = a.account_id) AS has_children,
                EXISTS (SELECT 1 FROM {$lines} l WHERE l.account_id = a.account_id) AS used,
                EXISTS (SELECT 1 FROM {$routing} r WHERE r.account_id = a.account_id) AS routed
             FROM {$accounts} a
             WHERE {$where}
             ORDER BY a.code ASC{$limitSql}",
            $params,
        )->getResult();
    }

    private function setActive(int $accountId, int $active, string $action, ?int $employeeId): void
    {
        $this->transactional(function () use ($accountId, $active, $action, $employeeId): void {
            $this->db->table('accounting_accounts')->where('account_id', $accountId)->update(['active' => $active]);
            $this->audit->write('account', $accountId, $action, $employeeId, JournalOrigin::User);
        });
    }

    private function requireAccount(int $accountId): object
    {
        $account = $this->find($accountId);

        if ($account === null) {
            throw new AccountingException(AccountingError::AccountNotFound, "Account {$accountId} does not exist.", ['account_id' => $accountId]);
        }

        return $account;
    }

    private function requireParent(int $parentId): object
    {
        $parent = $parentId > 0 ? $this->find($parentId) : null;

        if ($parent === null || (int) $parent->is_group !== 1) {
            throw new AccountingException(AccountingError::ParentInvalid, 'The parent must be an existing group account.', ['parent_id' => $parentId]);
        }

        if ((int) $parent->active === 0) {
            throw new AccountingException(AccountingError::ParentInactive, 'The parent account is inactive.');
        }

        return $parent;
    }

    private function normalizeCode(string $code): string
    {
        $code = trim($code);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new AccountingException(AccountingError::InvalidCode, 'The code must be 1 to 32 digits or dashes, starting with a digit.', ['code' => $code]);
        }

        return $code;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::InvalidName, 'The name must be 1 to 255 characters.');
        }

        return $name;
    }

    private function assertCodeFree(string $code, ?int $exceptAccountId): void
    {
        $builder = $this->db->table('accounting_accounts')->where('code', $code);

        if ($exceptAccountId !== null) {
            $builder->where('account_id !=', $exceptAccountId);
        }

        if ($builder->countAllResults() > 0) {
            throw new AccountingException(AccountingError::CodeTaken, 'The code is already used.', ['code' => $code]);
        }
    }

    private function codeExists(string $code): bool
    {
        return $this->db->table('accounting_accounts')->where('code', $code)->countAllResults() > 0;
    }

    private function assertNameFree(string $name, ?int $parentId, ?int $exceptAccountId): void
    {
        $builder = $this->db->table('accounting_accounts')->where('name', $name);

        $parentId === null ? $builder->where('parent_id IS NULL') : $builder->where('parent_id', $parentId);

        if ($exceptAccountId !== null) {
            $builder->where('account_id !=', $exceptAccountId);
        }

        if ($builder->countAllResults() > 0) {
            throw new AccountingException(AccountingError::NameTaken, 'The name is already used under the same parent.', ['name' => $name]);
        }
    }

    private function normalBalanceFor(AccountType $type, bool $contra): NormalBalance
    {
        $natural = $type->normalBalance();

        if (! $contra) {
            return $natural;
        }

        return $natural === NormalBalance::Debit ? NormalBalance::Credit : NormalBalance::Debit;
    }

    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }
}
