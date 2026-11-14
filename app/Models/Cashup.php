<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\CashupPosting;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;
use Config\OSPOS;
use stdClass;
use Throwable;

/**
 * Cashup class
 * Cashups are used to report actual cash on hand, expenses and transactions at the end of a period.
 */
class Cashup extends Model
{
    protected $table            = 'cash_up';
    protected $primaryKey       = 'cashup_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'open_date',
        'close_date',
        'open_cash_amount',
        'transfer_cash_amount',
        'note',
        'closed_amount_cash',
        'closed_amount_card',
        'closed_amount_check',
        'closed_amount_total',
        'description',
        'open_employee_id',
        'close_employee_id',
        'deleted',
        'closed_amount_due',
        'branch_id',
        'treasury_id',
        'expected_amount_cash',
        'cash_difference',
    ];
    private ?Throwable $accountingFailure = null;

    /**
     * Why the last change to a cash-up was refused by the books, or null when it was not.
     */
    public function accountingFailure(): ?Throwable
    {
        return $this->accountingFailure;
    }

    /**
     * Determines if a given Cashup_id is a Cashup
     */
    public function exists(int $cashup_id): bool
    {
        $builder = $this->db->table('cash_up');
        $builder->where('cashup_id', $cashup_id);

        return $builder->get()->getNumRows() === 1;    // TODO: ===
    }

    /**
     * Gets employee info
     */
    public function get_employee(int $cashup_id): object    // TODO: This function is never called and if it were called, would not yield proper results.  There is no employee_id field in the cash_up table.
    {
        $builder = $this->db->table('cash_up');
        $builder->where('cashup_id', $cashup_id);

        $employee = model(Employee::class);

        return $employee->get_info($builder->get()->getRow()->employee_id);
    }

    public function get_multiple_info(string $cashup_ids): ResultInterface
    {
        $builder = $this->db->table('cash_up');
        $builder->whereIn('cashup_id', $cashup_ids);
        $builder->orderBy('cashup_id', 'asc');

        return $builder->get();
    }

    /**
     * Gets rows
     */
    public function get_found_rows(string $search, array $filters): int
    {
        return $this->search($search, $filters, 0, 0, 'cashup_id', 'asc', true);
    }

    /**
     * Searches cashups
     */
    public function search(string $search, array $filters, ?int $rows = 0, ?int $limit_from = 0, ?string $sort = 'cashup_id', ?string $order = 'asc', ?bool $count_only = false)
    {
        // Set default values
        if ($rows === null) {
            $rows = 0;
        }
        if ($limit_from === null) {
            $limit_from = 0;
        }
        if ($sort === null) {
            $sort = 'cashup_id';
        }
        if ($order === null) {
            $order = 'asc';
        }
        if ($count_only === null) {
            $count_only = false;
        }

        $config       = config(OSPOS::class)->settings;
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();
        $builder      = $this->db->table('cash_up AS cash_up');

        // get_found_rows case
        if ($count_only) {
            $builder->select('COUNT(cash_up.cashup_id) as count');
        } else {
            $builder->select('
            cash_up.cashup_id,
            MAX(cash_up.open_date) AS open_date,
            MAX(cash_up.close_date) AS close_date,
            MAX(cash_up.open_amount_cash) AS open_amount_cash,
            MAX(cash_up.transfer_amount_cash) AS transfer_amount_cash,
            MAX(cash_up.closed_amount_cash) AS closed_amount_cash,
            MAX(cash_up.closed_amount_due) AS closed_amount_due,
            MAX(cash_up.closed_amount_card) AS closed_amount_card,
            MAX(cash_up.closed_amount_check) AS closed_amount_check,
            MAX(cash_up.closed_amount_total) AS closed_amount_total,
            MAX(cash_up.description) AS description,
            MAX(cash_up.note) AS note,
            MAX(cash_up.open_employee_id) AS open_employee_id,
            MAX(cash_up.close_employee_id) AS close_employee_id,
            MAX(open_employees.first_name) AS open_first_name,
            MAX(open_employees.last_name) AS open_last_name,
            MAX(close_employees.first_name) AS close_first_name,
            MAX(close_employees.last_name) AS close_last_name
        ');

            if ($accountingOn) {
                $builder->select('MAX(CASE WHEN cash_up.branch_id IS NULL AND cash_up.deleted = 0 THEN 0 ELSE 1 END) AS ledger_state', false);
            }
        }

        $builder->join('people AS open_employees', 'open_employees.person_id = cash_up.open_employee_id', 'LEFT');
        $builder->join('people AS close_employees', 'close_employees.person_id = cash_up.close_employee_id', 'LEFT');

        $builder->groupStart();
        $builder->like('cash_up.open_date', $search);
        $builder->orLike('open_employees.first_name', $search);
        $builder->orLike('open_employees.last_name', $search);
        $builder->orLike('close_employees.first_name', $search);
        $builder->orLike('close_employees.last_name', $search);
        $builder->orLike('cash_up.closed_amount_total', $search);
        $builder->orLike('CONCAT(open_employees.first_name, " ", open_employees.last_name)', $search);
        $builder->orLike('CONCAT(close_employees.first_name, " ", close_employees.last_name)', $search);
        $builder->groupEnd();

        $builder->where('cash_up.deleted', $filters['is_deleted']);

        if ($accountingOn && ! empty($filters['only_unposted'])) {
            $builder->where('cash_up.branch_id IS NULL AND cash_up.deleted = 0', null, false);
        }

        if (empty($config['date_or_time_format'])) {    // TODO: convert this to ternary notation.
            $builder->where('DATE_FORMAT(cash_up.open_date, "%Y-%m-%d") BETWEEN ' . $this->db->escape($filters['start_date']) . ' AND ' . $this->db->escape($filters['end_date']));
        } else {
            $builder->where('cash_up.open_date BETWEEN ' . $this->db->escape(rawurldecode($filters['start_date'])) . ' AND ' . $this->db->escape(rawurldecode($filters['end_date'])));
        }

        // get_found_rows case
        if ($count_only) {
            return $builder->get()->getRow()->count;
        }
        $builder->groupBy('cashup_id');

        $builder->orderBy($sort, $order);

        if ($rows > 0) {
            $builder->limit($rows, $limit_from);
        }

        return $builder->get();
    }

    /**
     * Gets information about a particular cashup
     */
    public function get_info(int $cashup_id): object
    {
        $accountingOn = (new AccountingSettings($this->db))->isEnabled();
        $builder      = $this->db->table('cash_up AS cash_up');
        $builder->select('
            cash_up.cashup_id AS cashup_id,
            cash_up.open_date AS open_date,
            cash_up.close_date AS close_date,
            cash_up.open_amount_cash AS open_amount_cash,
            cash_up.transfer_amount_cash AS transfer_amount_cash,
            cash_up.closed_amount_cash AS closed_amount_cash,
            cash_up.closed_amount_due AS closed_amount_due,
            cash_up.closed_amount_card AS closed_amount_card,
            cash_up.closed_amount_check AS closed_amount_check,
            cash_up.closed_amount_total AS closed_amount_total,
            cash_up.description AS description,
            cash_up.note AS note,
            cash_up.open_employee_id AS open_employee_id,
            cash_up.close_employee_id AS close_employee_id,
            cash_up.deleted AS deleted,
            cash_up.expected_amount_cash AS expected_amount_cash,
            cash_up.cash_difference AS cash_difference,
            open_employees.first_name AS open_first_name,
            open_employees.last_name AS open_last_name,
            close_employees.first_name AS close_first_name,
            close_employees.last_name AS close_last_name
        ');
        $builder->join('people AS open_employees', 'open_employees.person_id = cash_up.open_employee_id', 'LEFT');
        $builder->join('people AS close_employees', 'close_employees.person_id = cash_up.close_employee_id', 'LEFT');
        $builder->where('cashup_id', $cashup_id);

        if ($accountingOn) {
            $builder->select('CASE WHEN cash_up.branch_id IS NULL AND cash_up.deleted = 0 THEN 0 ELSE 1 END AS ledger_state', false);
        }

        $query = $builder->get();
        if ($query->getNumRows() === 1) {    // TODO: ===
            return $query->getRow();
        }

        return $this->getEmptyObject('cash_up');
    }

    /**
     * Initializes an empty object based on database definitions
     */
    private function getEmptyObject(string $table_name): object
    {
        // Return an empty base parent object, as $item_id is NOT an item
        $empty_obj = new stdClass();

        // Iterate through field definitions to determine how the fields should be initialized
        foreach ($this->db->getFieldData($table_name) as $field) {
            $field_name = $field->name;

            if (in_array($field->type, ['int', 'tinyint', 'decimal'], true)) {
                $empty_obj->{$field_name} = ($field->primary_key === 1) ? NEW_ENTRY : 0;
            } else {
                $empty_obj->{$field_name} = null;
            }
        }

        return $empty_obj;
    }

    /**
     * Inserts or updates a cashup. With accounting on, a cash-up the books follow is saved with the
     * journal of its cash difference in one transaction, and the books refusing it undoes both;
     * accountingFailure() then says why. The actor is the employee who makes the change, whose
     * branches and treasuries decide what is allowed, and is the employee who closes the cash-up
     * when none is given.
     *
     * @param mixed $cashupId
     */
    public function save_value(array &$cashUpData, $cashupId = NEW_ENTRY, ?int $actorId = null): bool
    {
        $this->accountingFailure = null;

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            unset($cashUpData['branch_id'], $cashUpData['treasury_id'], $cashUpData['expected_amount_cash'], $cashUpData['cash_difference']);

            return $this->saveRow($cashUpData, $cashupId);
        }

        $cashupId = (int) $cashupId;
        $actorId ??= isset($cashUpData['close_employee_id']) ? (int) $cashUpData['close_employee_id'] : null;
        $posting = new CashupPosting($this->db);
        $branch  = null;
        $stored  = null;

        $this->db->transStart();

        try {
            if ($cashupId !== NEW_ENTRY) {
                $stored = LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable('cash_up') . ' WHERE cashup_id = ? FOR UPDATE', [$cashupId]);
            }

            $plan       = $posting->plan($cashUpData, $stored, $actorId);
            $branch     = $plan['branch_id'];
            $cashUpData = $plan['data'];
            $success    = $this->saveRow($cashUpData, $cashupId);

            if ($success && $plan['post']) {
                $posting->sync($stored === null ? (int) $cashUpData['cashup_id'] : $cashupId, $actorId);
            }
        } catch (AccountingException|BranchException|PostingException $failure) {
            $this->db->transRollback();
            $this->accountingFailure = $failure;

            if ($failure instanceof PostingException) {
                $posting->recordFailure($stored === null ? null : $cashupId, $failure, $actorId, $branch);
            }

            return false;
        } catch (Throwable $unexpected) {
            $this->db->transRollback();

            throw $unexpected;
        }

        $this->db->transComplete();

        return $success && $this->db->transStatus();
    }

    private function saveRow(array &$cashUpData, $cashupId): bool
    {
        if (! $cashupId === NEW_ENTRY || ! $this->exists($cashupId)) {
            $builder = $this->db->table('cash_up');
            if ($builder->insert($cashUpData)) {
                $cashUpData['cashup_id'] = $this->db->insertID();

                return true;
            }

            return false;
        }

        $builder = $this->db->table('cash_up');
        $builder->where('cashup_id', $cashupId);

        return $builder->update($cashUpData);
    }

    /**
     * Deletes a list of cashups. With accounting on, the journal of each cash-up that is in the
     * books is reversed with it, all in one transaction: either every cash-up goes or none.
     */
    public function delete_list(array $cashupIds, ?int $actorId = null): bool
    {
        $this->accountingFailure = null;

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return $this->deleteRows($cashupIds);
        }

        $posting = new CashupPosting($this->db);
        $ids     = array_values(array_unique(array_map('intval', $cashupIds)));

        sort($ids);

        $current = null;

        $this->db->transStart();

        try {
            foreach ($ids as $cashupId) {
                $current = $cashupId;
                $row     = LockedRead::row($this->db, 'SELECT cashup_id FROM ' . $this->db->prefixTable('cash_up') . ' WHERE cashup_id = ? FOR UPDATE', [$cashupId]);

                if ($row === null) {
                    continue;
                }

                $posting->cancel($cashupId, $actorId);
                $this->db->table('cash_up')->where('cashup_id', $cashupId)->update(['deleted' => 1]);
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

    private function deleteRows(array $cashupIds): bool
    {
        // Run these queries as a transaction, we want to make sure we do all or nothing
        $this->db->transStart();
        $builder = $this->db->table('cash_up');
        $builder->whereIn('cashup_id', $cashupIds);
        $success = $builder->update(['deleted' => 1]);
        $this->db->transComplete();

        return $success;
    }
}
