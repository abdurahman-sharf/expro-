<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\ClosedPeriodService;
use App\Libraries\Organization\BranchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingClosings extends AccountingController
{
    private ClosedPeriodService $closings;

    public function __construct()
    {
        parent::__construct('accounting_periods');

        $this->closings = new ClosedPeriodService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $summary = $this->closings->summary($this->employeeId());

        return view('accounting_periods/closings', [
            'table_headers'  => transform_headers($this->headers(), false, false),
            'company_cutoff' => $summary['company'] === null ? null : $this->formatDate($summary['company']),
            'has_company'    => $summary['company'] !== null,
            'branches'       => array_map(fn (object $branch): array => [
                'branch_id' => (int) $branch->branch_id,
                'code'      => $branch->code,
                'name'      => $branch->name,
                'cutoff'    => $branch->cutoff === null ? null : $this->formatDate($branch->cutoff),
                'has_close' => $branch->cutoff !== null,
            ], $summary['branches']),
            'show_branches'   => ! $this->branchGuard()->isUnrestricted($this->employeeId()) || count($summary['branches']) > 1,
            'can_close'       => $this->can(AccountingGuard::PERIODS_CLOSE),
            'can_reopen'      => $this->can(AccountingGuard::PERIODS_REOPEN),
            'is_unrestricted' => $this->branchGuard()->isUnrestricted($this->employeeId()),
            'notice'          => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));

        $result = $this->closings->search([
            'scope'     => (string) $this->request->getGet('scope'),
            'status'    => (string) $this->request->getGet('status'),
            'date_from' => (string) $this->request->getGet('date_from'),
            'date_to'   => (string) $this->request->getGet('date_to'),
        ], $this->employeeId(), $limit, $offset);

        return $this->response->setJSON([
            'total' => $result['total'],
            'rows'  => array_map(fn (object $row): array => $this->row($row), $result['rows']),
        ]);
    }

    public function getCloseForm(): string
    {
        if (! $this->can(AccountingGuard::PERIODS_CLOSE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $scope   = $this->scopeParameter();
        $options = $this->scopeOptions();

        if (! array_key_exists($scope ?? 'company', $options)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $branchId = $scope === null ? null : (int) $scope;
        $previous = $this->closings->cutoff($branchId);

        return view('accounting_periods/close_form', [
            'scopes'    => $options,
            'scope'     => $scope ?? 'company',
            'previous'  => $previous === null ? '' : $this->formatDate($previous),
            'suggested' => $this->suggestedDate($previous),
            'max_date'  => date('Y-m-d'),
        ]);
    }

    public function getPreview(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $scope = $this->scopeParameter();
            $date  = (string) $this->request->getGet('date');

            $preview = $this->closings->preview($scope === null ? null : (int) $scope, $date, $this->employeeId());

            return [
                'success'     => true,
                'previous'    => $preview['previous'] === null ? '' : $this->formatDate($preview['previous']),
                'journals'    => $preview['journals'],
                'total_debit' => $this->formatAmount($preview['total_debit']),
                'draft_count' => $preview['draft_count'],
                'drafts'      => array_map(fn (object $draft): array => [
                    'id'          => (int) $draft->journal_id,
                    'date'        => $this->formatDate($draft->journal_date),
                    'description' => (string) $draft->description,
                    'branch'      => (string) $draft->branch_code,
                    'url'         => base_url('accounting_journals/edit/' . (int) $draft->journal_id),
                ], $preview['drafts']),
            ];
        });
    }

    public function postClose(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $scope = $this->scopeParameter(true);

            $this->closings->close($scope === null ? null : (int) $scope, (string) $this->request->getPost('date'), (string) $this->request->getPost('note'), $this->employeeId());

            $message = lang('Accounting_periods.closed');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => 1];
        });
    }

    public function getReopenForm(): string
    {
        if (! $this->can(AccountingGuard::PERIODS_REOPEN)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $scope = $this->scopeParameter();

        if (! array_key_exists($scope ?? 'company', $this->scopeOptions())) {
            throw PageNotFoundException::forPageNotFound();
        }

        $cutoff = $this->closings->cutoff($scope === null ? null : (int) $scope);

        return view('accounting_periods/reopen_form', [
            'scope'      => $scope ?? 'company',
            'cutoff'     => $cutoff === null ? '' : $this->formatDate($cutoff),
            'cutoff_raw' => $cutoff ?? '',
        ]);
    }

    public function postReopen(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $scope = $this->scopeParameter(true);

            $expected = (string) $this->request->getPost('cutoff');

            $this->closings->reopen($scope === null ? null : (int) $scope, (string) $this->request->getPost('reason'), $this->employeeId(), $expected === '' ? null : $expected);

            $message = lang('Accounting_periods.reopened');
            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => 1];
        });
    }

    /**
     * The scope a request names: null for the company, else a branch id as a string.
     */
    private function scopeParameter(bool $fromPost = false): ?string
    {
        $value = (string) ($fromPost ? $this->request->getPost('scope') : $this->request->getGet('scope'));

        if ($value === '' || $value === 'company') {
            return null;
        }

        if (ctype_digit($value) && (int) $value > 0) {
            return $value;
        }

        throw PageNotFoundException::forPageNotFound();
    }

    /**
     * What the employee may close: the company when they hold every branch, and each branch
     * in their scope.
     *
     * @return array<string, string>
     */
    private function scopeOptions(): array
    {
        $options = [];

        if ($this->branchGuard()->isUnrestricted($this->employeeId())) {
            $options['company'] = lang('Accounting_periods.scope_company');
        }

        foreach ((new BranchService(null, $this->branchGuard()))->all($this->employeeId()) as $branch) {
            $options[(string) $branch->branch_id] = $branch->code . ' - ' . $branch->name;
        }

        return $options;
    }

    /**
     * The last day of last month, unless that is not after the previous closing.
     */
    private function suggestedDate(?string $previous): string
    {
        $date = date('Y-m-t', strtotime('first day of last month'));

        return $previous !== null && $date <= $previous ? '' : $date;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['scope' => lang('Accounting_periods.scope'), 'sortable' => false],
            ['date_from' => lang('Accounting_periods.closed_from'), 'sortable' => false, 'escape' => false],
            ['date_to'   => lang('Accounting_periods.closed_to'), 'sortable' => false, 'escape' => false],
            ['status'    => lang('Accounting.status'), 'sortable' => false, 'escape' => false],
            ['note'      => lang('Accounting_periods.note'), 'sortable' => false],
            ['created'   => lang('Accounting_periods.created'), 'sortable' => false, 'escape' => false],
            ['reopened'  => lang('Accounting_periods.reopened_info'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $row): array
    {
        $active = (int) $row->active === 1;

        return [
            'closed_period_id' => (int) $row->closed_period_id,
            'scope'            => $row->branch_id === null ? lang('Accounting_periods.scope_company') : $row->branch_code . ' - ' . $row->branch_name,
            'date_from'        => $this->amountCell($this->formatDate($row->date_from)),
            'date_to'          => $this->amountCell($this->formatDate($row->date_to)),
            'status'           => '<span class="badge ' . ($active ? 'text-bg-danger' : 'text-bg-secondary') . '">' . esc(lang('Accounting_periods.status_' . ($active ? 'closed' : 'reopened'))) . '</span>',
            'note'             => (string) $row->note,
            'created'          => esc((string) $row->created_by_name) . ' <bdi dir="ltr" class="text-body-secondary">' . esc($this->formatDateTime($row->created_at)) . '</bdi>',
            'reopened'         => $active ? '' : esc((string) $row->reopened_by_name) . ' <bdi dir="ltr" class="text-body-secondary">' . esc($this->formatDateTime($row->reopened_at)) . '</bdi> ' . esc((string) $row->reopen_reason),
        ];
    }
}
