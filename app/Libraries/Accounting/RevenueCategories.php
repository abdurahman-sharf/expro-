<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The categories of receipt vouchers and the account each is credited to. A category without an
 * account is credited to the account of other revenue the routing names. Which account a category
 * goes to is a routing decision, so it asks for the permission to change the routing and for every
 * branch, and a change is audited. A deleted category stays on the vouchers that carry it.
 */
final class RevenueCategories
{
    use RunsInTransaction;

    public const ENTITY    = 'revenue_category';
    private const NAME_MAX = 255;
    private const TEXT_MAX = 255;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * Saves a category. The account is chosen only when the input names one, and a value the category
     * already has changes nothing and asks for nothing.
     *
     * @param array{category_name?: string, category_description?: string, account_id?: int|string|null} $input
     *
     * @return int The category.
     *
     * @throws AccountingException|BranchException
     */
    public function save(array $input, ?int $categoryId = null, ?int $employeeId = null): int
    {
        $name = trim((string) ($input['category_name'] ?? ''));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::RevenueCategoryNameInvalid, 'A revenue category has a name of 1 to 255 characters.');
        }

        $stored = $categoryId === null ? null : $this->stored($categoryId);

        if ($this->db->table('revenue_categories')->where(['category_name' => $name, 'deleted' => 0])->where('revenue_category_id !=', $categoryId ?? 0)->countAllResults() > 0) {
            throw new AccountingException(AccountingError::RevenueCategoryNameTaken, 'Another revenue category has this name.');
        }

        $current   = $stored === null || $stored->account_id === null ? null : (int) $stored->account_id;
        $accountId = array_key_exists('account_id', $input) ? $this->accountId($input['account_id']) : $current;

        if ($accountId !== $current) {
            $this->guard->require(AccountingGuard::ROUTING_MANAGE, $employeeId);
            $this->branches->requireCompanyScope($employeeId);

            if ($accountId !== null) {
                $this->assertUsable($accountId);
            }
        }

        return $this->transactional(function () use ($name, $input, $stored, $categoryId, $accountId, $current, $employeeId): int {
            $row = ['category_name' => $name, 'category_description' => mb_substr(trim((string) ($input['category_description'] ?? '')), 0, self::TEXT_MAX), 'account_id' => $accountId];

            if ($stored === null) {
                $this->db->table('revenue_categories')->insert($row);

                $categoryId = (int) $this->db->insertID();

                $this->audit->write(self::ENTITY, $categoryId, 'created', $employeeId, JournalOrigin::User, ['account' => $accountId]);

                return $categoryId;
            }

            $this->db->table('revenue_categories')->where('revenue_category_id', $categoryId)->update($row);

            if ($accountId !== $current) {
                $this->audit->write(self::ENTITY, (int) $categoryId, 'account_changed', $employeeId, JournalOrigin::User, ['before' => $current, 'after' => $accountId]);
            }

            return (int) $categoryId;
        });
    }

    /**
     * @param list<int> $categoryIds
     */
    public function delete(array $categoryIds, ?int $employeeId = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        $this->transactional(function () use ($ids, $employeeId): void {
            $this->db->table('revenue_categories')->whereIn('revenue_category_id', $ids)->update(['deleted' => 1]);

            foreach ($ids as $categoryId) {
                $this->audit->write(self::ENTITY, $categoryId, 'deleted', $employeeId, JournalOrigin::User);
            }
        });
    }

    public function find(int $categoryId): ?object
    {
        return $this->db->table('revenue_categories c')
            ->select('c.*, a.code AS account_code, a.name AS account_name')
            ->join('accounting_accounts a', 'a.account_id = c.account_id', 'left')
            ->where(['c.revenue_category_id' => $categoryId, 'c.deleted' => 0])
            ->get()
            ->getRow();
    }

    /**
     * @return array{total: int, rows: list<object>}
     */
    public function search(string $term, string $sort, string $order, int $limit, int $offset): array
    {
        $builder = $this->db->table('revenue_categories c')
            ->join('accounting_accounts a', 'a.account_id = c.account_id', 'left')
            ->where('c.deleted', 0);

        if (trim($term) !== '') {
            $builder->groupStart()->like('c.category_name', trim($term))->orLike('c.category_description', trim($term))->groupEnd();
        }

        $total   = $builder->countAllResults(false);
        $columns = ['revenue_category_id' => 'c.revenue_category_id', 'category_name' => 'c.category_name', 'category_description' => 'c.category_description', 'account' => 'a.code'];
        $rows    = $builder
            ->select('c.*, a.code AS account_code, a.name AS account_name')
            ->orderBy($columns[$sort] ?? 'c.revenue_category_id', strtolower($order) === 'asc' ? 'ASC' : 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @return list<object> The categories that are in use, by name.
     */
    public function all(): array
    {
        return $this->db->table('revenue_categories')->where('deleted', 0)->orderBy('category_name', 'ASC')->get()->getResult();
    }

    /**
     * Whether the employee may choose the accounts of the categories.
     */
    public function mayAssign(?int $employeeId): bool
    {
        return $this->guard->has(AccountingGuard::ROUTING_MANAGE, $employeeId) && $this->branches->isUnrestricted($employeeId);
    }

    /**
     * The accounts a voucher or a category can be credited to, in code order: active accounts of
     * revenue that take entries and are not kept for another purpose.
     *
     * @return list<object>
     */
    public function accountOptions(): array
    {
        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('account_type', AccountType::Revenue->value)
            ->where('is_group', 0)
            ->where('active', 1)
            ->groupStart()->where('system_key IS NULL', null, false)->orWhere('system_key', RoutingKey::RevenuesDefault->defaultSystemKey())->groupEnd()
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @throws AccountingException
     */
    private function stored(int $categoryId): object
    {
        $row = $this->db->table('revenue_categories')->where(['revenue_category_id' => $categoryId, 'deleted' => 0])->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::RevenueCategoryNotFound, "Revenue category {$categoryId} does not exist.", ['category_id' => $categoryId]);
        }

        return $row;
    }

    /**
     * @throws AccountingException
     */
    private function assertUsable(int $accountId): void
    {
        foreach ($this->accountOptions() as $option) {
            if ((int) $option->account_id === $accountId) {
                return;
            }
        }

        throw new AccountingException(AccountingError::RevenueAccountInvalid, "Account {$accountId} cannot take the receipt vouchers of a category.", ['account_id' => $accountId]);
    }

    private function accountId(mixed $value): ?int
    {
        if (! is_scalar($value) || ! ctype_digit(trim((string) $value))) {
            return null;
        }

        $id = (int) trim((string) $value);

        return $id > 0 ? $id : null;
    }
}
