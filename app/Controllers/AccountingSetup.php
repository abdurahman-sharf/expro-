<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\FiscalYearService;
use App\Libraries\Accounting\JournalDescriptionTemplate;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\ReadinessIssue;
use App\Libraries\Accounting\SensitivePermissions;
use App\Libraries\Pricing\SalesControlSettings;
use App\Models\Price_list;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingSetup extends AccountingController
{
    private const PERMISSION_LABELS = [
        AccountingGuard::JOURNALS_EDIT      => 'Accounting_journals.journals_edit_details',
        AccountingGuard::SETTINGS_MANAGE    => 'Accounting_settings.settings_manage',
        AccountingGuard::PERIODS_CLOSE      => 'Accounting_periods.periods_close',
        AccountingGuard::PERIODS_REOPEN     => 'Accounting_periods.periods_reopen',
        AccountingGuard::OPENING_MANAGE     => 'Accounting_periods.opening_manage',
        AccountingGuard::ROUTING_MANAGE     => 'Accounting_routing.routing_manage',
        AccountingGuard::TREASURIES_MANAGE  => 'Accounting_treasuries.treasuries_manage',
        AccountingGuard::PAYABLES_MANAGE    => 'Accounting_payables.payables_manage',
        AccountingGuard::PAYABLES_REFUND    => 'Accounting_payables.payables_refund',
        AccountingGuard::RECEIVABLES_MANAGE => 'Accounting_receivables.receivables_manage',
        AccountingGuard::RECEIVABLES_REFUND => 'Accounting_receivables.receivables_refund',
        AccountingGuard::RETURN_OVERRIDE    => 'Sales.return_override',
        AccountingGuard::PRICE_OVERRIDE     => 'Sales.price_override',
        AccountingGuard::CREDIT_OVERRIDE    => 'Sales.credit_override',
        AccountingGuard::INTERBRANCH_SETTLE => 'Accounting_treasuries.interbranch_settle',
        AccountingGuard::YEAR_END_CLOSE     => 'Accounting_periods.year_end_close',
        AccountingGuard::BACKFILL_MANAGE    => 'Accounting_periods.backfill_manage',
        AccountingGuard::ROLES_MANAGE       => 'Roles.roles_manage',
    ];

    private AccountingSettings $settings;
    private FiscalYearService $years;
    private SalesControlSettings $controls;

    public function __construct()
    {
        parent::__construct('accounting_settings', false);

        $this->settings = new AccountingSettings(null, null, $this->branchGuard());
        $this->years    = new FiscalYearService(null, null, $this->branchGuard());
        $this->controls = new SalesControlSettings();
    }

    public function getIndex(): string
    {
        $issues    = $this->settings->readiness();
        $needsYear = $this->needsFirstYear($issues);

        return view('accounting_settings/manage', [
            'enabled'                  => $this->settings->isEnabled(),
            'issues'                   => array_map(fn (ReadinessIssue $issue): string => $this->describe($issue), $issues),
            'notices'                  => array_map(fn (ReadinessIssue $issue): string => $this->describe($issue), $this->settings->notices()),
            'routing_url'              => $this->can('accounting_routing') ? base_url('accounting_routing') : null,
            'currency'                 => $this->settings->baseCurrency(),
            'start_date'               => (string) $this->settings->startDate(),
            'auto_year'                => $this->settings->autoFiscalYear(),
            'expense_account_required' => $this->settings->expenseAccountRequired(),
            'revenue_account_required' => $this->settings->revenueAccountRequired(),
            'lineDescriptionRequired'  => $this->settings->lineDescriptionRequired(),
            'customer_opening'         => $this->settings->customerOpeningEnabled(),
            'backfill_enabled'         => $this->settings->backfillEnabled(),
            'sales_adjustment'         => $this->settings->salesAdjustmentEnabled(),
            'sales_shipping'           => $this->settings->salesShippingEnabled(),
            'shipping_tax_percent'     => $this->settings->shippingTaxPercent(),
            'debit_note'               => $this->settings->debitNoteEnabled(),
            'journal_description_sales'     => $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::SALES),
            'journal_description_purchases' => $this->settings->journalDescriptionTemplate(JournalDescriptionTemplate::PURCHASES),
            'template_max'             => JournalDescriptionTemplate::MAX,
            'template_placeholders'    => array_map(static fn (string $name): string => '{' . $name . '}', JournalDescriptionTemplate::PLACEHOLDERS),
            'controls'                 => $this->controls->all(),
            'controlLists'             => array_column(array_map(static fn (object $list): array => ['id' => (int) $list->price_list_id, 'name' => $list->name], model(Price_list::class)->allLists()), 'name', 'id'),
            'controlCategories'        => $this->controls->itemCategories(),
            'locked'                   => $this->settings->isLocked(),
            'next_number'              => $this->settings->lastManualNumber() + 1,
            'can_manage'               => $this->canManageSettings(),
            'first_year'               => $needsYear ? $this->years->suggest() : null,
            'can_first_year'           => $needsYear && $this->canManage(AccountingGuard::PERIODS_MANAGE),
            'holders'                  => array_map(static fn (array $holder): array => [
                'name'         => $holder['name'] !== '' ? $holder['name'] : $holder['username'],
                'username'     => $holder['username'],
                'permissions'  => array_map(static fn (string $permissionId): string => lang(self::PERMISSION_LABELS[$permissionId]), $holder['permissions']),
                'all_branches' => $holder['all_branches'],
                'branches'     => $holder['branches'],
            ], (new SensitivePermissions())->holders()),
        ]);
    }

    public function postSaveSettings(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $input = [
                'base_currency'            => (string) $this->request->getPost('base_currency'),
                'start_date'               => (string) $this->request->getPost('start_date'),
                'auto_fiscal_year'         => $this->request->getPost('auto_fiscal_year') === '1',
                'expense_account_required' => $this->request->getPost('expense_account_required') === '1',
            ];

            if ($this->request->getPost('revenue_account_required') !== null) {
                $input['revenue_account_required'] = $this->request->getPost('revenue_account_required') === '1';
            }

            if ($this->request->getPost('line_description_required') !== null) {
                $input['line_description_required'] = $this->request->getPost('line_description_required') === '1';
            }

            if ($this->request->getPost('customer_opening') !== null) {
                $input['customer_opening'] = $this->request->getPost('customer_opening') === '1';
            }

            if ($this->request->getPost('backfill_enabled') !== null) {
                $input['backfill_enabled'] = $this->request->getPost('backfill_enabled') === '1';
            }

            foreach (['sales_adjustment', 'sales_shipping', 'debit_note'] as $flag) {
                if ($this->request->getPost($flag) !== null) {
                    $input[$flag] = $this->request->getPost($flag) === '1';
                }
            }

            foreach (['shipping_tax_percent', 'journal_description_sales', 'journal_description_purchases'] as $text) {
                if ($this->request->getPost($text) !== null) {
                    $input[$text] = (string) $this->request->getPost($text);
                }
            }

            $this->settings->update($input, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_settings.saved'), 'id' => 1];
        });
    }

    public function postSaveControls(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $input = [];

            foreach (['price_lists', 'change_list', 'minimum_price', 'max_discount_enabled', 'credit_limit', 'credit_period'] as $flag) {
                if ($this->request->getPost($flag) !== null) {
                    $input[$flag] = $this->request->getPost($flag) === '1';
                }
            }

            foreach (['default_list', 'max_discount'] as $field) {
                if ($this->request->getPost($field) !== null) {
                    $input[$field] = is_array($this->request->getPost($field)) ? 'not a value' : (string) $this->request->getPost($field);
                }
            }

            if ($this->request->getPost('exempt_categories') !== null) {
                $categories = $this->request->getPost('exempt_categories');

                $input['exempt_categories'] = is_array($categories) ? array_values($categories) : [];
            }

            $this->controls->update($input, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_settings.controls_saved'), 'id' => 1];
        });
    }

    public function postFirstYear(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $id = $this->years->create([
                'name'       => (string) $this->request->getPost('name'),
                'start_date' => (string) $this->request->getPost('start_date'),
                'end_date'   => (string) $this->request->getPost('end_date'),
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_settings.year_created'), 'id' => $id];
        });
    }

    public function postEnable(): ResponseInterface
    {
        try {
            return $this->attempt(function (): array {
                $this->settings->enable($this->employeeId());

                return ['success' => true, 'message' => lang('Accounting_settings.enabled_now'), 'id' => 1];
            });
        } catch (AccountingNotReadyException $exception) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Accounting_settings.not_ready') . ' ' . implode(' ', array_map(fn (ReadinessIssue $issue): string => $this->describe($issue), $exception->issues)),
                'id'      => NEW_ENTRY,
            ]);
        }
    }

    public function postDisable(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $this->settings->disable($this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_settings.disabled_now'), 'id' => 1];
        });
    }

    private function canManageSettings(): bool
    {
        return $this->canManage(AccountingGuard::SETTINGS_MANAGE);
    }

    /**
     * @param list<ReadinessIssue> $issues
     */
    private function needsFirstYear(array $issues): bool
    {
        foreach ($issues as $issue) {
            if (in_array($issue->code, [ReadinessCode::NoFiscalYear, ReadinessCode::StartDateOutsideFiscalYear], true)) {
                return true;
            }
        }

        return false;
    }

    private function describe(ReadinessIssue $issue): string
    {
        return lang('Accounting_settings.readiness_' . $issue->code->value, [(string) $issue->subject]);
    }
}
