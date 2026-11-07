<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The shipping companies a sale can name for its shipping charge. A company may have an account of what
 * is owed to it, a leaf liability account, and then the shipping charge of a sale that names it is
 * posted there instead of to the routed shipping revenue: the customer pays the charge and the shop owes
 * it on. A company with no account changes nothing in the books. The feature is off until it is turned
 * on, and only whoever manages the accounting settings, across the whole company, changes it. The
 * reference system's chart reserves a group of shipping companies; how it names a company on a document
 * was not observed, so this is the smallest form of it, and an assumption.
 */
final class ShippingCompanies
{
    use RunsInTransaction;

    public const ENABLED_KEY = 'accounting_shipping_companies';
    private const NAME_MAX   = 255;

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->config   = model(Appconfig::class);
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
    }

    public function enabled(): bool
    {
        return $this->config->get_value(self::ENABLED_KEY, '0') === '1';
    }

    /**
     * @throws AccountingException
     */
    public function setEnabled(bool $enabled, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        if ($enabled === $this->enabled()) {
            return;
        }

        $this->config->batch_save([self::ENABLED_KEY => $enabled ? '1' : '0']);
        $this->audit->write('settings', null, 'settings_updated', $employeeId, JournalOrigin::User, ['before' => ['shipping_companies' => ! $enabled], 'after' => ['shipping_companies' => $enabled]]);
    }

    /**
     * @param array{name?: string, account_id?: int|string|null} $input
     *
     * @throws AccountingException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->requireManage($employeeId);

        $name      = $this->normalizeName((string) ($input['name'] ?? ''), null);
        $accountId = $this->normalizeAccount($input['account_id'] ?? null, null);

        return $this->transactional(function () use ($name, $accountId, $employeeId): int {
            $this->db->table('shipping_companies')->insert(['name' => $name, 'account_id' => $accountId, 'active' => 1]);

            $companyId = (int) $this->db->insertID();

            $this->audit->write('shipping_company', $companyId, 'created', $employeeId, JournalOrigin::User, ['name' => $name, 'account_id' => $accountId]);

            return $companyId;
        });
    }

    /**
     * @param array{name?: string, account_id?: int|string|null} $input What is left out stays as it is.
     *
     * @throws AccountingException
     */
    public function update(int $companyId, array $input, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $company = $this->require($companyId);
        $changes = [];

        if (array_key_exists('name', $input)) {
            $name = $this->normalizeName((string) $input['name'], $companyId);

            if ($name !== $company->name) {
                $changes['name'] = $name;
            }
        }

        if (array_key_exists('account_id', $input)) {
            $accountId = $this->normalizeAccount($input['account_id'], $companyId);

            if ($accountId !== ($company->account_id === null ? null : (int) $company->account_id)) {
                $changes['account_id'] = $accountId;
            }
        }

        if ($changes === []) {
            return;
        }

        $this->transactional(function () use ($companyId, $changes, $company, $employeeId): void {
            $this->db->table('shipping_companies')->where('shipping_company_id', $companyId)->update($changes);
            $this->audit->write('shipping_company', $companyId, 'updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key((array) $company, $changes), 'after' => $changes]);
        });
    }

    /**
     * @throws AccountingException
     */
    public function setActive(int $companyId, bool $active, ?int $employeeId = null): void
    {
        $this->requireManage($employeeId);

        $company = $this->require($companyId);

        if ((int) $company->active === (int) $active) {
            throw new AccountingException($active ? AccountingError::AlreadyActive : AccountingError::AlreadyInactive, 'The shipping company is already ' . ($active ? 'active' : 'inactive') . '.');
        }

        $this->transactional(function () use ($companyId, $active, $employeeId): void {
            $this->db->table('shipping_companies')->where('shipping_company_id', $companyId)->update(['active' => (int) $active]);
            $this->audit->write('shipping_company', $companyId, $active ? 'activated' : 'deactivated', $employeeId, JournalOrigin::User);
        });
    }

    /**
     * @return list<object> Every company with the code and name of its account, by name.
     */
    public function all(): array
    {
        return $this->db->table('shipping_companies c')
            ->select('c.shipping_company_id, c.name, c.account_id, c.active, a.code AS account_code, a.name AS account_name')
            ->join('accounting_accounts a', 'a.account_id = c.account_id', 'left')
            ->orderBy('c.name', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @return list<object> The companies a sale may name, by name.
     */
    public function selectable(): array
    {
        return array_values(array_filter($this->all(), static fn (object $company): bool => (int) $company->active === 1));
    }

    /**
     * Checks that a sale may name the company: the feature is on and the company is there and active.
     *
     * @throws AccountingException
     */
    public function requireNameable(int $companyId): void
    {
        if (! $this->enabled()) {
            throw new AccountingException(AccountingError::ShippingCompaniesOff, 'Shipping companies are not turned on.');
        }

        $company = $this->find($companyId);

        if ($company === null || (int) $company->active === 0) {
            throw new AccountingException(AccountingError::ShippingCompanyInvalid, "Shipping company {$companyId} is missing or inactive.", ['shipping_company_id' => $companyId]);
        }
    }

    /**
     * @return array<int, string> The accounts a company may be given, by id: leaf liability accounts that are on.
     */
    public function accountChoices(): array
    {
        $found = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, code, name')->where('is_group', 0)->where('active', 1)->where('account_type', AccountType::Liability->value)->orderBy('code', 'ASC')->get()->getResult() as $row) {
            $found[(int) $row->account_id] = $row->code . ' - ' . $row->name;
        }

        return $found;
    }

    public function accountOf(int $companyId): ?int
    {
        $company = $this->find($companyId);

        return $company === null || $company->account_id === null ? null : (int) $company->account_id;
    }

    public function find(int $companyId): ?object
    {
        return $this->db->table('shipping_companies')->where('shipping_company_id', $companyId)->get()->getRow();
    }

    /**
     * @throws AccountingException
     */
    private function require(int $companyId): object
    {
        $company = $this->find($companyId);

        if ($company === null) {
            throw new AccountingException(AccountingError::ShippingCompanyNotFound, "Shipping company {$companyId} does not exist.", ['shipping_company_id' => $companyId]);
        }

        return $company;
    }

    /**
     * @throws AccountingException
     */
    private function requireManage(?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::SETTINGS_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);
    }

    /**
     * @throws AccountingException
     */
    private function normalizeName(string $name, ?int $exceptCompanyId): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new AccountingException(AccountingError::InvalidName, 'The name must be 1 to 255 characters.');
        }

        $taken = $this->db->table('shipping_companies')->where('name', $name);

        if ($exceptCompanyId !== null) {
            $taken->where('shipping_company_id !=', $exceptCompanyId);
        }

        if ($taken->countAllResults() > 0) {
            throw new AccountingException(AccountingError::NameTaken, 'A shipping company has that name already.', ['name' => $name]);
        }

        return $name;
    }

    /**
     * The account of what is owed to the company, or none: a leaf liability account that is on and that
     * no other company uses.
     *
     * @throws AccountingException
     */
    private function normalizeAccount(mixed $accountId, ?int $exceptCompanyId): ?int
    {
        if ($accountId === null || $accountId === '' || (int) $accountId === 0) {
            return null;
        }

        $accountId = (int) $accountId;
        $account   = $this->db->table('accounting_accounts')->select('account_id, is_group, active, account_type')->where('account_id', $accountId)->get()->getRow();

        if ($account === null || (int) $account->is_group === 1 || (int) $account->active === 0 || $account->account_type !== AccountType::Liability->value) {
            throw new AccountingException(AccountingError::ShippingAccountInvalid, "Account {$accountId} is not a leaf liability account that is on.", ['account_id' => $accountId]);
        }

        $used = $this->db->table('shipping_companies')->where('account_id', $accountId);

        if ($exceptCompanyId !== null) {
            $used->where('shipping_company_id !=', $exceptCompanyId);
        }

        if ($used->countAllResults() > 0) {
            throw new AccountingException(AccountingError::ShippingAccountInvalid, "Account {$accountId} belongs to another shipping company.", ['account_id' => $accountId]);
        }

        return $accountId;
    }
}
