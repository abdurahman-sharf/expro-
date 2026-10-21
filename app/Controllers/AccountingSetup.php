<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\FiscalYearService;
use App\Libraries\Accounting\ReadinessCode;
use App\Libraries\Accounting\ReadinessIssue;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingSetup extends AccountingController
{
    private AccountingSettings $settings;
    private FiscalYearService $years;

    public function __construct()
    {
        parent::__construct('accounting_settings', false);

        $this->settings = new AccountingSettings(null, null, $this->branchGuard());
        $this->years    = new FiscalYearService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $issues    = $this->settings->readiness();
        $needsYear = $this->needsFirstYear($issues);

        return view('accounting_settings/manage', [
            'enabled'        => $this->settings->isEnabled(),
            'issues'         => array_map(fn (ReadinessIssue $issue): string => $this->describe($issue), $issues),
            'currency'       => $this->settings->baseCurrency(),
            'start_date'     => (string) $this->settings->startDate(),
            'auto_year'      => $this->settings->autoFiscalYear(),
            'locked'         => $this->settings->isLocked(),
            'next_number'    => $this->settings->lastManualNumber() + 1,
            'can_manage'     => $this->canManageSettings(),
            'first_year'     => $needsYear ? $this->years->suggest() : null,
            'can_first_year' => $needsYear && $this->canManage(AccountingGuard::PERIODS_MANAGE),
        ]);
    }

    public function postSave(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $this->settings->update([
                'base_currency'    => (string) $this->request->getPost('base_currency'),
                'start_date'       => (string) $this->request->getPost('start_date'),
                'auto_fiscal_year' => $this->request->getPost('auto_fiscal_year') === '1',
            ], $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_settings.saved'), 'id' => 1];
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
