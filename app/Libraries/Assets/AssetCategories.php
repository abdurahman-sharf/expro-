<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The categories of assets: a name, the accounts and the method and life an asset of the category
 * starts from. A category serves every branch, so it is changed only by someone who works in all of
 * them. A category that assets use is switched off and not deleted.
 */
final class AssetCategories
{
    use RunsInTransaction;

    public const ENTITY    = 'asset_category';
    private const NAME_MAX = 100;
    private const LIFE_MAX = 1200;

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
     * Each category with the assets in the register that use it (asset_count) and every asset that names
     * it, deleted ones too, which is what keeps a category from being deleted (referenced_count).
     *
     * @return list<object>
     */
    public function all(bool $activeOnly = false): array
    {
        $builder = $this->db->table('asset_categories c')
            ->select('c.*, ca.code AS cost_code, ca.name AS cost_name, aa.code AS accumulated_code, aa.name AS accumulated_name, ea.code AS expense_code, ea.name AS expense_name, (SELECT COUNT(*) FROM ' . $this->db->prefixTable('assets') . ' a WHERE a.category_id = c.category_id AND a.deleted = 0) AS asset_count, (SELECT COUNT(*) FROM ' . $this->db->prefixTable('assets') . ' r WHERE r.category_id = c.category_id) AS referenced_count', false)
            ->join('accounting_accounts ca', 'ca.account_id = c.cost_account_id', 'left')
            ->join('accounting_accounts aa', 'aa.account_id = c.accumulated_account_id', 'left')
            ->join('accounting_accounts ea', 'ea.account_id = c.expense_account_id', 'left')
            ->where('c.company_id', CompanyContext::id());

        if ($activeOnly) {
            $builder->where('c.active', 1);
        }

        return $builder->orderBy('c.name', 'ASC')->get()->getResult();
    }

    public function find(int $categoryId): ?object
    {
        return $this->db->table('asset_categories')->where('company_id', CompanyContext::id())->where('category_id', $categoryId)->get()->getRow();
    }

    /**
     * @param array{name?: string, cost_account_id?: int|string, accumulated_account_id?: int|string, expense_account_id?: int|string, method?: string, life_months?: int|string} $input
     *
     * @throws AccountingException|BranchException
     */
    public function save(array $input, ?int $employeeId = null, ?int $categoryId = null): int
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $row = $this->normalize($input, $categoryId);

        return $this->transactional(function () use ($row, $employeeId, $categoryId): int {
            if ($categoryId === null) {
                $this->db->table('asset_categories')->insert($row + ['company_id' => CompanyContext::id(), 'created_by' => $employeeId]);

                $id     = (int) $this->db->insertID();
                $action = 'asset_category_created';
            } else {
                $this->lock($categoryId);
                $this->db->table('asset_categories')->where('category_id', $categoryId)->update($row);

                $id     = $categoryId;
                $action = 'asset_category_updated';
            }

            $this->audit->write(self::ENTITY, $id, $action, $employeeId, $this->origin($employeeId), ['name' => $row['name']]);

            return $id;
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function setActive(int $categoryId, bool $active, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $this->transactional(function () use ($categoryId, $active, $employeeId): void {
            $this->lock($categoryId);
            $this->db->table('asset_categories')->where('category_id', $categoryId)->update(['active' => $active ? 1 : 0]);
            $this->audit->write(self::ENTITY, $categoryId, $active ? 'asset_category_activated' : 'asset_category_deactivated', $employeeId, $this->origin($employeeId));
        });
    }

    /**
     * @throws AccountingException|BranchException
     */
    public function delete(int $categoryId, ?int $employeeId = null): void
    {
        $this->guard->require(AccountingGuard::ASSETS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        $this->transactional(function () use ($categoryId, $employeeId): void {
            $row = $this->lock($categoryId);

            if ($this->db->table('assets')->where('category_id', $categoryId)->countAllResults() > 0) {
                throw new AccountingException(AccountingError::AssetCategoryInUse, 'Assets use this category, so it is switched off instead.', ['name' => (string) $row->name]);
            }

            $this->db->table('asset_categories')->where('category_id', $categoryId)->delete();
            $this->audit->write(self::ENTITY, $categoryId, 'asset_category_deleted', $employeeId, $this->origin($employeeId), ['name' => $row->name]);
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     *
     * @throws AccountingException
     */
    private function normalize(array $input, ?int $categoryId): array
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::AssetCategoryInvalid, 'A category needs a name of up to 100 characters.', ['name' => $name]);
        }

        $taken = $this->db->table('asset_categories')->where('company_id', CompanyContext::id())->where('name', $name);

        if ($categoryId !== null) {
            $taken->where('category_id !=', $categoryId);
        }

        if ($taken->countAllResults() > 0) {
            throw new AccountingException(AccountingError::AssetCategoryInvalid, 'Another category has this name.', ['name' => $name]);
        }

        $method = trim((string) ($input['method'] ?? ''));

        if ($method !== '' && AssetMethod::tryFrom($method) === null) {
            throw new AccountingException(AccountingError::AssetFieldInvalid, 'The method is not one of the four.', ['name' => 'method']);
        }

        $life = trim((string) ($input['life_months'] ?? ''));

        if ($life !== '' && (preg_match('/^\d{1,4}$/', $life) !== 1 || (int) $life < 1 || (int) $life > self::LIFE_MAX)) {
            throw new AccountingException(AccountingError::AssetFieldInvalid, 'The useful life is a number of months from 1 to 1200.', ['name' => 'life_months']);
        }

        return [
            'name'                   => $name,
            'cost_account_id'        => $this->account($input['cost_account_id'] ?? null, AssetAccountRules::COST),
            'accumulated_account_id' => $this->account($input['accumulated_account_id'] ?? null, AssetAccountRules::ACCUMULATED),
            'expense_account_id'     => $this->account($input['expense_account_id'] ?? null, AssetAccountRules::EXPENSE),
            'method'                 => $method === '' ? null : $method,
            'life_months'            => $life === '' ? null : (int) $life,
        ];
    }

    private function account(mixed $value, string $role): ?int
    {
        $id = (int) $value;

        if ($id === 0) {
            return null;
        }

        AssetAccountRules::require($this->db, $id, $role);

        return $id;
    }

    /**
     * @throws AccountingException
     */
    private function lock(int $categoryId): object
    {
        $table = $this->db->prefixTable('asset_categories');
        $row   = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND category_id = ? FOR UPDATE", [CompanyContext::id(), $categoryId]);

        if ($row === null) {
            throw new AccountingException(AccountingError::AssetCategoryNotFound, "Asset category {$categoryId} does not exist.", ['name' => (string) $categoryId]);
        }

        return $row;
    }

    private function origin(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
