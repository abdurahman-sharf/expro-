<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountPicker;
use App\Models\Employee;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The search a picker of accounts, customers or suppliers asks while someone types, at most twenty entries
 * for the branches the employee works in. A kind is answered only to someone who holds the permission of a
 * screen that uses it.
 */
class AccountingLookup extends AccountingController
{
    private const SCREENS = [
        AccountPicker::ACCOUNT  => ['accounting_journals', 'accounting_periods', 'accounting_chart', 'accounting_reports', 'accounting_routing'],
        AccountPicker::CUSTOMER => ['accounting_receivables', 'customers', 'sales'],
        AccountPicker::SUPPLIER => ['accounting_payables', 'suppliers', 'receivings', 'recurring_expenses'],
    ];

    public function __construct()
    {
        parent::__construct(self::entryModule());
    }

    public function getSearch(): ResponseInterface
    {
        $kind = (string) $this->request->getGet('kind');
        $mode = (string) $this->request->getGet('mode');

        if (! in_array($kind, AccountPicker::KINDS, true) || ! in_array($mode, AccountPicker::MODES, true)) {
            return $this->response->setStatusCode(400);
        }

        if (! $this->mayPick($kind)) {
            return $this->response->setStatusCode(403);
        }

        return $this->response->setJSON([...(new AccountPicker())->search($kind, $mode, (string) $this->request->getGet('term'), $this->branchIds()), 'limit' => AccountPicker::LIMIT]);
    }

    /**
     * The search belongs to no module of its own, so the employee comes in through the first module they hold
     * among the screens that use it, and each kind is then checked against its own screens.
     */
    private static function entryModule(): string
    {
        $employee = model(Employee::class);
        $person   = $employee->get_logged_in_employee_info();

        foreach (array_unique(array_merge(...array_values(self::SCREENS))) as $module) {
            if ($person && $employee->has_module_grant($module, (int) $person->person_id)) {
                return $module;
            }
        }

        return self::SCREENS[AccountPicker::ACCOUNT][0];
    }

    private function mayPick(string $kind): bool
    {
        foreach (self::SCREENS[$kind] as $screen) {
            if ($this->can($screen)) {
                return true;
            }
        }

        return false;
    }
}
