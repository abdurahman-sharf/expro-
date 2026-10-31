<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountLedger;
use App\Libraries\Accounting\AccountLedgerBlocksReport;
use App\Libraries\Accounting\AccountLedgerReport;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\LedgerDisplay;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ScopedReportRequest;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\Accounting\TrialBalanceFilter;
use App\Libraries\Accounting\TrialBalanceReport;
use App\Libraries\Accounting\TrialBalanceRow;
use App\Libraries\Accounting\TrialBalanceType;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The financial reports over the journals that are in the books: the trial balance and the
 * statement of an account, each with its file. Each is for one branch, for several, or for the
 * company, and what an employee asks for is cut down to the branches they work in; someone limited
 * to some branches is told that the total is of those.
 */
class AccountingReports extends AccountingController
{
    use ExportsCsv;
    use ScopedReportRequest;

    private const PAGE_SIZE      = 100;
    private const LAST_PAGE      = 1_000_000;
    private const CSV_MAX_LINES  = 20_000;
    private const LAYOUT_MERGED  = 'merged';
    private const LAYOUT_ACCOUNT = 'accounts';

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
        $type   = TrialBalanceType::fromRequest($this->query('type'));
        $scope  = $this->requestedScope();
        $filter = $this->trialBalanceFilter();
        $expand = $this->flag('entities');
        $empty  = $this->flag('empty');
        $error  = null;

        try {
            $period = $this->trialBalancePeriod($type);
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $period = ReportPeriod::of(null, date('Y-m-d'));
        }

        $linkedFrom = $type->showsOpening() ? $this->query('from') : '';

        return view('accounting_reports/trial_balance', [
            'report'          => $this->trialBalance->build($scope, $period, $expand, $empty, $filter),
            'type'            => $type,
            'error'           => $error,
            'from_ignored'    => $error === null && $this->query('from') !== '' && ! $type->showsOpening(),
            'filters'         => $this->filterValues($scope),
            'filter'          => $filter,
            'levels'          => range(1, 5),
            'account_choices' => $this->trialBalance->choices(),
            'expand'          => $expand,
            'show_empty'      => $empty,
            'branches'        => $this->branchChoices($scope),
            'scope_label'     => $this->scopeLabel($scope),
            'scope_kind'      => $scope->kind,
            'csv_url'         => base_url('accounting_reports/csv') . $this->trialBalanceQuery($scope),
            'amount'          => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'            => fn (string $value): string => $this->formatDate($value),
            'ledger_url'      => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, $linkedFrom, $this->query('to')),
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $type  = TrialBalanceType::fromRequest($this->query('type'));
        $scope = $this->requestedScope();

        try {
            $report = $this->trialBalance->build($scope, $this->trialBalancePeriod($type), $this->flag('entities'), $this->flag('empty'), $this->trialBalanceFilter());
        } catch (AccountingException $refusal) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        return $this->download('trial_balance_' . $type->value . '_' . ($report->from === null ? '' : $report->from . '_') . $report->to . '.csv', $this->trialBalanceCsv($report, $type, $scope));
    }

    public function getLedger(): string
    {
        $scope = $this->requestedScope();
        $page  = min(self::LAST_PAGE, max(1, (int) $this->query('page')));
        $error = null;

        try {
            $period = $this->period();
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $period = ReportPeriod::of(null, date('Y-m-d'));
        }

        $options   = $this->ledgerOptions();
        $accountId = (int) $this->query('account');

        if ($accountId < 1) {
            return view('accounting_reports/ledger', $this->ledgerData(null, null, $scope, $period, $page, $error, $options));
        }

        if ($options['layout'] === self::LAYOUT_ACCOUNT) {
            try {
                $blocks = $this->blocks($scope, $accountId, $period, $options);
            } catch (AccountingException $refusal) {
                if ($refusal->error !== AccountingError::LedgerTooLarge) {
                    throw $refusal;
                }

                $blocks = null;
                $error  = $this->messageFor($refusal);
            }

            return view('accounting_reports/ledger', $this->ledgerData(null, $blocks, $scope, $period, $page, $error, $options, $accountId));
        }

        $report = $this->statement($scope, $accountId, $period, $page, $options['employee']);
        $pages  = $this->pagesOf($report);

        if ($page > $pages) {
            $page   = $pages;
            $report = $this->statement($scope, $accountId, $period, $page, $options['employee']);
        }

        return view('accounting_reports/ledger', $this->ledgerData($report, null, $scope, $period, $page, $error, $options));
    }

    public function getLedgerCsv(): ResponseInterface
    {
        $scope     = $this->requestedScope();
        $options   = $this->ledgerOptions();
        $accountId = (int) $this->query('account');

        try {
            $period = $this->period();

            if ($options['layout'] === self::LAYOUT_ACCOUNT) {
                $text = $this->blocksCsv($this->blocks($scope, $accountId, $period, $options), $options['hide_employee']);
                $name = 'account_ledger_blocks_';
            } else {
                $report = $this->ledger->build($scope, $accountId, $period, self::CSV_MAX_LINES, 0, $options['employee']);

                if ($report->totalRows > self::CSV_MAX_LINES) {
                    throw new AccountingException(AccountingError::LedgerTooLarge, 'The statement is too large for a file.', ['max' => self::CSV_MAX_LINES]);
                }

                $text = $this->ledgerCsv($report, $options['hide_employee']);
                $name = 'account_ledger_';
            }
        } catch (AccountingException $refusal) {
            if ($refusal->error === AccountingError::ReportAccountMissing) {
                throw PageNotFoundException::forPageNotFound();
            }

            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        return $this->download($name . $accountId . '_' . ($period->from === null ? '' : $period->from . '_') . $period->to . '.csv', $text);
    }

    private function download(string $name, string $text): ResponseInterface
    {
        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody("\xEF\xBB\xBF" . $text);
    }

    private function trialBalanceFilter(): TrialBalanceFilter
    {
        return TrialBalanceFilter::fromRequest($this->request->getGet('level'), $this->request->getGet('display'), $this->request->getGet('account'), $this->request->getGet('accounts'));
    }

    /**
     * @throws AccountingException
     */
    private function trialBalancePeriod(TrialBalanceType $type): ReportPeriod
    {
        $period = $this->period();

        return $type->showsOpening() ? $period : ReportPeriod::of(null, $period->to);
    }

    private function statement(ReportScope $scope, int $accountId, ReportPeriod $period, int $page, ?int $employeeId): AccountLedgerReport
    {
        try {
            return $this->ledger->build($scope, $accountId, $period, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE, $employeeId);
        } catch (AccountingException $refusal) {
            if ($refusal->error === AccountingError::ReportAccountMissing) {
                throw PageNotFoundException::forPageNotFound();
            }

            throw $refusal;
        }
    }

    /**
     * @param array{employee: ?int, hide_employee: bool, layout: string, display: LedgerDisplay} $options
     *
     * @throws AccountingException
     */
    private function blocks(ReportScope $scope, int $accountId, ReportPeriod $period, array $options): AccountLedgerBlocksReport
    {
        try {
            return $this->ledger->buildBlocks($scope, $accountId, $period, $options['display'], $options['employee']);
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
     * What the request asks of the statement: whose lines, whether the column of the employee is
     * drawn, one list or a block for each account, and which accounts have a block. An employee
     * that is empty or not a number is nobody in particular, which is everyone.
     *
     * @return array{employee: ?int, hide_employee: bool, layout: string, display: LedgerDisplay}
     */
    private function ledgerOptions(): array
    {
        $employee = $this->query('employee');

        return [
            'employee'      => ctype_digit($employee) && (int) $employee > 0 ? (int) $employee : null,
            'hide_employee' => $this->flag('hide_employee'),
            'layout'        => $this->query('layout') === self::LAYOUT_ACCOUNT ? self::LAYOUT_ACCOUNT : self::LAYOUT_MERGED,
            'display'       => LedgerDisplay::fromRequest($this->request->getGet('display')),
        ];
    }

    /**
     * @param array{employee: ?int, hide_employee: bool, layout: string, display: LedgerDisplay} $options
     *
     * @return array<string, mixed>
     */
    private function ledgerData(?AccountLedgerReport $report, ?AccountLedgerBlocksReport $blocks, ReportScope $scope, ReportPeriod $period, int $page, ?string $error, array $options, ?int $chosen = null): array
    {
        $accountId = $report?->accountId ?? $blocks?->accountId ?? $chosen;

        return [
            'report'       => $report,
            'blocks'       => $blocks,
            'error'        => $error,
            'accounts'     => $this->ledger->pickable($accountId),
            'account_id'   => $accountId,
            'employees'    => $this->ledger->employees($scope),
            'options'      => $options,
            'filters'      => $this->filterValues($scope),
            'branches'     => $this->branchChoices($scope),
            'scope_label'  => $this->scopeLabel($scope),
            'scope_kind'   => $scope->kind,
            'page'         => $page,
            'pages'        => $report === null ? 1 : $this->pagesOf($report),
            'journals_url' => $this->can('accounting_journals'),
            'csv_url'      => base_url('accounting_reports/ledger/csv') . $this->ledgerQuery($accountId ?? 0, $scope, $period, $options),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (string $value): string => $this->formatDate($value),
            'source'       => fn (string $type): string => $this->label('source', $type),
            'number'       => fn (?string $series, ?string $number): string => $this->displayNumber($series, $number),
            'page_url'     => fn (int $target): string => $this->ledgerUrl($accountId ?? 0, $scope, $period->from ?? '', $period->to, $target, $options),
        ];
    }

    /**
     * @throws AccountingException
     */
    private function period(): ReportPeriod
    {
        $to = $this->query('to');

        return ReportPeriod::of($this->query('from'), $to === '' ? date('Y-m-d') : $to);
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
     * @param array{employee: ?int, hide_employee: bool, layout: string, display: LedgerDisplay}|null $options
     */
    private function ledgerUrl(int $accountId, ReportScope $scope, string $from, string $to, int $page = 1, ?array $options = null): string
    {
        $query = ['account' => $accountId];

        if ($from !== '') {
            $query['from'] = $from;
        }

        if ($to !== '') {
            $query['to'] = $to;
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        if ($page > 1) {
            $query['page'] = $page;
        }

        return base_url('accounting_reports/ledger') . '?' . http_build_query($query + ($options === null ? [] : $this->optionsQuery($options)));
    }

    /**
     * @param array{employee: ?int, hide_employee: bool, layout: string, display: LedgerDisplay} $options
     *
     * @return array<string, int|string>
     */
    private function optionsQuery(array $options): array
    {
        $query = [];

        if ($options['employee'] !== null) {
            $query['employee'] = $options['employee'];
        }

        if ($options['hide_employee']) {
            $query['hide_employee'] = 1;
        }

        if ($options['layout'] === self::LAYOUT_ACCOUNT) {
            $query['layout']  = self::LAYOUT_ACCOUNT;
            $query['display'] = $options['display']->value;
        }

        return $query;
    }

    /**
     * @param array{employee: ?int, hide_employee: bool, layout: string, display: LedgerDisplay} $options
     */
    private function ledgerQuery(int $accountId, ReportScope $scope, ReportPeriod $period, array $options): string
    {
        $query = ['account' => $accountId, 'to' => $period->to];

        if ($period->from !== null) {
            $query['from'] = $period->from;
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return '?' . http_build_query($query + $this->optionsQuery($options));
    }

    private function trialBalanceQuery(ReportScope $scope): string
    {
        $query = [];

        foreach (['type', 'from', 'to', 'level', 'display', 'account'] as $name) {
            if ($this->query($name) !== '') {
                $query[$name] = $this->query($name);
            }
        }

        foreach (['entities', 'empty'] as $name) {
            if ($this->flag($name)) {
                $query[$name] = 1;
            }
        }

        $accounts = $this->trialBalanceFilter()->accountIds;

        if ($accounts !== []) {
            $query['accounts'] = $accounts;
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return $query === [] ? '' : '?' . http_build_query($query);
    }

    private function trialBalanceCsv(TrialBalanceReport $report, TrialBalanceType $type, ReportScope $scope): string
    {
        $handle = fopen('php://temp', 'r+b');

        $header = [lang('Accounting_reports.code'), lang('Accounting_reports.account')];

        if ($type->showsOpening()) {
            array_push($header, lang($type->isGross() ? 'Accounting_reports.before_debit' : 'Accounting_reports.opening_debit'), lang($type->isGross() ? 'Accounting_reports.before_credit' : 'Accounting_reports.opening_credit'));
        }

        if ($type->showsMovement()) {
            array_push($header, lang('Accounting_reports.period_debit'), lang('Accounting_reports.period_credit'));
        }

        array_push($header, lang($type->isGross() ? 'Accounting_reports.after_debit' : 'Accounting_reports.closing_debit'), lang($type->isGross() ? 'Accounting_reports.after_credit' : 'Accounting_reports.closing_credit'));

        $this->csvRow($handle, $header);

        foreach ($report->rows as $row) {
            $this->csvRow($handle, [$row->code, $row->name, ...$this->trialBalanceCells($row, $type)]);
        }

        $totals = $report->totals;
        $cells  = [];

        if ($type->showsOpening()) {
            array_push($cells, $type->isGross() ? $totals['gross_opening_debit'] : $totals['opening_debit'], $type->isGross() ? $totals['gross_opening_credit'] : $totals['opening_credit']);
        }

        if ($type->showsMovement()) {
            array_push($cells, $totals['debit'], $totals['credit']);
        }

        array_push($cells, $type->isGross() ? $totals['gross_closing_debit'] : $totals['closing_debit'], $type->isGross() ? $totals['gross_closing_credit'] : $totals['closing_credit']);

        $this->csvRow($handle, ['', lang('Accounting_reports.totals'), ...$cells]);

        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_reports.type'), lang('Accounting_reports.type_' . $type->value)]);

        if ($type === TrialBalanceType::ReviewTotals) {
            $this->csvRow($handle, [lang('Accounting_reports.review_note')]);
        }

        $this->csvRow($handle, [lang('Accounting_reports.csv_period'), $report->from === null ? lang('Accounting_reports.period_to', [$report->to]) : lang('Accounting_reports.period_between', [$report->from, $report->to])]);
        $this->csvRow($handle, [lang('Accounting_reports.csv_branches'), implode(' ', $report->scope->codes)]);

        $label = $this->scopeLabel($scope);

        if ($label !== null) {
            $this->csvRow($handle, [$label]);
        }

        if ($report->limitedToAccounts) {
            $this->csvRow($handle, [lang('Accounting_reports.filtered_run')]);
        } else {
            $this->csvRow($handle, [lang($report->balances() ? 'Accounting_reports.balanced' : 'Accounting_reports.unbalanced')]);
        }

        return $this->csvText($handle);
    }

    /**
     * @return list<string>
     */
    private function trialBalanceCells(TrialBalanceRow $row, TrialBalanceType $type): array
    {
        $cells = [];

        if ($type->showsOpening()) {
            array_push($cells, ...($type->isGross() ? [$row->openingDebit, $row->openingCredit] : TrialBalanceRow::sides($row->opening)));
        }

        if ($type->showsMovement()) {
            array_push($cells, $row->debit, $row->credit);
        }

        array_push($cells, ...($type->isGross() ? [$row->grossClosingDebit(), $row->grossClosingCredit()] : TrialBalanceRow::sides($row->closing())));

        return $cells;
    }

    private function ledgerCsv(AccountLedgerReport $report, bool $hideEmployee): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, $this->ledgerHeader($hideEmployee));
        $this->csvRow($handle, $this->ledgerSummary(lang('Accounting_reports.ledger_opening'), '', '', $report->opening, $hideEmployee));

        foreach ($report->rows as $row) {
            $this->csvRow($handle, $this->ledgerCells($row, $hideEmployee));
        }

        $this->csvRow($handle, $this->ledgerSummary(lang('Accounting_reports.totals'), $report->debit, $report->credit, $report->closing, $hideEmployee));

        return $this->csvText($handle);
    }

    private function blocksCsv(AccountLedgerBlocksReport $report, bool $hideEmployee): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, $this->ledgerHeader($hideEmployee));

        foreach ($report->blocks as $block) {
            $this->csvRow($handle, $this->ledgerSummary($block->name . ' ' . $block->code, '', '', '', $hideEmployee));
            $this->csvRow($handle, $this->ledgerSummary(lang('Accounting_reports.ledger_opening'), '', '', $block->opening, $hideEmployee));

            foreach ($block->rows as $row) {
                $this->csvRow($handle, $this->ledgerCells($row, $hideEmployee));
            }

            $this->csvRow($handle, $this->ledgerSummary(lang('Accounting_reports.totals'), $block->debit, $block->credit, $block->closing, $hideEmployee));
        }

        $this->csvRow($handle, $this->ledgerSummary(lang('Accounting_reports.ledger_grand_total'), $report->debit, $report->credit, $report->net(), $hideEmployee));

        return $this->csvText($handle);
    }

    /**
     * A line of the file that is not a journal line, with its words in the column of the
     * description so that every line has as many cells as the header.
     *
     * @return list<string>
     */
    private function ledgerSummary(string $label, string $debit, string $credit, string $balance, bool $hideEmployee): array
    {
        return [...array_fill(0, $hideEmployee ? 4 : 5, ''), $label, $debit, $credit, $balance];
    }

    /**
     * @return list<string>
     */
    private function ledgerHeader(bool $hideEmployee): array
    {
        return array_values(array_filter([
            lang('Accounting_reports.ledger_date'),
            lang('Accounting_reports.ledger_journal'),
            lang('Accounting_reports.ledger_source'),
            lang('Accounting_reports.account'),
            $hideEmployee ? null : lang('Accounting_reports.ledger_employee'),
            lang('Accounting_reports.ledger_description'),
            lang('Accounting_reports.debit'),
            lang('Accounting_reports.credit'),
            lang('Accounting_reports.ledger_balance'),
        ], static fn (?string $cell): bool => $cell !== null));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function ledgerCells(array $row, bool $hideEmployee): array
    {
        return array_values(array_filter([
            (string) $row['date'],
            $this->displayNumber($row['series'], $row['number']),
            $this->label('source', (string) $row['source_type']),
            $row['account_code'] . ' ' . $row['account_name'],
            $hideEmployee ? null : (string) $row['employee'],
            (string) $row['description'],
            (string) $row['debit'],
            (string) $row['credit'],
            (string) $row['balance'],
        ], static fn (?string $cell): bool => $cell !== null));
    }
}
