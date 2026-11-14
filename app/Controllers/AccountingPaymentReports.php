<?php

namespace App\Controllers;

use App\Libraries\Accounting\OperationalReports\AdvancePaymentsReport;
use App\Libraries\Accounting\OperationalReports\DateFilter;
use App\Libraries\Accounting\OperationalReports\PaymentGroup;
use App\Libraries\Accounting\OperationalReports\PaymentsReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

/**
 * What customers paid, from the payments whose journals stand: by customer, invoice employee, collector,
 * method, treasury, day, week, month or year, in summary or payment by payment, and the advances that
 * belong to no invoice with what is left of each. Both are read under the permission of the payments
 * reports, and the first says whether its total is what the accounts the money went into hold of the same
 * payments.
 */
class AccountingPaymentReports extends AccountingOperationalReport
{
    private const PERMISSION      = 'reports_payments';
    private const PAYMENT_CARRIED = ['group', 'view', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'user', 'collector', 'method', 'treasury', 'show'];
    private const FILTERS         = ['user' => 'reports_employees', 'collector' => 'reports_employees'];
    private const ADVANCE_FILTERS = ['user' => 'reports_employees'];
    private const ADVANCE_CARRIED = ['preset', 'mode', 'from', 'to', 'date', 'customer', 'user', 'method', 'state', 'show'];

    private PaymentsReport $payments;
    private AdvancePaymentsReport $advances;

    public function __construct()
    {
        parent::__construct();

        $this->payments = new PaymentsReport();
        $this->advances = new AdvancePaymentsReport();
    }

    public function getIndex(): ResponseInterface|string
    {
        $this->allow(self::PERMISSION);

        $group = PaymentGroup::tryFrom($this->query('group')) ?? PaymentGroup::Customer;

        $this->allow($group->permission());
        $this->allowFilters(self::FILTERS);

        $scope = $this->requestedScope();
        $dates = $this->dates();
        $table = $this->runs($dates) ? $this->paymentsTable($scope, $group, $dates) : null;
        $query = $this->carried(self::PAYMENT_CARRIED, $scope);

        return $this->page([
            'titleKey'      => 'Accounting_operational_reports.payments_title',
            'route'         => 'accounting_reports/payments',
            'table'         => $table,
            'dates'         => $dates,
            'scope'         => $scope,
            'branches'      => $this->branchChoices($scope),
            'scopeLabel'    => $this->scopeLabel($scope),
            'error'         => $dates->refusal === null ? null : $this->messageFor($dates->refusal),
            'groups'        => $this->groupChoices(),
            'group'         => $group->value,
            'groupLabelKey' => 'Accounting_operational_reports.group_by',
            'details'       => $this->wantsDetails(),
            'offersView'    => true,
            'fields'        => $this->permittedFields([
                $this->customerField(),
                ['type' => 'select', 'name' => 'user', 'labelKey' => 'Accounting_operational_reports.invoice_user', 'options' => $this->employeeChoices(), 'value' => $this->query('user')],
                ['type' => 'select', 'name' => 'collector', 'labelKey' => 'Accounting_operational_reports.collector', 'options' => $this->employeeChoices(), 'value' => $this->query('collector')],
                ['type' => 'select', 'name' => 'method', 'labelKey' => 'Accounting_operational_reports.method', 'options' => $this->methodChoices(), 'value' => $this->query('method')],
                ['type' => 'select', 'name' => 'treasury', 'labelKey' => 'Accounting_operational_reports.treasury', 'options' => $this->moneyChoices(), 'value' => $this->query('treasury')],
            ], self::FILTERS),
            'csvUrl'   => base_url('accounting_reports/payments/csv') . ($query === [] ? '' : '?' . http_build_query($query)),
            'siblings' => [['label' => lang('Accounting_operational_reports.advances_title'), 'url' => base_url('accounting_reports/advance_payments')]],
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $this->allow(self::PERMISSION);

        $group = PaymentGroup::tryFrom($this->query('group')) ?? PaymentGroup::Customer;

        $this->allow($group->permission());
        $this->allowFilters(self::FILTERS);

        $dates = $this->dates();

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('payments_' . $group->value, $this->paymentsTable($this->requestedScope(), $group, $dates), 'Accounting_operational_reports.payments_title');
    }

    public function getAdvances(): ResponseInterface|string
    {
        $this->allow(self::PERMISSION);
        $this->allowFilters(self::ADVANCE_FILTERS);

        $scope = $this->requestedScope();
        $dates = $this->dates();
        $table = $this->runs($dates) ? $this->advancesTable($scope, $dates) : null;
        $query = $this->carried(self::ADVANCE_CARRIED, $scope);

        return $this->page([
            'titleKey'      => 'Accounting_operational_reports.advances_title',
            'route'         => 'accounting_reports/advance_payments',
            'table'         => $table,
            'dates'         => $dates,
            'scope'         => $scope,
            'branches'      => $this->branchChoices($scope),
            'scopeLabel'    => $this->scopeLabel($scope),
            'error'         => $dates->refusal === null ? null : $this->messageFor($dates->refusal),
            'groups'        => [],
            'group'         => '',
            'groupLabelKey' => 'Accounting_operational_reports.group_by',
            'details'       => false,
            'offersView'    => false,
            'fields'        => $this->permittedFields([
                $this->customerField(),
                ['type' => 'select', 'name' => 'user', 'labelKey' => 'Accounting_operational_reports.created_by', 'options' => $this->employeeChoices(), 'value' => $this->query('user')],
                ['type' => 'select', 'name' => 'method', 'labelKey' => 'Accounting_operational_reports.method', 'options' => $this->methodChoices(), 'value' => $this->query('method')],
                ['type' => 'select', 'name' => 'state', 'labelKey' => 'Accounting_operational_reports.advance_state', 'options' => ['' => lang('Accounting_operational_reports.state_any'), 'open' => lang('Accounting_operational_reports.state_open'), 'spent' => lang('Accounting_operational_reports.state_spent')], 'value' => $this->choice('state', AdvancePaymentsReport::STATES, '')],
            ], self::ADVANCE_FILTERS),
            'csvUrl'   => base_url('accounting_reports/advance_payments/csv') . ($query === [] ? '' : '?' . http_build_query($query)),
            'siblings' => [['label' => lang('Accounting_operational_reports.payments_title'), 'url' => base_url('accounting_reports/payments')]],
        ]);
    }

    public function getAdvancesCsv(): ResponseInterface
    {
        $this->allow(self::PERMISSION);
        $this->allowFilters(self::ADVANCE_FILTERS);

        $dates = $this->dates();

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('advance_payments', $this->advancesTable($this->requestedScope(), $dates), 'Accounting_operational_reports.advances_title');
    }

    private function paymentsTable(ReportScope $scope, PaymentGroup $group, DateFilter $dates): ReportTable
    {
        return $this->payments->build(
            $scope,
            $group,
            $this->wantsDetails(),
            $dates->from,
            $dates->to,
            $this->positiveId('customer'),
            $this->positiveId('user'),
            $this->positiveId('collector'),
            $this->query('method'),
            $this->positiveId('treasury'),
            $dates->isOpenStart(),
        );
    }

    private function advancesTable(ReportScope $scope, DateFilter $dates): ReportTable
    {
        return $this->advances->build($scope, $dates->from, $dates->to, $this->positiveId('customer'), $this->positiveId('user'), $this->query('method'), $this->choice('state', AdvancePaymentsReport::STATES, ''));
    }

    /**
     * @return array<string, string> The groupings the employee may open.
     */
    private function groupChoices(): array
    {
        $choices = [];

        foreach (PaymentGroup::cases() as $option) {
            if ($this->can($option->permission())) {
                $choices[$option->value] = lang($option->languageKey());
            }
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function methodChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.all_methods')];

        foreach (PaymentType::cases() as $type) {
            $choices[$type->value] = lang($type->languageKey());
        }

        return $choices;
    }

    /**
     * The accounts the money of customer payments went into, by name: the treasuries and banks and the other
     * accounts a payment method is routed to.
     *
     * @return array<string, string>
     */
    private function moneyChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.all_treasuries')];
        $db      = Database::connect();

        foreach ($db->query(
            'SELECT DISTINCT a.account_id, a.name FROM ' . $db->prefixTable('accounting_accounts') . ' a
             JOIN ' . $db->prefixTable('accounting_journal_lines') . ' l ON l.account_id = a.account_id
             JOIN ' . $db->prefixTable('accounting_journals') . ' j ON j.journal_id = l.journal_id AND j.source_type = ?
             WHERE a.entity_type IS NULL OR a.entity_type <> ?
             ORDER BY a.name ASC LIMIT 200',
            ['sale_payment', 'customer'],
        )->getResult() as $account) {
            $choices[(string) $account->account_id] = (string) $account->name;
        }

        return $choices;
    }
}
