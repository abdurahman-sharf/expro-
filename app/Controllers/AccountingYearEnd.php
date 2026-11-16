<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\YearEndBranchPlan;
use App\Libraries\Accounting\YearEndBranchStatus;
use App\Libraries\Accounting\YearEndClosing;
use App\Libraries\Accounting\YearEndPreview;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingYearEnd extends AccountingController
{
    use ExportsCsv;

    private YearEndClosing $closing;

    public function __construct()
    {
        parent::__construct('accounting_periods');
        $this->requireGrant(AccountingGuard::YEAR_END_CLOSE);

        $this->closing = new YearEndClosing(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $years   = $this->closing->years($this->employeeId());
        $options = $this->scopeOptions();
        $scope   = $this->scopeParameter($options);
        $yearId  = $this->yearParameter($years);
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
            'preview'     => $preview,
            'error'       => $error,
            'blockers'    => $preview === null ? [] : array_map(fn (AccountingException $blocker): string => $this->messageFor($blocker), $preview->blockers),
            'closings'    => $this->closing->closings($this->employeeId()),
            'notice'      => session()->getFlashdata('accounting_notice'),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
            'status_text' => static fn (YearEndBranchPlan $plan): string => lang('Accounting_periods.year_end_status_' . $plan->status->value),
            'csvUrl'      => $yearId === null || $scope === '' ? null : base_url('accounting_periods/year_end/csv') . '?' . http_build_query(['year' => $yearId, 'scope' => $scope]),
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $options = $this->scopeOptions();
        $scope   = $this->scopeParameter($options);
        $yearId  = $this->yearParameter($this->closing->years($this->employeeId()));

        if ($yearId === null || $options === []) {
            throw PageNotFoundException::forPageNotFound();
        }

        try {
            $preview = $this->closing->preview($scope === 'company' ? null : (int) $scope, $yearId, $this->employeeId());
        } catch (AccountingException|BranchException $refusal) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="year_end_preview_' . $yearId . '_' . $scope . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->previewCsv($preview));
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

            $this->closing->approve($scope === 'company' ? null : (int) $scope, $yearId, $this->postedText('fingerprint'), $this->employeeId());

            $message = lang('Accounting_periods.year_end_approved');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $yearId, 'redirect' => $this->url($yearId, $scope)];
        });
    }

    public function getReverseForm(int $closingId): string
    {
        $closing = $this->visibleClosing($closingId);

        if ($closing === null || $closing->status !== 'active') {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/year_end_reverse_form', ['closing' => $closing]);
    }

    public function postReverse(int $closingId): ResponseInterface
    {
        return $this->attempt(function () use ($closingId): array {
            $this->closing->reverse($closingId, $this->postedText('reason'), $this->employeeId());

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

    private function postedText(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? $value : '';
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

    private function previewCsv(YearEndPreview $preview): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [lang('Accounting_periods.year_end_title')]);
        $this->csvRow($handle, [lang('Accounting_periods.year_end_period', [$preview->year->name, $preview->year->start_date, $preview->year->end_date])]);

        if ($preview->retainedAccount !== null) {
            $this->csvRow($handle, [lang('Accounting_periods.year_end_retained'), $preview->retainedAccount->code . ' ' . $preview->retainedAccount->name]);
        }

        foreach ($preview->blockers as $blocker) {
            $this->csvNote($handle, $this->messageFor($blocker));
        }

        foreach ($preview->branches as $plan) {
            $this->csvRow($handle, []);
            $this->csvRow($handle, [$plan->code . ' ' . $plan->name . ' (' . lang('Accounting_periods.year_end_status_' . $plan->status->value) . ')']);

            if ($plan->status === YearEndBranchStatus::AlreadyClosed) {
                $this->csvNote($handle, lang('Accounting_periods.year_end_already_closed_note'));

                continue;
            }

            if ($plan->status === YearEndBranchStatus::NoActivity) {
                $this->csvNote($handle, lang('Accounting_periods.year_end_nothing'));

                continue;
            }

            $this->csvRow($handle, [lang('Accounting_periods.year_end_account'), lang('Accounting_journals.debit'), lang('Accounting_journals.credit')]);

            foreach ($plan->lines as $line) {
                $this->csvRow($handle, [$line->code . ' ' . $line->name, $line->closingDebit(), $line->closingCredit()]);
            }

            if ($preview->retainedAccount !== null && ($plan->isProfit() || $plan->isLoss())) {
                $this->csvRow($handle, [$preview->retainedAccount->code . ' ' . $preview->retainedAccount->name, $plan->isLoss() ? bcsub('0', $plan->result, 4) : '', $plan->isProfit() ? $plan->result : '']);
            }

            $this->csvRow($handle, [lang('Accounting_periods.year_end_revenue'), $plan->revenue]);
            $this->csvRow($handle, [lang('Accounting_periods.year_end_expense'), $plan->expense]);
            $this->csvRow($handle, [$plan->isLoss() ? lang('Accounting_periods.year_end_loss') : lang('Accounting_periods.year_end_profit'), $plan->isLoss() ? bcsub('0', $plan->result, 4) : $plan->result]);
        }

        return $this->csvText($handle);
    }
}
