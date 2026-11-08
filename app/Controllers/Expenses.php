<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Documents\RendersPdf;
use App\Libraries\Documents\VoucherDocument;
use App\Libraries\Documents\VoucherDocuments;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Models\Expense;
use App\Models\Expense_category;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use Config\Services;

class Expenses extends Secure_Controller
{
    use RendersPdf;

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
        $requestFilters = array_fill_keys($this->request->getGet('filters', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? [], true);
        $filters = array_merge($filters, $requestFilters);
        $expenses = $this->expense->search($search, $filters, $limit, $offset, $sort, $order);
        $totalRows = $this->expense->get_found_rows($search, $filters);
        $payments = $this->expense->get_payments_summary($search, $filters);
        $paymentSummary = get_expenses_manage_payments_summary($payments, $expenses);
        $dataRows = [];

        $numbers = (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', array_map(static fn (object $expense): int => (int) $expense->expense_id, $expenses->getResult()));

        foreach ($expenses->getResult() as $expense) {
            $dataRows[] = get_expenses_data_row($expense, $numbers[(int) $expense->expense_id] ?? null);
        }

        if ($totalRows > 0) {
            $dataRows[] = get_expenses_data_last_row($expenses);
        }

        return $this->response->setJSON(['total' => $totalRows, 'rows' => $dataRows, 'payment_summary' => $paymentSummary]);
    }

    /**
     * @param int $expenseId
     * @return void
     */
    public function getView(int $expenseId = NEW_ENTRY): string
    {
        if ($expenseId !== NEW_ENTRY && $this->expense->outsideRecordScope($expenseId)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $data = [];    // TODO: Duplicated code

        $data['expenses_info'] = $this->expense->get_info($expenseId);
        $expenseId = $data['expenses_info']->expense_id;

        $currentEmployeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $canAssignEmployee = $this->employee->has_grant('employees', $currentEmployeeId);

        $data['employees'] = [];
        if ($canAssignEmployee) {
            foreach ($this->employee->get_all()->getResult() as $employee) {
                $data['employees'][$employee->person_id] = $employee->first_name . ' ' . $employee->last_name;
            }
        } else {
            $storedEmployeeId = $expenseId == NEW_ENTRY ? $currentEmployeeId : $data['expenses_info']->employee_id;
            $storedEmployee = $this->employee->get_info($storedEmployeeId);
            $data['employees'][$storedEmployeeId] = $storedEmployee->first_name . ' ' . $storedEmployee->last_name;
        }
        $data['can_assign_employee'] = $canAssignEmployee;

        $expenseCategories = [];
        foreach ($this->expense_category->get_all(0, 0, true)->getResultArray() as $row) {
            $expenseCategories[$row['expense_category_id']] = $row['category_name'];
        }
        $data['expense_categories'] = $expenseCategories;

        if ($expenseId == NEW_ENTRY) {
            $data['expenses_info']->date = date('Y-m-d H:i:s');
            $data['expenses_info']->employee_id = $currentEmployeeId;
        }

        $data['expenseNumber'] = (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', [(int) $expenseId])[(int) $expenseId] ?? null;

        $data['payments'] = [];
        foreach ($this->expense->get_expense_payment($expenseId)->getResult() as $payment) {
            foreach (get_object_vars($payment) as $property => $value) {
                $payment->$property = $value;
            }

            $data['payments'][] = $payment;
        }

        // Don't allow gift card to be a payment option in a sale transaction edit because it's a complex change
        $data['payment_options'] = $this->expense->get_payment_options();

        $followed = $expenseId == NEW_ENTRY || $data['expenses_info']->branch_id !== null;

        $data['treasury_options']     = $followed ? $this->treasuryOptions((int) ($data['expenses_info']->treasury_id ?? 0)) : [];
        $data['no_treasury_payments'] = $this->paymentsWithoutTreasury(array_keys($data['payment_options']));
        $data['branchOptions']        = $data['treasury_options'] === [] ? [] : $this->branchOptions();
        $data['branchSelected']       = ($data['expenses_info']->treasury_branch_id ?? null) === null ? 0 : (int) $data['expenses_info']->branch_id;

        return view("expenses/form", $data);
    }

    /**
     * The payment voucher of an expense, ready to print. Used in app/Views/expenses/form.php
     *
     * @noinspection PhpUnused
     */
    public function getVoucher(int $expenseId): string
    {
        $voucher = $this->voucherOrNotFound($expenseId);

        return view('documents/screen', [
            'inner'     => 'documents/voucher',
            'data'      => ['voucher' => $voucher],
            'pdfUrl'    => 'expenses/voucherPdf/' . $expenseId,
            'backUrl'   => 'expenses',
            'backLabel' => lang('Documents.back'),
            'backIcon'  => 'bi-arrow-left',
        ]);
    }

    /**
     * @noinspection PhpUnused
     */
    public function getVoucherPdf(int $expenseId): ResponseInterface
    {
        $voucher = $this->voucherOrNotFound($expenseId);

        return $this->pdfResponse($this->response, view('documents/voucher_pdf', ['voucher' => $voucher]), $voucher->title, $voucher->number);
    }

    private function voucherOrNotFound(int $expenseId): VoucherDocument
    {
        $voucher = (new VoucherDocuments())->expense($expenseId, (int) $this->employee->get_logged_in_employee_info()->person_id);

        if ($voucher === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $voucher;
    }

    /**
     * @param int $row_id
     * @return ResponseInterface
     */
    public function getRow(int $row_id): ResponseInterface
    {
        if ($this->expense->outsideRecordScope($row_id)) {
            return $this->response->setStatusCode(404)->setJSON(['success' => false, 'message' => lang('Roles.record_unavailable')]);
        }

        $expenseInfo = $this->expense->get_info($row_id);
        $dataRow = get_expenses_data_row($expenseInfo, (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', [$row_id])[$row_id] ?? null);

        return $this->response->setJSON($dataRow);
    }

    /**
     * @param int $expenseId
     * @return ResponseInterface
     */
    public function postSave(int $expenseId = NEW_ENTRY): ResponseInterface
    {
        $config = config(OSPOS::class)->settings;
        $newDate = $this->request->getPost('date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        $dateFormatter = date_create_from_format($config['dateformat'] . ' ' . $config['timeformat'], $newDate);

        $currentEmployeeId = $this->employee->get_logged_in_employee_info()->person_id;
        $submittedEmployeeId = $this->request->getPost('employee_id', FILTER_SANITIZE_NUMBER_INT);

        if (!$this->employee->has_grant('employees', $currentEmployeeId)) {
            if ($expenseId == NEW_ENTRY) {
                $employeeId = $currentEmployeeId;
            } else {
                $existingExpense = $this->expense->get_info($expenseId);
                $employeeId = $existingExpense->employee_id;
            }
        } else {
            $employeeId = $submittedEmployeeId;
        }

        $expenseData = [
            'date'                => $dateFormatter->format('Y-m-d H:i:s'),
            'supplier_id'         => $this->request->getPost('supplier_id') == '' ? null : $this->request->getPost('supplier_id', FILTER_SANITIZE_NUMBER_INT),
            'supplier_tax_code'   => $this->request->getPost('supplier_tax_code', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'amount'              => parse_decimals($this->request->getPost('amount')),
            'tax_amount'          => parse_decimals($this->request->getPost('tax_amount')),
            'payment_type'        => $this->request->getPost('payment_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'expense_category_id' => $this->request->getPost('expense_category_id', FILTER_SANITIZE_NUMBER_INT),
            'description'         => $this->request->getPost('description', FILTER_SANITIZE_FULL_SPECIAL_CHARS),
            'employee_id'         => $employeeId,
            'deleted'             => $this->request->getPost('deleted') != null
        ];

        $accountingOn = (new AccountingSettings())->isEnabled();

        if ($accountingOn && $this->request->getPost('treasury_id') !== null) {
            $expenseData['treasury_id'] = (int) $this->request->getPost('treasury_id', FILTER_SANITIZE_NUMBER_INT);
        }

        if ($this->request->getPost('expense_branch_id') !== null) {
            $expenseData['expense_branch_id'] = (int) $this->request->getPost('expense_branch_id', FILTER_SANITIZE_NUMBER_INT);
        }

        if ($this->expense->save_value($expenseData, $expenseId, (int) $currentEmployeeId)) {
            // New Expense
            if ($expenseId == NEW_ENTRY) {
                return $this->response->setJSON(['success' => true, 'message' => lang('Expenses.successful_adding'), 'id' => $expenseData['expense_id']]);
            } else { // Existing Expense
                return $this->response->setJSON(['success' => true, 'message' => lang('Expenses.successful_updating'), 'id' => $expenseId]);
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
        $expensesToDelete = $this->request->getPost('ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

        if ($this->expense->delete_list($expensesToDelete, (int) $this->employee->get_logged_in_employee_info()->person_id)) {
            return $this->response->setJSON(['success' => true, 'message' => lang('Expenses.successful_deleted') . ' ' . count($expensesToDelete) . ' ' . lang('Expenses.one_or_multiple'), 'ids' => $expensesToDelete]);
        } else {
            return $this->response->setJSON(['success' => false, 'message' => $this->refusal(lang('Expenses.cannot_be_deleted')), 'ids' => $expensesToDelete]);
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
            $options[(int) $branch->branch_id] = esc($branch->code . ' - ' . $branch->name);
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
            $options[(int) $treasury->treasury_id] = esc($treasury->name . ' (' . $treasury->branch_code . ')');
        }

        $carried = $current > 0 && ! isset($options[$current]) ? $treasuries->find($current) : null;

        if ($carried !== null) {
            $options[$current] = esc($carried->name . ' (' . $carried->branch_code . ')');
        }

        return $options === [] ? [] : [0 => lang('Expenses.treasury_default')] + $options;
    }

    /**
     * The ways of payment an expense is not paid from a treasury with, which the form uses to hide
     * the treasury it would ignore.
     *
     * @param list<string> $labels
     *
     * @return list<string>
     */
    private function paymentsWithoutTreasury(array $labels): array
    {
        return array_values(array_filter($labels, static fn (string $label): bool => PaymentType::fromLabel($label)?->usesTreasury() !== true));
    }
}
