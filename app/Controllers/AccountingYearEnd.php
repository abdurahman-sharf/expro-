<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\YearEndBranchPlan;
use App\Libraries\Accounting\YearEndClosing;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingYearEnd extends AccountingController
{
    private YearEndClosing $closing;

    public function __construct()
    {
        parent::__construct('accounting_periods');

        $this->closing = new YearEndClosing(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $years   = $this->closing->years();
        $options = $this->scopeOptions();
        $scope   = $this->scopeParameter($options);
        $yearId  = $this->yearParameter($years);
        $canRun  = $this->can(AccountingGuard::YEAR_END_CLOSE);
        $preview = null;
        $error   = null;

        if ($yearId !== null && $options !== []) {
            try {
                $preview = $this->closing->preview($scope === 'company' ? null : (int) $scope, $yearId, $this->employeeId());
            } catch (AccountingException|BranchException $refusal) {
                $error = $this->messageFor($refusal);
            }
        }

        return view('accounting_periods/year_end', [
            'years'       => $years,
            'year_id'     => $yearId,
            'scopes'      => $options,
            'scope'       => $scope,
            'can_run'     => $canRun,
            'preview'     => $preview,
            'error'       => $error,
            'blockers'    => $preview === null ? [] : array_map(fn (AccountingException $blocker): string => $this->messageFor($blocker), $preview->blockers),
            'closings'    => $this->closing->closings($this->employeeId()),
            'notice'      => session()->getFlashdata('accounting_notice'),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
            'status_text' => static fn (YearEndBranchPlan $plan): string => lang('Accounting_periods.year_end_status_' . $plan->status->value),
            'return_url'  => base_url('accounting_periods/year_end'),
        ]);
    }

    public function getApproveForm(): string
    {
        $scope  = $this->scopeParameter($this->scopeOptions());
        $yearId = (int) $this->request->getGet('year');

        try {
            $preview = $this->closing->preview($scope === 'company' ? null : (int) $scope, $yearId, $this->employeeId());
        } catch (AccountingException|BranchException) {
            throw PageNotFoundException::forPageNotFound();
        }

        if (! $preview->canApprove()) {
            throw PageNotFoundException::forPageNotFound();
        }

        $shown = $this->request->getGet('fingerprint');

        if (! is_string($shown) || preg_match('/^[0-9a-f]{64}$/', $shown) !== 1) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/year_end_approve_form', [
            'preview'     => $preview,
            'scope'       => $scope,
            'fingerprint' => $shown,
            'year_id'     => $yearId,
        ]);
    }

    public function postApprove(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $scope  = $this->postedScope();
            $yearId = (int) $this->request->getPost('year');

            $this->closing->approve($scope === 'company' ? null : (int) $scope, $yearId, (string) $this->request->getPost('fingerprint'), $this->employeeId());

            $message = lang('Accounting_periods.year_end_approved');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $yearId, 'redirect' => $this->url($yearId, $scope)];
        });
    }

    public function getReverseForm(int $closingId): string
    {
        if (! $this->can(AccountingGuard::YEAR_END_CLOSE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $closing = $this->visibleClosing($closingId);

        if ($closing === null || $closing->status !== 'active') {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/year_end_reverse_form', ['closing' => $closing]);
    }

    public function postReverse(int $closingId): ResponseInterface
    {
        return $this->attempt(function () use ($closingId): array {
            $this->closing->reverse($closingId, (string) $this->request->getPost('reason'), $this->employeeId());

            $message = lang('Accounting_periods.year_end_reversed');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $closingId, 'redirect' => base_url('accounting_periods/year_end')];
        });
    }

    private function visibleClosing(int $closingId): ?object
    {
        foreach ($this->closing->closings($this->employeeId()) as $closing) {
            if ((int) $closing->closing_id === $closingId) {
                return $closing;
            }
        }

        return null;
    }

    private function url(int $yearId, string $scope): string
    {
        return base_url('accounting_periods/year_end') . '?' . http_build_query(['year' => $yearId, 'scope' => $scope]);
    }

    /**
     * What the employee may run: the company when they hold every branch, and each branch in their scope.
     *
     * @return array<string, string>
     */
    private function scopeOptions(): array
    {
        $options = [];

        if ($this->branchGuard()->isUnrestricted($this->employeeId())) {
            $options['company'] = lang('Accounting_periods.year_end_scope_company');
        }

        foreach ((new BranchService(null, $this->branchGuard()))->all($this->employeeId()) as $branch) {
            $options[(string) $branch->branch_id] = $branch->code . ' - ' . $branch->name;
        }

        return $options;
    }

    /**
     * @param array<string, string> $options
     */
    private function scopeParameter(array $options): string
    {
        $value = $this->request->getGet('scope');
        $value = is_string($value) ? $value : '';

        if ($options === []) {
            return '';
        }

        if ($value === '') {
            return (string) array_key_first($options);
        }

        if (! array_key_exists($value, $options)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $value;
    }

    private function postedScope(): string
    {
        $value = $this->request->getPost('scope');

        if (! is_string($value) || ! ($value === 'company' || (ctype_digit($value) && (int) $value > 0))) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $value;
    }

    /**
     * @param list<object> $years
     */
    private function yearParameter(array $years): ?int
    {
        $value = (int) $this->request->getGet('year');

        foreach ($years as $year) {
            if ((int) $year->fiscal_year_id === $value) {
                return $value;
            }
        }

        return $years === [] ? null : (int) $years[0]->fiscal_year_id;
    }
}
