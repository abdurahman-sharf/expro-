<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Models\Expense;
use App\Models\Expense_category;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use Config\Services;

class Expenses extends Secure_Controller
{
    private Expense $expense;
    private Expense_category $expense_category;

    public function __construct()
    {
        parent::__construct('expenses');

        $this->expense = model(Expense::class);
        $this->expense_category = model(Expense_category::class);
    }

    /**
     * @return void
     */
    public function getIndex(): string
    {
        $data['table_headers'] = get_expenses_manage_table_headers();

        // filters that will be loaded in the multiselect dropdown
        $data['filters'] = [
            'only_cash'   => lang('Expenses.cash_filter'),
            'only_due'    => lang('Expenses.due_filter'),
            'only_check'  => lang('Expenses.check_filter'),
            'only_credit' => lang('Expenses.credit_filter'),
            'only_debit'  => lang('Expenses.debit_filter'),
            'is_deleted'  => lang('Expenses.is_deleted')
        ];

        if ((new AccountingSettings())->isEnabled()) {
            $data['filters']['only_unposted'] = lang('Expenses.unposted_filter');
        }

        // Restore filters from URL
        $data = array_merge($data, restoreTableFilters($this->request));

        return view('expenses/manage', $data);
    }

    /**
     * @return void
     */
    public function getSearch(): ResponseInterface
    {
        $search   = $this->request->getGet('search');
        $limit    = $this->request->getGet('limit', FILTER_SANITIZE_NUMBER_INT);
        $offset   = $this->request->getGet('offset', FILTER_SANITIZE_NUMBER_INT);
        $sort     = $this->sanitizeSortColumn(expense_headers(), $this->request->getGet('sort', FILTER_SANITIZE_FULL_SPECIAL_CHARS), 'expense_id');
        $order    = $this->request->getGet('order', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $filters  = [
            'start_date'  => $this->request->getGet('start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'end_date'    => $this->request->getGet('end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'only_cash'   => false,
            'only_due'    => false,
            'only_check'  => false,
            'only_credit' => false,
            'only_debit'  => false,
            'only_unposted' => false,
            'is_deleted'  => false
        ];

        // Check if any filter is set in the multiselect dropdown
        $request_filters = array_fill_keys($this->request->getGet('filters', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? [], true);
        $filters = array_merge($filters, $request_filters);
        $expenses = $this->expense->search($search, $filters, $limit, $offset, $sort, $order);
        $total_rows = $this->expense->get_found_rows($search, $filters);
        $payments = $this->expense->get_payments_summary($search, $filters);
        $payment_summary = get_expenses_manage_payments_summary($payments, $expenses);
        $data_rows = [];

        $numbers = (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', array_map(static fn (object $expense): int => (int) $expense->expense_id, $expenses->getResult()));

        foreach ($expenses->getResult() as $expense) {
            $data_rows[] = get_expenses_data_row($expense, $numbers[(int) $expense->expense_id] ?? null);
        }

        if ($total_rows > 0) {
            $data_rows[] = get_expenses_data_last_row($expenses);
        }

        return $this->response->setJSON(['total' => $total_rows, 'rows' => $data_rows, 'payment_summary' => $payment_summary]);
    }

    /**
     * @param int $expense_id
     * @return void
     */
    public function getView(int $expense_id = NEW_ENTRY): string
    {
        $data = [];    // TODO: Duplicated code

        $data['expenses_info'] = $this->expense->get_info($expense_id);
        $expense_id = $data['expenses_info']->expense_id;

        $current_employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $can_assign_employee = $this->employee->has_grant('employees', $current_employee_id);

        $data['employees'] = [];
        if ($can_assign_employee) {
            foreach ($this->employee->get_all()->getResult() as $employee) {
                $data['employees'][$employee->person_id] = $employee->first_name . ' ' . $employee->last_name;
            }
        } else {
            $stored_employee_id = $expense_id == NEW_ENTRY ? $current_employee_id : $data['expenses_info']->employee_id;
            $stored_employee = $this->employee->get_info($stored_employee_id);
            $data['employees'][$stored_employee_id] = $stored_employee->first_name . ' ' . $stored_employee->last_name;
        }
        $data['can_assign_employee'] = $can_assign_employee;

        $expense_categories = [];
        foreach ($this->expense_category->get_all(0, 0, true)->getResultArray() as $row) {
            $expense_categories[$row['expense_category_id']] = $row['category_name'];
        }
        $data['expense_categories'] = $expense_categories;

        if ($expense_id == NEW_ENTRY) {
            $data['expenses_info']->date = date('Y-m-d H:i:s');
            $data['expenses_info']->employee_id = $current_employee_id;
        }

        $data['expense_number'] = (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', [(int) $expense_id])[(int) $expense_id] ?? null;

        $data['payments'] = [];
        foreach ($this->expense->get_expense_payment($expense_id)->getResult() as $payment) {
            foreach (get_object_vars($payment) as $property => $value) {
                $payment->$property = $value;
            }

            $data['payments'][] = $payment;
        }

        // Don't allow gift card to be a payment option in a sale transaction edit because it's a complex change
        $data['payment_options'] = $this->expense->get_payment_options();
        $data['treasury_options'] = $this->treasuryOptions((int) ($data['expenses_info']->treasury_id ?? 0));
        $data['branch_options']   = $data['treasury_options'] === [] ? [] : $this->branchOptions();
        $data['branch_selected']  = ($data['expenses_info']->treasury_branch_id ?? null) === null ? 0 : (int) $data['expenses_info']->branch_id;

        return view("expenses/form", $data);
    }

    /**
     * @param int $row_id
     * @return ResponseInterface
     */
    public function getRow(int $row_id): ResponseInterface
    {
        $expense_info = $this->expense->get_info($row_id);
        $data_row = get_expenses_data_row($expense_info, (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', [$row_id])[$row_id] ?? null);

        return $this->response->setJSON($data_row);
    }

    /**
     * @param int $expense_id
     * @return ResponseInterface
     */
    public function postSave(int $expense_id = NEW_ENTRY): ResponseInterface
    {
        $config = config(OSPOS::class)->settings;
        $newdate = $this->request->getPost('date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $date_formatter = date_create_from_format($config['dateformat'] . ' ' . $config['timeformat'], $newdate);

        $current_employee_id = $this->employee->get_logged_in_employee_info()->person_id;
        $submitted_employee_id = $this->request->getPost('employee_id', FILTER_SANITIZE_NUMBER_INT);

        if (!$this->employee->has_grant('employees', $current_employee_id)) {
            if ($expense_id == NEW_ENTRY) {
                $employee_id = $current_employee_id;
            } else {
                $existing_expense = $this->expense->get_info($expense_id);
                $employee_id = $existing_expense->employee_id;
            }
        } else {
            $employee_id = $submitted_employee_id;
        }

        $expense_data = [
            'date'                => $date_formatter->format('Y-m-d H:i:s'),
            'supplier_id'         => $this->request->getPost('supplier_id') == '' ? null : $this->request->getPost('supplier_id', FILTER_SANITIZE_NUMBER_INT),
            'supplier_tax_code'   => $this->request->getPost('supplier_tax_code', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'amount'              => parse_decimals($this->request->getPost('amount')),
            'tax_amount'          => parse_decimals($this->request->getPost('tax_amount')),
            'payment_type'        => $this->request->getPost('payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'expense_category_id' => $this->request->getPost('expense_category_id', FILTER_SANITIZE_NUMBER_INT),
            'description'         => $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'employee_id'         => $employee_id,
            'deleted'             => $this->request->getPost('deleted') != null
        ];

        $accountingOn = (new AccountingSettings())->isEnabled();

        if ($accountingOn && $this->request->getPost('treasury_id') !== null) {
            $expense_data['treasury_id'] = (int) $this->request->getPost('treasury_id', FILTER_SANITIZE_NUMBER_INT);
        }

        if ($this->request->getPost('expense_branch_id') !== null) {
            $expense_data['expense_branch_id'] = (int) $this->request->getPost('expense_branch_id', FILTER_SANITIZE_NUMBER_INT);
        }

        if ($this->expense->save_value($expense_data, $expense_id, (int) $current_employee_id)) {
            // New Expense
            if ($expense_id == NEW_ENTRY) {
                return $this->response->setJSON(['success' => true, 'message' => lang('Expenses.successful_adding'), 'id' => $expense_data['expense_id']]);
            } else { // Existing Expense
                return $this->response->setJSON(['success' => true, 'message' => lang('Expenses.successful_updating'), 'id' => $expense_id]);
            }
        } else { // Failure
            return $this->response->setJSON(['success' => false, 'message' => $this->refusal(lang('Expenses.error_adding_updating')), 'id' => NEW_ENTRY]);
        }
    }

    /**
     * @return ResponseInterface
     */
    public function postDelete(): ResponseInterface
    {
        $expenses_to_delete = $this->request->getPost('ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if ($this->expense->delete_list($expenses_to_delete, (int) $this->employee->get_logged_in_employee_info()->person_id)) {
            return $this->response->setJSON(['success' => true, 'message' => lang('Expenses.successful_deleted') . ' ' . count($expenses_to_delete) . ' ' . lang('Expenses.one_or_multiple'), 'ids' => $expenses_to_delete]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => $this->refusal(lang('Expenses.cannot_be_deleted')), 'ids' => $expenses_to_delete]);
        }
    }

    /**
     * What the books said about the change that was just refused, in words for the employee, or the
     * usual message when the books had nothing to say.
     */
    private function refusal(string $usual): string
    {
        $failure = $this->expense->accountingFailure();

        return $failure instanceof AccountingException || $failure instanceof BranchException || $failure instanceof PostingException
            ? AccountingMessage::of($failure)
            : $usual;
    }

    /**
     * The branches an expense can be charged to when it is paid from a treasury of another branch,
     * for an employee who works in more than one: the first choice leaves it to the branch of the
     * treasury, as it always was. Nobody else has a choice.
     *
     * @return array<int, string>
     */
    private function branchOptions(): array
    {
        $branches = (new BranchGuard())->selectableBranches((int) $this->employee->get_logged_in_employee_info()->person_id);

        if (count($branches) < 2) {
            return [];
        }

        $options = [0 => lang('Expenses.branch_of_treasury')];

        foreach ($branches as $branch) {
            $options[(int) $branch->branch_id] = $branch->code . ' - ' . $branch->name;
        }

        return $options;
    }

    /**
     * The treasuries an expense can be paid from, when there is a choice: the active ones the
     * employee may withdraw from, in the branches they work in, and the one the expense already
     * uses. Without accounting, or with nothing to choose from, there are none.
     *
     * @return array<int, string>
     */
    private function treasuryOptions(int $current): array
    {
        if (! (new AccountingSettings())->isEnabled()) {
            return [];
        }

        $employeeId = (int) $this->employee->get_logged_in_employee_info()->person_id;
        $treasuries = new TreasuryService();
        $branchIds  = (new BranchGuard())->allowedBranchIds($employeeId);
        $options    = [];

        foreach ((new TreasuryScopes())->usable($treasuries->all($branchIds, false), TreasuryScopes::WITHDRAW, $employeeId) as $treasury) {
            $options[(int) $treasury->treasury_id] = $treasury->name . ' (' . $treasury->branch_code . ')';
        }

        $carried = $current > 0 && ! isset($options[$current]) ? $treasuries->find($current) : null;

        if ($carried !== null) {
            $options[$current] = $carried->name . ' (' . $carried->branch_code . ')';
        }

        return $options === [] ? [] : [0 => lang('Expenses.treasury_default')] + $options;
    }
}
