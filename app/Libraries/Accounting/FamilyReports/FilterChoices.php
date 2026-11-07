<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SupplierPaymentList;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The records a filter of a report is chosen among, as ids and the words that name them.
 */
final class FilterChoices
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array<int|string, string>
     */
    public function of(string $source, ReportScope $scope): array
    {
        return match ($source) {
            'suppliers'          => $this->suppliers(),
            'employees'          => $this->employees(),
            'locations'          => (new StockShelves($this->db))->locations($scope),
            'treasuries'         => $this->treasuries($scope),
            'expense_categories' => $this->categories('expense_categories', 'expense_category_id'),
            'revenue_categories' => $this->categories('revenue_categories', 'revenue_category_id'),
            'accounts'           => $this->accounts(),
            'sources'            => $this->sources(),
            'item_categories'    => $this->itemCategories(),
            'payment_types'      => $this->paymentTypes(),
            default              => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function suppliers(): array
    {
        $found = [];

        foreach ($this->db->table('suppliers s')->select("s.person_id, COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS name", false)->join('people p', 'p.person_id = s.person_id')->where('s.deleted', 0)->orderBy('name', 'ASC')->get()->getResult() as $row) {
            $found[(int) $row->person_id] = (string) $row->name;
        }

        return $found;
    }

    /**
     * @return array<int, string>
     */
    private function employees(): array
    {
        $found = [];

        foreach ($this->db->table('employees e')->select("e.person_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)->join('people p', 'p.person_id = e.person_id')->where('e.deleted', 0)->orderBy('name', 'ASC')->get()->getResult() as $row) {
            $found[(int) $row->person_id] = (string) $row->name;
        }

        return $found;
    }

    /**
     * @return array<int, string>
     */
    private function treasuries(ReportScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $found = [];

        foreach ($this->db->table('treasuries')->select('treasury_id, name')->where('company_id', CompanyContext::id())->whereIn('branch_id', $scope->ids)->orderBy('name', 'ASC')->get()->getResult() as $row) {
            $found[(int) $row->treasury_id] = (string) $row->name;
        }

        return $found;
    }

    /**
     * @return array<int, string>
     */
    private function categories(string $table, string $key): array
    {
        $found = [];

        foreach ($this->db->table($table)->select("{$key} AS id, category_name")->where('deleted', 0)->orderBy('category_name', 'ASC')->get()->getResult() as $row) {
            $found[(int) $row->id] = (string) $row->category_name;
        }

        return $found;
    }

    /**
     * @return array<int, string>
     */
    private function accounts(): array
    {
        $found = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, code, name')->where('company_id', CompanyContext::id())->orderBy('code', 'ASC')->get()->getResult() as $row) {
            $found[(int) $row->account_id] = $row->code . ' ' . $row->name;
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function sources(): array
    {
        $found = [];

        foreach (JournalSources::all() as $source) {
            $found[$source] = JournalSources::label($source);
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function itemCategories(): array
    {
        $found = [];

        foreach ($this->db->table('items')->select('category')->distinct()->where('deleted', 0)->where("category <> ''", null, false)->orderBy('category', 'ASC')->get()->getResult() as $row) {
            $found[(string) $row->category] = (string) $row->category;
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function paymentTypes(): array
    {
        $found = [];

        foreach ((new SupplierPaymentList($this->db))->paymentTypes() as $type) {
            $found[$type] = $type;
        }

        return $found;
    }
}
