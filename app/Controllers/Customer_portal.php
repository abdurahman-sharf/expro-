<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalSettings;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * What an employee does with the portal of a customer: switch the portal on or off, make the account of a
 * customer, give it a new temporary password and disable or enable it. Every action asks for the sensitive
 * permission to manage the portal, a temporary password is shown once in the answer and kept nowhere, and
 * nothing is sent to the customer: the employee hands the password over.
 */
class Customer_portal extends Secure_Controller
{
    private PortalAccounts $accounts;
    private PortalSettings $settings;

    public function __construct()
    {
        parent::__construct('customers');

        $this->accounts = new PortalAccounts();
        $this->settings = new PortalSettings();
    }

    public function postCreate(int $customerId): ResponseInterface
    {
        return $this->attempt(function () use ($customerId): array {
            $made = $this->accounts->create($customerId, (string) $this->request->getPost('username'), $this->employeeId());

            return ['temporary_password' => $made['temporary_password'], 'account_id' => $made['account_id'], 'message' => lang('Portal.manage_created')];
        });
    }

    public function postReset(int $accountId): ResponseInterface
    {
        return $this->attempt(fn (): array => ['temporary_password' => $this->accounts->resetPassword($accountId, $this->employeeId()), 'message' => lang('Portal.manage_reset_done')]);
    }

    public function postStatus(int $accountId): ResponseInterface
    {
        return $this->attempt(function () use ($accountId): array {
            $this->accounts->setActive($accountId, $this->request->getPost('active') === '1', $this->employeeId());

            return ['message' => lang('Portal.manage_status_changed')];
        });
    }

    public function postEnabled(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $this->settings->setEnabled($this->request->getPost('enabled') === '1', $this->employeeId());

            return ['message' => lang('Portal.manage_setting_saved')];
        });
    }

    /**
     * @param callable(): array<string, mixed> $action
     */
    private function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON(['success' => true] + $action());
        } catch (AccountingException $failure) {
            return $this->response->setStatusCode($failure->error === AccountingError::PermissionDenied ? 403 : 200)->setJSON(['success' => false, 'message' => AccountingMessage::of($failure)]);
        }
    }

    private function employeeId(): int
    {
        return (int) $this->employee->get_logged_in_employee_info()->person_id;
    }
}
