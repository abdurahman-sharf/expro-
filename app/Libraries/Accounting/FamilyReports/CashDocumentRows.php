<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The expenses, or the receipt vouchers, of a period, each with what it came to without its tax, its
 * tax and what it came to with it, the treasury it went through, its category and the account of the
 * category, and what the journal that stands for it holds. A document saved before documents carried a
 * branch belongs to none and is read only when every branch is.
 */
final class CashDocumentRows
{
    public const EXPENSE = 'expense';
    public const INCOME  = 'income';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array{treasury_id?: int, category_id?: int, employee_id?: int, vendor?: string} $filters
     *
     * @return list<object{id: int, number: string, date: string, treasury: string, treasury_id: ?int, category: string, category_id: ?int, vendor: string, account: string, employee_id: ?int, employee: string, note: string, amount: string, tax: string, total: string, journal_total: ?string, branch_code: string}>
     */
    public function read(string $kind, ReportScope $scope, ReportPeriod $period, ?int $viewerId = null, array $filters = []): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $expense  = $kind === self::EXPENSE;
        $table    = $expense ? 'expenses' : 'revenues';
        $key      = $expense ? 'expense_id' : 'revenue_id';
        $day      = $expense ? 'DATE(d.date)' : 'd.date';
        $category = $expense ? 'expense_categories' : 'revenue_categories';
        $categoryKey = $expense ? 'expense_category_id' : 'revenue_category_id';
        $journals = $this->db->prefixTable('accounting_journals');
        $where    = [ScopeSql::branches('d.branch_id', $scope, true), 'd.deleted = 0', "{$day} >= ?", "{$day} <= ?"];
        $bindings = [CompanyContext::id(), $expense ? ExpensePostingBuilder::SOURCE : RevenuePostingBuilder::SOURCE, JournalEntryType::General->value, JournalStatus::Posted->value, $period->from ?? '1000-01-01', $period->to];

        [$mine, $mineBindings] = (new RecordScope($this->db))->condition('d.employee_id', $viewerId ?? RecordScope::sessionEmployee());

        if ($mine !== '') {
            $where[]  = $mine;
            $bindings = [...$bindings, ...$mineBindings];
        }

        foreach (['treasury_id' => 'd.treasury_id', 'category_id' => "d.{$categoryKey}", 'employee_id' => 'd.employee_id'] as $name => $column) {
            if (($filters[$name] ?? 0) > 0) {
                $where[]    = "{$column} = ?";
                $bindings[] = (int) $filters[$name];
            }
        }

        $accountId = $expense ? 'c.account_id' : 'COALESCE(d.account_id, c.account_id)';
        $vendor    = $expense ? "COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(COALESCE(sp.first_name, ''), ' ', COALESCE(sp.last_name, ''))))" : "''";
        $vendorSql = $expense ? ' LEFT JOIN ' . $this->db->prefixTable('suppliers') . ' s ON s.person_id = d.supplier_id LEFT JOIN ' . $this->db->prefixTable('people') . ' sp ON sp.person_id = d.supplier_id' : '';

        if ($expense && ($filters['vendor'] ?? '') !== '') {
            $where[]    = "{$vendor} LIKE ?";
            $bindings[] = '%' . $this->db->escapeLikeString((string) $filters['vendor']) . '%';
        }

        $rows = $this->db->query(
            "SELECT d.{$key} AS id, {$day} AS doc_date, d.amount, d.tax_amount, d.description, d.employee_id, d.treasury_id, d.{$categoryKey} AS category_id, c.category_name,
                    {$vendor} AS vendor, ac.code AS account_code, ac.name AS account_name, t.name AS treasury_name, b.code AS branch_code,
                    TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name,
                    CASE WHEN j.journal_id IS NULL THEN NULL WHEN d.amount < 0 THEN 0 - j.total_debit ELSE j.total_debit END AS journal_total
             FROM " . $this->db->prefixTable($table) . " d
             LEFT JOIN {$journals} j ON j.company_id = ? AND j.source_type = ? AND j.source_id = d.{$key} AND j.entry_type = ? AND j.status = ?
             LEFT JOIN " . $this->db->prefixTable($category) . " c ON c.{$categoryKey} = d.{$categoryKey}
             LEFT JOIN " . $this->db->prefixTable('accounting_accounts') . " ac ON ac.account_id = {$accountId}
             LEFT JOIN " . $this->db->prefixTable('treasuries') . ' t ON t.treasury_id = d.treasury_id
             LEFT JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = d.branch_id
             LEFT JOIN ' . $this->db->prefixTable('people') . " ep ON ep.person_id = d.employee_id{$vendorSql}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY doc_date ASC, d.{$key} ASC",
            $bindings,
        )->getResult();

        $numbers = (new DocumentNumbering($this->db))->numbers($expense ? DocumentType::Expense : DocumentType::Revenue, $table, array_map(static fn (object $row): int => (int) $row->id, $rows));
        $type    = $expense ? DocumentType::Expense : DocumentType::Revenue;
        $found   = [];

        foreach ($rows as $row) {
            $total   = bcadd((string) $row->amount, '0', SaleAmounts::SCALE);
            $tax     = bcadd((string) $row->tax_amount, '0', SaleAmounts::SCALE);
            $found[] = (object) [
                'id'            => (int) $row->id,
                'number'        => $numbers[(int) $row->id] ?? $type->fallback((int) $row->id),
                'date'          => (string) $row->doc_date,
                'treasury'      => (string) ($row->treasury_name ?? ''),
                'treasury_id'   => $row->treasury_id === null ? null : (int) $row->treasury_id,
                'category'      => (string) ($row->category_name ?? ''),
                'category_id'   => $row->category_id === null ? null : (int) $row->category_id,
                'vendor'        => trim((string) $row->vendor),
                'account'       => $row->account_code === null ? '' : $row->account_code . ' ' . $row->account_name,
                'employee_id'   => $row->employee_id === null ? null : (int) $row->employee_id,
                'employee'      => trim((string) $row->employee_name),
                'note'          => (string) ($row->description ?? ''),
                'amount'        => bcsub($total, $tax, SaleAmounts::SCALE),
                'tax'           => $tax,
                'total'         => $total,
                'journal_total' => $row->journal_total === null ? null : bcadd((string) $row->journal_total, '0', SaleAmounts::SCALE),
                'branch_code'   => (string) ($row->branch_code ?? ''),
            ];
        }

        return $found;
    }
}
