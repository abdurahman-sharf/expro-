<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountType;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the forms and the filters of the register offer to choose from.
 */
final class AssetChoices
{
    private const SUPPLIER_LIMIT = 500;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * The accounts an asset may name: the accounts of fixed assets and of their accumulated depreciation
     * (the group 11 of the chart), and the accounts of expenses.
     *
     * @return array{cost: list<object>, accumulated: list<object>, expense: list<object>}
     */
    public function accounts(): array
    {
        $assets = $this->leaves(AccountType::Asset, '11%');

        return ['cost' => $assets, 'accumulated' => $assets, 'expense' => $this->leaves(AccountType::Expense, '5%')];
    }

    /**
     * @return list<object>
     */
    public function employees(): array
    {
        return $this->db->table('employees e')
            ->select("e.person_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)
            ->join('people p', 'p.person_id = e.person_id')
            ->where('e.deleted', 0)
            ->orderBy('name', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @return list<object>
     */
    public function suppliers(): array
    {
        return $this->db->table('suppliers s')
            ->select("s.person_id, COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS name", false)
            ->join('people p', 'p.person_id = s.person_id')
            ->where('s.deleted', 0)
            ->orderBy('name', 'ASC')
            ->limit(self::SUPPLIER_LIMIT)
            ->get()
            ->getResult();
    }

    /**
     * @return list<object>
     */
    public function storages(): array
    {
        return $this->db->table('stock_locations')->select('location_id, location_name')->where('deleted', 0)->orderBy('location_name', 'ASC')->get()->getResult();
    }

    /**
     * @param list<int>|null $branchIds The branches the reader works in, null for every branch.
     *
     * @return list<object>
     */
    public function treasuries(?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $builder = $this->db->table('treasuries')->select('treasury_id, branch_id, name')->where('company_id', CompanyContext::id())->where('active', 1);

        if ($branchIds !== null) {
            $builder->whereIn('branch_id', $branchIds);
        }

        return $builder->orderBy('name', 'ASC')->get()->getResult();
    }

    /**
     * @return list<object>
     */
    private function leaves(AccountType $type, string $codePattern): array
    {
        return $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('company_id', CompanyContext::id())
            ->where('is_group', 0)
            ->where('active', 1)
            ->where('entity_type', null)
            ->where('account_type', $type->value)
            ->like('code', rtrim($codePattern, '%'), 'after')
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();
    }
}
