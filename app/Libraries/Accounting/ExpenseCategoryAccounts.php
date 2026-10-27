<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The account an expense category is charged to. A category without one is charged to the account
 * of expenses the routing names. Which account a category goes to is a routing decision, so it asks
 * for the permission to change the routing and for every branch, and a change is audited.
 */
final class ExpenseCategoryAccounts
{
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
     * Gives the category an account, or none with an empty value. A value the category already has
     * changes nothing and asks for nothing.
     *
     * @throws AccountingException|BranchException
     */
    public function assign(int $categoryId, mixed $account, ?int $employeeId): void
    {
        $accountId = $this->accountId($account);
        $category  = $this->db->table('expense_categories')->select('account_id')->where('expense_category_id', $categoryId)->get()->getRow();
        $current   = $category === null || $category->account_id === null ? null : (int) $category->account_id;

        if ($category === null || $accountId === $current) {
            return;
        }

        $this->guard->require(AccountingGuard::ROUTING_MANAGE, $employeeId);
        $this->branches->requireCompanyScope($employeeId);

        if ($accountId !== null) {
            $this->assertUsable($accountId);
        }

        $this->db->table('expense_categories')->where('expense_category_id', $categoryId)->update(['account_id' => $accountId]);
        $this->audit->write('expense_category', $categoryId, 'account_changed', $employeeId, JournalOrigin::User, ['before' => $current, 'after' => $accountId]);
    }

    /**
     * Whether the employee may choose the accounts of the categories.
     */
    public function mayAssign(?int $employeeId): bool
    {
        return $this->guard->has(AccountingGuard::ROUTING_MANAGE, $employeeId) && $this->branches->isUnrestricted($employeeId);
    }

    /**
     * The accounts a category can be charged to, in code order: active accounts of expenses that
     * take entries and are not kept for another purpose.
     *
     * @return list<object>
     */
    public function options(): array
    {
        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('account_type', AccountType::Expense->value)
            ->where('is_group', 0)
            ->where('active', 1)
            ->groupStart()->where('system_key IS NULL', null, false)->orWhere('system_key', RoutingKey::ExpensesDefault->defaultSystemKey())->groupEnd()
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @throws AccountingException
     */
    private function assertUsable(int $accountId): void
    {
        foreach ($this->options() as $option) {
            if ((int) $option->account_id === $accountId) {
                return;
            }
        }

        throw new AccountingException(AccountingError::ExpenseCategoryAccountInvalid, "Account {$accountId} cannot take the expenses of a category.", ['account_id' => $accountId]);
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
