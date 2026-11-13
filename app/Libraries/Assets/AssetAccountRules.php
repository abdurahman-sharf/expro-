<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountType;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;

/**
 * Which accounts an asset or a category may name: an account of the company that takes entries (not a
 * group, not switched off) and belongs to no customer, supplier, treasury or stock location, of the type
 * its part of the books has.
 */
final class AssetAccountRules
{
    public const COST        = 'cost';
    public const ACCUMULATED = 'accumulated';
    public const EXPENSE     = 'expense';

    /**
     * @throws AccountingException
     */
    public static function require(BaseConnection $db, int $accountId, string $role): object
    {
        $account = $db->table('accounting_accounts')->where('company_id', CompanyContext::id())->where('account_id', $accountId)->get()->getRow();
        $type    = $role === self::EXPENSE ? AccountType::Expense : AccountType::Asset;

        if ($account === null || (int) $account->is_group === 1 || (int) $account->active === 0 || $account->entity_type !== null || $account->account_type !== $type->value) {
            throw new AccountingException(AccountingError::AssetAccountInvalid, "Account {$accountId} cannot be the {$role} account of an asset.", ['name' => $role, 'code' => (string) ($account->code ?? $accountId)]);
        }

        return $account;
    }
}
