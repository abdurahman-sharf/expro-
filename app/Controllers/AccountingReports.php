<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountLedger;
use App\Libraries\Accounting\AccountLedgerReport;
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
    private const LAST_PAGE = 1_000_000;

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
        $type      = TrialBalanceType::fromRequest($this->query('type'));
        $scope     = $this->scope();
        $expand    = $this->flag('entities');
        $showEmpty = $this->flag('empty');
        $error     = null;
        $ignored   = false;

        try {
            $period  = $this->period();
            $ignored = $period->from !== null && ! $type->showsOpening();
            $counted = $type->showsOpening() ? $period : ReportPeriod::of(null, $period->to);
        } catch (AccountingException $refusal) {
            $error   = $this->messageFor($refusal);
            $counted = ReportPeriod::of(null, date('Y-m-d'));
        }

        $linkedFrom = $type->showsOpening() ? $this->query('from') : '';

        return view('accounting_reports/trial_balance', [
            'report'       => $this->trialBalance->build($scope, $counted, $expand, $showEmpty),
            'type'         => $type,
            'error'        => $error,
            'from_ignored' => $ignored,
            'filters'      => $this->filterValues($scope),
            'expand'       => $expand,
            'show_empty'   => $showEmpty,
            'branches'     => $this->branchChoices($scope),
            'scope_label'  => $this->scopeLabel($scope),
            'scope_kind'   => $scope->kind,
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (string $value): string => $this->formatDate($value),
            'ledger_url'   => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, $linkedFrom, $this->query('to')),
        ]);
    }

    public function getLedger(): string
    {
        $accountId = (int) $this->query('account');
        $scope     = $this->scope();
        $page      = min(self::LAST_PAGE, max(1, (int) $this->query('page')));
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

        $report = $this->statement($scope, $accountId, $period, $page);
        $pages  = $this->pagesOf($report);

        if ($page > $pages) {
            $page   = $pages;
            $report = $this->statement($scope, $accountId, $period, $page);
        }

        return view('accounting_reports/ledger', $this->ledgerData($report, $scope, $period, $page, $error));
    }

    private function statement(ReportScope $scope, int $accountId, ReportPeriod $period, int $page): AccountLedgerReport
    {
        try {
            return $this->ledger->build($scope, $accountId, $period, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        } catch (AccountingException $refusal) {
            if ($refusal->error === AccountingError::ReportAccountMissing) {
                throw PageNotFoundException::forPageNotFound();
            }

            throw $refusal;
        }
    }

    private function pagesOf(AccountLedgerReport $report): int
    {
        return max(1, intdiv($report->totalRows + self::PAGE_SIZE - 1, self::PAGE_SIZE));
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerData(?AccountLedgerReport $report, ReportScope $scope, ReportPeriod $period, int $page, ?string $error): array
    {
        $accountId = $report?->accountId;

        return [
            'report'       => $report,
            'error'        => $error,
            'accounts'     => $this->ledger->pickable($accountId),
            'account_id'   => $accountId,
            'filters'      => $this->filterValues($scope),
            'branches'     => $this->branchChoices($scope),
            'scope_label'  => $this->scopeLabel($scope),
            'scope_kind'   => $scope->kind,
            'page'         => $page,
            'pages'        => $report === null ? 1 : $this->pagesOf($report),
            'journals_url' => $this->can('accounting_journals'),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (string $value): string => $this->formatDate($value),
            'source'       => fn (string $type): string => $this->label('source', $type),
            'number'       => fn (?string $series, ?string $number): string => $this->displayNumber($series, $number),
            'page_url'     => fn (int $target): string => $this->ledgerUrl($accountId ?? 0, $scope, $period->from ?? '', $period->to, $target),
        ];
    }

    /**
     * What the request names, and nothing when it names it more than once or not as text, which
     * is how a hand-made address gives an array.
     */
    private function query(string $name): string
    {
        $value = $this->request->getGet($name);

        return is_string($value) ? trim($value) : '';
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
        $to = $this->query('to');

        return ReportPeriod::of($this->query('from'), $to === '' ? date('Y-m-d') : $to);
    }

    private function flag(string $name): bool
    {
        return $this->query($name) === '1';
    }

    /**
     * What the filter form shows: the days as they were asked for, so a day that is not one is
     * shown back to be corrected.
     *
     * @return array{from: string, to: string, branches: list<int>}
     */
    private function filterValues(ReportScope $scope): array
    {
        $to = $this->query('to');

        return ['from' => $this->query('from'), 'to' => $to === '' ? date('Y-m-d') : $to, 'branches' => $scope->ids];
    }

    /**
     * The branches the employee may choose from, none for someone who reads one only.
     *
     * @return list<object>
     */
    private function branchChoices(ReportScope $scope): array
    {
        return $scope->choosable() ? $scope->choices : [];
    }

    /**
     * Whose figures the report holds, said only to someone who reads more than one branch.
     */
    private function scopeLabel(ReportScope $scope): ?string
    {
        if (! $scope->choosable() || $scope->isEmpty()) {
            return null;
        }

        $codes = implode(lang('Accounting_reports.branch_separator'), $scope->codes);

        return match ($scope->kind) {
            ReportScopeKind::Company  => lang('Accounting_reports.scope_company'),
            ReportScopeKind::Partial  => lang('Accounting_reports.scope_partial', [$codes]),
            ReportScopeKind::Selected => lang('Accounting_reports.scope_selected', [$codes]),
        };
    }

    private function ledgerUrl(int $accountId, ReportScope $scope, string $from, string $to, int $page = 1): string
    {
        $query = ['account' => $accountId];

        if ($from !== '') {
            $query['from'] = $from;
        }

        if ($to !== '') {
            $query['to'] = $to;
        }

        if ($this->request->getGet('branches') !== null) {
            $query['branches'] = $scope->isEmpty() ? [0] : $scope->ids;
        }

        if ($page > 1) {
            $query['page'] = $page;
        }

        return base_url('accounting_reports/ledger') . '?' . http_build_query($query);
    }
}
