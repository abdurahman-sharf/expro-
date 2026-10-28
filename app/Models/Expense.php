<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\ExpensePosting;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use stdClass;
use Throwable;

/**
 * Expense class
 */
class Expense extends Model
{
    protected $table = 'expenses';
    protected $primaryKey = 'expense_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'date',
        'amount',
        'payment_type',
        'expense_category_id',
        'description',
        'employee_id',
        'deleted',
        'supplier_tax_code',
        'tax_amount',
        'supplier_id',
        'branch_id',
        'treasury_id'
    ];
    private ?Throwable $accountingFailure = null;

    /**
     * Why the last change to an expense was refused by the books, or null when it was not.
     */
    public function accountingFailure(): ?Throwable
    {
        return $this->accountingFailure;
    }

    /**
     * Determines if a given Expense_id is an Expense
     */
    public function exists(int $expense_id): bool
    {
        $builder = $this->db->table('expenses');
        $builder->where('expense_id', $expense_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Gets category info
     */
    public function get_expense_category(int $expense_id): object    // TODO: This function is never called in the code
    {
        $builder = $this->db->table('expenses');
        $builder->where('expense_id', $expense_id);

        $expense_category = model(Expense_category::class);
        return $expense_category->get_info($builder->get()->getRow()->expense_category_id);    // TODO: refactor out the nested function call.
    }

    /**
     * Gets employee info
     */
    public function get_employee(int $expense_id): object    // TODO: This function is never called in the code
    {
        $builder = $this->db->table('expenses');
        $builder->where('expense_id', $expense_id);

        $employee = model(Employee::class);

        return $employee->get_info($builder->get()->getRow()->employee_id);    // TODO: refactor out the nested function call.
    }

    /**
     * @param array $expense_ids
     * @return ResultInterface
     */
    public function get_multiple_info(array $expense_ids): ResultInterface
    {
        $builder = $this->db->table('expenses');
        $builder->whereIn('expenses.expense_id', $expense_ids);
        $builder->orderBy('expense_id', 'asc');

        return $builder->get();
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search, array $filters): int
    {
        return $this->search($search, $filters, 0, 0, 'expense_id', 'asc', true);
    }

    /**
     * Searches expenses
     *
     * @param string $search
     * @param array $filters
     * @param int|null $rows
     * @param int|null $limit_from
     * @param string|null $sort
     * @param string|null $order
     * @param bool|null $count_only
     * @return ResultInterface|false|string
     */
    public function search(string $search, array $filters, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'expense_id', ?string $order = 'asc', ?bool $count_only = false): false|string|ResultInterface
    {
        // Set default values
        if ($rows == null) $rows = 0;
        if ($limit_from == null) $limit_from = 0;
        if ($sort == null) $sort = 'expense_id';
        if ($order == null) $order = 'asc';
        if ($count_only == null) $count_only = false;

        $config = config(OSPOS::class)->settings;
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();
        $builder = $this->db->table('expenses AS expenses');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(DISTINCT expenses.expense_id) as count');
        } else {
            $builder->select('
                expenses.expense_id,
                MAX(expenses.date) AS date,
                MAX(suppliers.company_name) AS supplier_name,
                MAX(expenses.supplier_tax_code) AS supplier_tax_code,
                MAX(expenses.amount) AS amount,
                MAX(expenses.tax_amount) AS tax_amount,
                MAX(expenses.payment_type) AS payment_type,
                MAX(expenses.description) AS description,
                MAX(employees.first_name) AS first_name,
                MAX(employees.last_name) AS last_name,
                MAX(expense_categories.category_name) AS category_name
            ');

            if ($accountingOn) {
                $builder->select('MAX(' . $this->ledgerState('expenses') . ') AS ledger_state', false);
            }
        }

        $builder->join('people AS employees', 'employees.person_id = expenses.employee_id', 'LEFT');
        $builder->join('expense_categories AS expense_categories', 'expense_categories.expense_category_id = expenses.expense_category_id', 'LEFT');
        $builder->join('suppliers AS suppliers', 'suppliers.person_id = expenses.supplier_id', 'LEFT');

        $builder->groupStart();
            $builder->like('employees.first_name', $search);
            $builder->orLike('expenses.date', $search);
            $builder->orLike('employees.last_name', $search);
            $builder->orLike('expenses.payment_type', $search);
            $builder->orLike('expenses.amount', $search);
            $builder->orLike('expense_categories.category_name', $search);
            $builder->orLike('CONCAT(employees.first_name, " ", employees.last_name)', $search);
        $builder->groupEnd();

        $builder->where('expenses.deleted', $filters['is_deleted']);

        if (empty($config['date_or_time_format'])) {
            $builder->where('DATE_FORMAT(expenses.date, "%Y-%m-%d") BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        } else {
            $builder->where('expenses.date BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        if ($filters['only_debit']) {
            $builder->like('expenses.payment_type', lang('Expenses.debit'));
        }

        if ($filters['only_credit']) {
            $builder->like('expenses.payment_type', lang('Expenses.credit'));
        }

        if ($filters['only_cash']) {
            $builder->groupStart();
            $builder->like('expenses.payment_type', lang('Expenses.cash'));
            $builder->orWhere('expenses.payment_type IS NULL');
            $builder->groupEnd();
        }

        if ($filters['only_due']) {
            $builder->like('expenses.payment_type', lang('Expenses.due'));
        }

        if ($filters['only_check']) {
            $builder->like('expenses.payment_type', lang('Expenses.check'));
        }

        if ($accountingOn && !empty($filters['only_unposted'])) {
            $builder->where($this->ledgerState('expenses') . ' = 0', null, false);
        }

        if ($count_only) {    // TODO: replace this with `if ($count_only)`
            return $builder->get()->getRow()->count;
        }

        $builder->groupBy('expense_id');

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Gets information about a particular expense
     */
    public function get_info(int $expense_id): object
    {
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();
        $builder = $this->db->table('expenses AS expenses');
        $builder->select('
            expenses.expense_id AS expense_id,
            expenses.date AS date,
            suppliers.company_name AS supplier_name,
            expenses.supplier_id AS supplier_id,
            expenses.supplier_tax_code AS supplier_tax_code,
            expenses.amount AS amount,
            expenses.tax_amount AS tax_amount,
            expenses.payment_type AS payment_type,
            expenses.description AS description,
            expenses.employee_id AS employee_id,
            expenses.deleted AS deleted,
            expenses.treasury_id AS treasury_id,
            expenses.treasury_branch_id AS treasury_branch_id,
            expenses.branch_id AS branch_id,
            employees.first_name AS first_name,
            employees.last_name AS last_name,
            expense_categories.expense_category_id AS expense_category_id,
            expense_categories.category_name AS category_name
        ');

        $builder->join('people AS employees', 'employees.person_id = expenses.employee_id', 'LEFT');
        $builder->join('expense_categories AS expense_categories', 'expense_categories.expense_category_id = expenses.expense_category_id', 'LEFT');
        $builder->join('suppliers AS suppliers', 'suppliers.person_id = expenses.supplier_id', 'LEFT');
        $builder->where('expense_id', $expense_id);

        if ($accountingOn) {
            $builder->select($this->ledgerState('expenses') . ' AS ledger_state', false);
        }

        $query = $builder->get();

        if ($query->getNumRows() == 1) {    // TODO: ===
            return $query->getRow();
        }

        $empty_obj = $this->getEmptyObject('expenses');
        $empty_obj->supplier_name = null;
        $empty_obj->first_name = null;
        $empty_obj->last_name = null;

        return $empty_obj;
    }

    /**
     * 1 when the books hold all the expense calls for: a journal that stands, or an amount of zero
     * or a deletion that call for none. 0 when a journal is still missing.
     */
    private function ledgerState(string $alias): string
    {
        return "(CASE WHEN {$alias}.amount = 0 OR {$alias}.deleted = 1 OR EXISTS (SELECT 1 FROM " . $this->db->prefixTable('accounting_journals') . ' j WHERE j.source_type = '
            . $this->db->escape(ExpensePostingBuilder::SOURCE) . " AND j.source_id = {$alias}.expense_id AND j.status = "
            . $this->db->escape(JournalStatus::Posted->value) . ') THEN 1 ELSE 0 END)';
    }

    /**
     * Initializes an empty object based on database definitions
     * @param string $table_name
     * @return object
     */
    private function getEmptyObject(string $table_name): object
    {
        // Return an empty base parent object, as $item_id is NOT an item
        $empty_obj = new stdClass();

        // Iterate through field definitions to determine how the fields should be initialized
        foreach ($this->db->getFieldData($table_name) as $field) {
            $field_name = $field->name;

            if (in_array($field->type, ['int', 'tinyint', 'decimal'])) {
                $empty_obj->$field_name = ($field->primary_key == 1) ? NEW_ENTRY : 0;
            } else {
                $empty_obj->$field_name = null;
            }
        }

        return $empty_obj;
    }

    /**
     * Inserts or updates an expense. With accounting on, an expense the books follow is saved with
     * its journal in one transaction, and the books refusing it undoes both; accountingFailure()
     * then says why. The actor is the employee who makes the change, whose branches and treasuries
     * decide what is allowed, and is the employee of the expense when none is given.
     */
    public function save_value(array &$expenseData, int $expenseId = NEW_ENTRY, ?int $actorId = null): bool
    {
        $this->accountingFailure = null;

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            unset($expenseData['treasury_id'], $expenseData['branch_id'], $expenseData['expense_branch_id'], $expenseData['treasury_branch_id']);

            try {
                return $this->saveRow($expenseData, $expenseId);
            } catch (AccountingException $failure) {
                $this->accountingFailure = $failure;

                return false;
            }
        }

        $actorId ??= isset($expenseData['employee_id']) ? (int) $expenseData['employee_id'] : null;
        $posting = new ExpensePosting($this->db);
        $branch  = null;
        $stored  = null;

        $this->db->transStart();

        try {
            if ($expenseId !== NEW_ENTRY) {
                $stored = LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable('expenses') . ' WHERE expense_id = ? FOR UPDATE', [$expenseId]);
            }

            $plan        = $posting->plan($expenseData, $stored, $actorId);
            $branch      = $plan['branch_id'];
            $expenseData = $plan['data'];
            $success     = $this->saveRow($expenseData, $expenseId);

            if ($success && $plan['post']) {
                $posting->sync($stored === null ? (int) $expenseData['expense_id'] : $expenseId, $actorId);
            }
        } catch (AccountingException|BranchException|PostingException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            if ($failure instanceof PostingException) {
                $posting->recordFailure($stored === null ? null : $expenseId, $failure, $actorId, $branch);
            }

            return false;
        } catch (Throwable $unexpected) {
            $this->db->transRollback();

            throw $unexpected;
        }

        $this->db->transComplete();

        return $success && $this->db->transStatus();
    }

    /**
     * A new expense that cannot be given its number is not left behind, and the refusal reaches the
     * caller so that a transaction it holds is rolled back too.
     *
     * @throws AccountingException
     */
    private function saveRow(array &$expenseData, int $expenseId): bool
    {
        $builder = $this->db->table('expenses');

        if ($expenseId == NEW_ENTRY || !$this->exists($expenseId)) {
            $this->db->transStart();

            $inserted = $builder->insert($expenseData);

            if ($inserted) {
                $expenseData['expense_id'] = $this->db->insertID();

                try {
                    (new DocumentNumbering($this->db))->assign(DocumentType::Expense, 'expenses', (int) $expenseData['expense_id']);
                } catch (AccountingException $failure) {
                    log_message('error', 'Numbering expense ' . $expenseData['expense_id'] . ' failed: ' . $failure->getMessage());
                    $this->db->transRollback();

                    throw $failure;
                }
            }

            $this->db->transComplete();

            return $inserted && $this->db->transStatus();
        }

        $builder->where('expense_id', $expenseId);

        return $builder->update($expenseData);
    }

    /**
     * Deletes a list of expenses. With accounting on, the journal of each expense that is in the
     * books is reversed with it, all in one transaction: either every expense goes or none.
     */
    public function delete_list(array $expenseIds, ?int $actorId = null): bool
    {
        $this->accountingFailure = null;

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return $this->deleteRows($expenseIds);
        }

        $posting = new ExpensePosting($this->db);
        $ids     = array_values(array_unique(array_map('intval', $expenseIds)));

        sort($ids);

        $current = null;

        $this->db->transStart();

        try {
            foreach ($ids as $expenseId) {
                $current = $expenseId;
                $row     = LockedRead::row($this->db, 'SELECT expense_id, branch_id FROM ' . $this->db->prefixTable('expenses') . ' WHERE expense_id = ? FOR UPDATE', [$expenseId]);

                if ($row === null) {
                    continue;
                }

                $posting->cancel($expenseId, $actorId);
                $this->db->table('expenses')->where('expense_id', $expenseId)->update(['deleted' => 1]);
            }
        } catch (AccountingException|BranchException|PostingException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            if ($failure instanceof PostingException) {
                $posting->recordFailure($current, $failure, $actorId);
            }

            return false;
        } catch (Throwable $unexpected) {
            $this->db->transRollback();

            throw $unexpected;
        }

        $this->db->transComplete();

        return $this->db->transStatus();
    }

    private function deleteRows(array $expenseIds): bool
    {
        $builder = $this->db->table('expenses');

        $this->db->transStart();
        $builder->whereIn('expense_id', $expenseIds);
        $success = $builder->update(['deleted' => 1]);
        $this->db->transComplete();

        $success &= $this->db->transStatus();

        return $success;
    }

    /**
     * Gets the payment summary for the expenses (expenses/manage) view
     */
    public function get_payments_summary(string $search, array $filters): array    // TODO: $search is passed but never used in the function
    {
        $config = config(OSPOS::class)->settings;

        // get payment summary
        $builder = $this->db->table('expenses');
        $builder->select('payment_type, COUNT(amount) AS count, SUM(amount) AS amount');
        $builder->where('deleted', $filters['is_deleted']);

        if (empty($config['date_or_time_format'])) {
            $builder->where('DATE_FORMAT(date, "%Y-%m-%d") BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        } else {
            $builder->where('date BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        if ($filters['only_cash']) {
            $builder->like('payment_type', lang('Expenses.cash'));
        }

        if ($filters['only_due']) {
            $builder->like('payment_type', lang('Expenses.due'));
        }

        if ($filters['only_check']) {
            $builder->like('payment_type', lang('Expenses.check'));
        }

        if ($filters['only_credit']) {
            $builder->like('payment_type', lang('Expenses.credit'));
        }

        if ($filters['only_debit']) {
            $builder->like('payment_type', lang('Expenses.debit'));
        }

        $builder->groupBy('payment_type');

        return $builder->get()->getResultArray();
    }

    /**
     * Gets the payment options to show in the expense forms
     */
    public function get_payment_options(): array
    {
        return get_payment_options();
    }

    /**
     * Gets the expense payment
     */
    public function get_expense_payment(int $expense_id): ResultInterface
    {
        $builder = $this->db->table('expenses');
        $builder->where('expense_id', $expense_id);

        return $builder->get();
    }
}
