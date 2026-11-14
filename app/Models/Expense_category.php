<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\ExpenseCategoryAccounts;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use stdClass;
use Throwable;

/**
 * Expense_category class
 */
class Expense_category extends Model
{
    protected $table = 'expense_categories';
    protected $primaryKey = 'expense_category_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'category_name',
        'category_description',
        'deleted',
        'account_id'
    ];
    private ?Throwable $accountingFailure = null;

    /**
     * Why the last change to the account of a category was refused, or null when it was not.
     */
    public function accountingFailure(): ?Throwable
    {
        return $this->accountingFailure;
    }

    /**
     * Determines if a given Expense_id is an Expense category
     */
    public function exists(int $expense_category_id): bool
    {
        $builder = $this->db->table('expense_categories');
        $builder->where('expense_category_id', $expense_category_id);

        return ($builder->get()->getNumRows() == 1);    // TODO: ===
    }

    /**
     * Gets total of rows
     */
    public function get_total_rows(): int
    {
        $builder = $this->db->table('expense_categories');
        $builder->where('deleted', 0);

        return $builder->countAllResults();
    }

    /**
     * Gets information about a particular category
     */
    public function get_info(int $expense_category_id): object
    {
        $builder = $this->db->table('expense_categories');
        $builder->where('expense_category_id', $expense_category_id);
        $builder->where('deleted', 0);
        $query = $builder->get();

        if ($query->getNumRows() == 1) {    // TODO: ===
            return $query->getRow();
        } else {
            // Get empty base parent object, as $item_kit_id is NOT an item kit
            $expense_obj = new stdClass();

            // Get all the fields from items table
            foreach ($this->db->getFieldNames('expense_categories') as $field) {
                $expense_obj->$field = '';
            }

            return $expense_obj;
        }
    }

    /**
     * Returns all the expense_categories
     */
    public function get_all(int $rows = 0, int $limit_from = 0, bool $no_deleted = false): ResultInterface
    {
        $builder = $this->db->table('expense_categories');

        if ($no_deleted) {
            $builder->where('deleted', 0);
        }

        $builder->orderBy('category_name', 'asc');

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Gets information about multiple expense_category_id
     */
    public function get_multiple_info(array $expense_category_ids): ResultInterface
    {
        $builder = $this->db->table('expense_categories');
        $builder->whereIn('expense_category_id', $expense_category_ids);
        $builder->orderBy('category_name', 'asc');

        return $builder->get();
    }

    /**
     * Inserts or updates an expense_category. The account of the category is changed only with
     * accounting on, only by an employee who may change the routing, and together with the rest of
     * the category: the account being refused undoes the save, and accountingFailure() says why.
     */
    public function save_value(array &$expenseCategoryData, int $expenseCategoryId = NEW_ENTRY, ?int $employeeId = null): bool
    {
        $this->accountingFailure = null;

        $asked = array_key_exists('account_id', $expenseCategoryData);
        $value = $expenseCategoryData['account_id'] ?? null;

        unset($expenseCategoryData['account_id']);

        if (! $asked || ! (new AccountingSettings($this->db))->isEnabled()) {
            return $this->saveRow($expenseCategoryData, $expenseCategoryId);
        }

        $this->db->transStart();

        try {
            $success = $this->saveRow($expenseCategoryData, $expenseCategoryId);

            if ($success) {
                (new ExpenseCategoryAccounts($this->db))->assign((int) $expenseCategoryData['expense_category_id'], $value, $employeeId);
            }
        } catch (AccountingException|BranchException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            return false;
        } catch (Throwable $unexpected) {
            $this->db->transRollback();

            throw $unexpected;
        }

        $this->db->transComplete();

        return $success && $this->db->transStatus();
    }

    private function saveRow(array &$expenseCategoryData, int $expenseCategoryId): bool
    {
        $builder = $this->db->table('expense_categories');

        if ($expenseCategoryId == NEW_ENTRY || !$this->exists($expenseCategoryId)) {
            if ($builder->insert($expenseCategoryData)) {
                $expenseCategoryData['expense_category_id'] = $this->db->insertID();

                return true;
            }

            return false;
        }

        $builder->where('expense_category_id', $expenseCategoryId);
        $success = $builder->update($expenseCategoryData);

        $expenseCategoryData['expense_category_id'] = $expenseCategoryId;

        return $success;
    }

    /**
     * Deletes a list of expense_category
     */
    public function delete_list(array $expense_category_ids): bool
    {
        $builder = $this->db->table('expense_categories');
        $builder->whereIn('expense_category_id', $expense_category_ids);

        return $builder->update(['deleted' => 1]);
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search): int
    {
        return $this->search($search, 0, 0, 'category_name', 'asc', true);
    }

    /**
     * Perform a search on expense_category
     */
    public function search(string $search, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'category_name', ?string $order = 'asc', ?bool $count_only = false)
    {
        // Set default values
        if ($rows == null) $rows = 0;
        if ($limit_from == null) $limit_from = 0;
        if ($sort == null) $sort = 'category_name';
        if ($order == null) $order = 'asc';
        if ($count_only == null) $count_only = false;

        $builder = $this->db->table('expense_categories AS expense_categories');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(expense_categories.expense_category_id) as count');
        }

        $builder->groupStart();
        $builder->like('category_name', $search);
        $builder->orLike('category_description', $search);
        $builder->groupEnd();
        $builder->where('deleted', 0);

        // get_found_rows case
        if ($count_only) {
            return $builder->get()->getRow()->count;
        }

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }
}
