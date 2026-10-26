<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountLedger;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\Accounting\TrialBalanceType;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * The financial reports over the journals that are in the books: the trial balance and the
 * statement of an account. Each is for one branch, for several, or for the company, and what an
 * employee asks for is cut down to the branches they work in; someone limited to some branches
 * is told that the total is of those.
 */
class AccountingReports extends AccountingController
{
    private const PAGE_SIZE = 100;

    private TrialBalance $trialBalance;
    private AccountLedger $ledger;

    public function __construct()
    {
        parent::__construct('accounting_reports');

        $this->trialBalance = new TrialBalance();
        $this->ledger       = new AccountLedger();
    }

    public function getIndex(): string
    {
        $type      = TrialBalanceType::fromRequest($this->request->getGet('type'));
        $scope     = $this->scope();
        $expand    = $this->flag('entities');
        $showEmpty = $this->flag('empty');
        $error     = null;
        $report    = null;

        try {
            $report = $this->trialBalance->build($scope, $this->period(), $expand, $showEmpty);
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $report = $this->trialBalance->build($scope, ReportPeriod::of(null, date('Y-m-d')), $expand, $showEmpty);
        }

        return view('accounting_reports/trial_balance', [
            'report'      => $report,
            'type'        => $type,
            'error'       => $error,
            'filters'     => $this->filterValues($scope),
            'expand'      => $expand,
            'show_empty'  => $showEmpty,
            'branches'    => $this->branchChoices(),
            'scope_label' => $this->scopeLabel($scope),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
            'ledger_url'  => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope),
        ]);
    }

    public function getLedger(): string
    {
        $accountId = (int) $this->request->getGet('account');
        $scope     = $this->scope();
        $page      = max(1, (int) $this->request->getGet('page'));
        $error     = null;

        try {
            $period = $this->period();
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $period = ReportPeriod::of(null, date('Y-m-d'));
        }

        if ($accountId < 1) {
            return view('accounting_reports/ledger', $this->ledgerData(null, $scope, $period, $page, $error));
        }

        try {
            $report = $this->ledger->build($scope, $accountId, $period, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        } catch (AccountingException $refusal) {
            if ($refusal->error === AccountingError::ReportAccountMissing) {
                throw PageNotFoundException::forPageNotFound();
            }

            throw $refusal;
        }

        return view('accounting_reports/ledger', $this->ledgerData($report, $scope, $period, $page, $error));
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerData(?object $report, ReportScope $scope, ReportPeriod $period, int $page, ?string $error): array
    {
        $accountId = $report === null ? null : $report->accountId;

        return [
            'report'       => $report,
            'error'        => $error,
            'accounts'     => $this->ledger->pickable($accountId),
            'account_id'   => $accountId,
            'filters'      => ['from' => $period->from ?? '', 'to' => $period->to, 'branches' => $scope->ids],
            'branches'     => $this->branchChoices(),
            'scope_label'  => $this->scopeLabel($scope),
            'page'         => $page,
            'page_size'    => self::PAGE_SIZE,
            'pages'        => $report === null ? 1 : max(1, (int) ceil($report->totalRows / self::PAGE_SIZE)),
            'journals_url' => $this->can('accounting_journals'),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (string $value): string => $this->formatDate($value),
            'source'       => fn (string $type): string => $this->label('source', $type),
            'number'       => fn (?string $series, ?string $number): string => $this->displayNumber($series, $number),
            'page_url'     => fn (int $target): string => $this->ledgerUrl($accountId ?? 0, $scope, $period, $target),
        ];
    }

    private function scope(): ReportScope
    {
        $requested = $this->request->getGet('branches');

        return ReportScope::resolve(is_array($requested) ? array_map('intval', $requested) : null, $this->branchIds());
    }

    /**
     * @throws AccountingException
     */
    private function period(): ReportPeriod
    {
        $to = trim((string) $this->request->getGet('to'));

        return ReportPeriod::of((string) $this->request->getGet('from'), $to === '' ? date('Y-m-d') : $to);
    }

    private function flag(string $name): bool
    {
        return (string) $this->request->getGet($name) === '1';
    }

    /**
     * What the filter form shows: the days as they were asked for, so a day that is not one is
     * shown back to be corrected.
     *
     * @return array{from: string, to: string, branches: list<int>}
     */
    private function filterValues(ReportScope $scope): array
    {
        $to = trim((string) $this->request->getGet('to'));

        return ['from' => trim((string) $this->request->getGet('from')), 'to' => $to === '' ? date('Y-m-d') : $to, 'branches' => $scope->ids];
    }

    /**
     * The branches the employee may choose from, none for someone who works in one only.
     *
     * @return list<object>
     */
    private function branchChoices(): array
    {
        return $this->seesBranches() ? $this->branchGuard()->selectableBranches($this->employeeId()) : [];
    }

    /**
     * Whose figures the report holds, said only to someone who works in more than one branch.
     */
    private function scopeLabel(ReportScope $scope): ?string
    {
        if (! $this->seesBranches()) {
            return null;
        }

        return match ($scope->kind) {
            ReportScopeKind::Company  => lang('Accounting_reports.scope_company'),
            ReportScopeKind::Partial  => lang('Accounting_reports.scope_partial', [implode('، ', $scope->codes)]),
            ReportScopeKind::Selected => lang('Accounting_reports.scope_selected', [implode('، ', $scope->codes)]),
        };
    }

    private function ledgerUrl(int $accountId, ReportScope $scope, ?ReportPeriod $period = null, int $page = 1): string
    {
        $query = ['account' => $accountId];
        $from  = $period === null ? trim((string) $this->request->getGet('from')) : ($period->from ?? '');
        $to    = $period === null ? trim((string) $this->request->getGet('to')) : $period->to;

        if ($from !== '') {
            $query['from'] = $from;
        }

        if ($to !== '') {
            $query['to'] = $to;
        }

        if ($this->request->getGet('branches') !== null) {
            $query['branches'] = $scope->ids;
        }

        if ($page > 1) {
            $query['page'] = $page;
        }

        return base_url('accounting_reports/ledger') . '?' . http_build_query($query);
    }
}
