<?php
/**
 * @var AccountLedgerReport|null                                                          $report
 * @var AccountLedgerBlocksReport|null                                                    $blocks
 * @var string|null                                                                       $error
 * @var list<object>                                                                      $accounts
 * @var list<object>                                                                      $employees
 * @var array{employee: ?int, hideEmployee: bool, layout: string, display: LedgerDisplay} $options
 * @var string                                                                            $csvUrl
 * @var int|null                                                                          $accountId
 * @var array{from: string, to: string, branches: list<int>}                              $filters
 * @var list<object>                                                                      $branches
 * @var string|null                                                                       $scopeLabel
 * @var ReportScopeKind                                                                   $scopeKind
 * @var int                                                                               $page
 * @var int                                                                               $pages
 * @var bool                                                                              $journalsUrl
 * @var Closure                                                                           $amount
 * @var Closure                                                                           $date
 * @var Closure                                                                           $source
 * @var Closure                                                                           $number
 * @var Closure                                                                           $pageUrl
 */

use App\Libraries\Accounting\AccountLedgerBlocksReport;
use App\Libraries\Accounting\AccountLedgerReport;
use App\Libraries\Accounting\LedgerDisplay;
use App\Libraries\Accounting\ReportScopeKind;

$kind = static function (array $row) use ($source): string {
    $label = $source((string) $row['source_type']);

    return $row['reverses_journal_id'] === null ? $label : $label . ' (' . lang('Accounting.source_reversal') . ')';
};

$journal = static function (array $row) use ($journalsUrl, $number): string {
    $text = esc($number($row['series'], $row['number']));

    return $journalsUrl
        ? '<a href="' . esc(base_url('accounting_journals/view/' . (int) $row['journal_id']), 'attr') . '">' . $text . '</a>'
        : $text;
};

$side         = static fn (string $value, Closure $amount): string => bccomp($value, '0', 4) === 0 ? '' : $amount($value);
$showEmployee = ! $options['hideEmployee'];
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <?php if ($accountId !== null): ?>
        <a class="btn btn-outline-secondary btn-sm float-end" id="ledger_csv" href="<?= esc($csvUrl, 'attr') ?>">
            <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?>
        </a>
    <?php endif; ?>
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_reports'), 'attr') ?>">
        <span class="bi bi-table" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.trial_balance') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_balance_sheet" href="<?= esc(base_url('accounting_statements/balance_sheet'), 'attr') ?>">
        <span class="bi bi-file-earmark-bar-graph" aria-hidden="true">&nbsp;</span><?= lang('Accounting_statements.balance_sheet') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_profit_loss" href="<?= esc(base_url('accounting_statements/profit_loss'), 'attr') ?>">
        <span class="bi bi-graph-up-arrow" aria-hidden="true">&nbsp;</span><?= lang('Accounting_profit_loss.title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_cash_flow" href="<?= esc(base_url('accounting_statements/cash_flow'), 'attr') ?>">
        <span class="bi bi-cash-stack" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cash_flow.title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_income_statement" href="<?= esc(base_url('accounting_statements/income_statement'), 'attr') ?>">
        <span class="bi bi-graph-up" aria-hidden="true">&nbsp;</span><?= lang('Accounting_statements.income_statement') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="inventory_valuation_link" href="<?= esc(base_url('accounting_reports/inventory_valuation'), 'attr') ?>">
        <span class="bi bi-boxes" aria-hidden="true">&nbsp;</span><?= lang('Accounting_inventory_reports.title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="tax_report_link" href="<?= esc(base_url('accounting_reports/tax'), 'attr') ?>">
        <span class="bi bi-journal-check" aria-hidden="true">&nbsp;</span><?= lang('Accounting_tax_reports.title') ?>
    </a>
</div>

<form id="ledger_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_reports/ledger'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_account"><?= lang('Accounting_reports.account') ?></label>
            <select id="f_account" name="account" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_reports.ledger_pick') ?></option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?= (int) $account->account_id ?>"<?= $accountId === $account->account_id ? ' selected' : '' ?>><?= esc($account->code . ' - ' . $account->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_from"><?= lang('Accounting_reports.from') ?></label>
            <input type="date" id="f_from" name="from" class="form-control form-control-sm" value="<?= esc($filters['from'], 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_to"><?= lang('Accounting_reports.to') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($filters['to'], 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_employee"><?= lang('Accounting_reports.ledger_employee') ?></label>
            <select id="f_employee" name="employee" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_reports.ledger_employee_all') ?></option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?= (int) $employee->employee_id ?>"<?= $options['employee'] === $employee->employee_id ? ' selected' : '' ?>><?= esc($employee->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_layout"><?= lang('Accounting_reports.ledger_layout') ?></label>
            <select id="f_layout" name="layout" class="form-select form-select-sm">
                <option value="merged"<?= $options['layout'] === 'merged' ? ' selected' : '' ?>><?= lang('Accounting_reports.ledger_layout_merged') ?></option>
                <option value="accounts"<?= $options['layout'] === 'accounts' ? ' selected' : '' ?>><?= lang('Accounting_reports.ledger_layout_accounts') ?></option>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_display"><?= lang('Accounting_reports.ledger_display') ?></label>
            <select id="f_display" name="display" class="form-select form-select-sm">
                <?php foreach (LedgerDisplay::cases() as $choice): ?>
                    <option value="<?= esc($choice->value, 'attr') ?>"<?= $choice === $options['display'] ? ' selected' : '' ?>><?= lang('Accounting_reports.ledger_display_' . $choice->value) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <fieldset class="acc-filter acc-filter-status">
            <legend class="form-label"><?= lang('Accounting_reports.show') ?></legend>
            <label class="choice"><input type="checkbox" class="form-check-input" id="f_hide_employee" name="hide_employee" value="1"<?= $options['hideEmployee'] ? ' checked' : '' ?>> <?= lang('Accounting_reports.ledger_hide_employee') ?></label>
        </fieldset>
        <?php if ($branches !== []): ?>
            <fieldset class="acc-filter acc-filter-status" id="f_branches">
                <legend class="form-label"><?= lang('Accounting_reports.branches') ?></legend>
                <?php foreach ($branches as $branch): ?>
                    <label class="choice"><input type="checkbox" class="form-check-input" name="branches[]" value="<?= (int) $branch->branch_id ?>"<?= in_array((int) $branch->branch_id, $filters['branches'], true) ? ' checked' : '' ?>> <?= esc($branch->code . ' - ' . $branch->name) ?><?= $branch->active ? '' : ' (' . esc(lang('Accounting_reports.branch_inactive')) . ')' ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_reports/ledger'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="account_ledger">
    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($report === null && $blocks === null): ?>
        <?php if ($accountId === null): ?>
            <p class="text-body-secondary" id="ledger_none_selected"><?= lang('Accounting_reports.ledger_none_selected') ?></p>
        <?php endif; ?>
    <?php elseif ($blocks !== null): ?>
        <?= view('accounting_reports/ledger_blocks', ['blocks' => $blocks, 'options' => $options, 'scopeLabel' => $scopeLabel, 'scopeKind' => $scopeKind, 'amount' => $amount, 'date' => $date, 'source' => $source, 'number' => $number, 'journalsUrl' => $journalsUrl, 'showEmployee' => $showEmployee, 'branches' => $branches]) ?>
    <?php else: ?>
        <h1 class="h4"><?= lang('Accounting_reports.ledger') ?>: <bdi dir="ltr"><?= esc($report->code) ?></bdi> <?= esc($report->name) ?></h1>

        <p class="text-body-secondary mb-2" id="report_period">
            <?= $report->period->from === null
                ? lang('Accounting_reports.period_to', [$date($report->period->to)])
                : lang('Accounting_reports.period_between', [$date($report->period->from), $date($report->period->to)]) ?>
        </p>

        <?php if ($scopeLabel !== null): ?>
            <?php if ($scopeKind === ReportScopeKind::Partial): ?>
                <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scopeLabel) ?></p>
            <?php else: ?>
                <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scopeLabel) ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($report->scope->isEmpty()): ?>
            <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
        <?php endif; ?>

        <?php if ($report->employeeId !== null): ?>
            <p class="small text-body-secondary" id="ledger_employee_note" role="note"><?= lang('Accounting_reports.ledger_employee_note') ?></p>
        <?php endif; ?>

        <?php if ($report->accountsCounted > 1): ?>
            <p class="small text-body-secondary" id="ledger_accounts_counted"><?= lang('Accounting_reports.ledger_accounts_counted', [$report->accountsCounted]) ?></p>
        <?php endif; ?>

        <dl class="row mb-3" id="ledger_summary">
            <dt class="col-sm-3"><?= lang('Accounting_reports.ledger_opening') ?></dt>
            <dd class="col-sm-9"><?= $amount($report->opening) ?></dd>
            <dt class="col-sm-3"><?= lang('Accounting_reports.period_debit') ?></dt>
            <dd class="col-sm-9"><?= $amount($report->debit) ?></dd>
            <dt class="col-sm-3"><?= lang('Accounting_reports.period_credit') ?></dt>
            <dd class="col-sm-9"><?= $amount($report->credit) ?></dd>
            <dt class="col-sm-3"><?= lang('Accounting_reports.ledger_closing') ?></dt>
            <dd class="col-sm-9"><?= $amount($report->closing) ?></dd>
        </dl>

        <?php if ($report->rows === []): ?>
            <p class="text-body-secondary" id="ledger_empty"><?= lang($report->employeeId === null ? 'Accounting_reports.ledger_empty' : 'Accounting_reports.ledger_nothing_matches') ?></p>
        <?php else: ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm" id="ledger_table">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_reports.ledger_date') ?></th>
                            <th scope="col"><?= lang('Accounting_reports.ledger_journal') ?></th>
                            <th scope="col"><?= lang('Accounting_reports.ledger_source') ?></th>
                            <?php if ($report->accountsCounted > 1): ?>
                                <th scope="col"><?= lang('Accounting_reports.account') ?></th>
                            <?php endif; ?>
                            <?php if ($showEmployee): ?>
                                <th scope="col"><?= lang('Accounting_reports.ledger_employee') ?></th>
                            <?php endif; ?>
                            <th scope="col"><?= lang('Accounting_reports.ledger_description') ?></th>
                            <?php if ($branches !== []): ?>
                                <th scope="col"><?= lang('Accounting_reports.ledger_branch') ?></th>
                            <?php endif; ?>
                            <th scope="col" class="text-end"><?= lang('Accounting_reports.debit') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_reports.credit') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_reports.ledger_balance') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report->rows as $row): ?>
                            <tr>
                                <td><?= esc($date($row['date'])) ?></td>
                                <td><?= $journal($row) ?></td>
                                <td><?= esc($kind($row)) ?><?php if ($row['entry_type'] === 'year_end'): ?> <span class="badge text-bg-warning acc-year-end-tag"><?= lang('Accounting_reports.ledger_year_end') ?></span><?php endif; ?></td>
                                <?php if ($report->accountsCounted > 1): ?>
                                    <td><bdi dir="ltr"><?= esc($row['account_code']) ?></bdi> <?= esc($row['account_name']) ?></td>
                                <?php endif; ?>
                                <?php if ($showEmployee): ?>
                                    <td><?= esc($row['employee']) ?></td>
                                <?php endif; ?>
                                <td><?= esc($row['description']) ?></td>
                                <?php if ($branches !== []): ?>
                                    <td><?= esc($row['branch_code']) ?></td>
                                <?php endif; ?>
                                <td class="text-end"><?= $side($row['debit'], $amount) ?></td>
                                <td class="text-end"><?= $side($row['credit'], $amount) ?></td>
                                <td class="text-end"><?= $amount($row['balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <nav class="print_hide" aria-label="<?= esc(lang('Accounting_reports.paging'), 'attr') ?>" id="ledger_paging">
                    <?php if ($page > 1): ?>
                        <a class="btn btn-outline-secondary btn-sm" rel="prev" href="<?= esc($pageUrl($page - 1), 'attr') ?>"><?= lang('Accounting_reports.previous') ?></a>
                    <?php endif; ?>
                    <span class="mx-2 small text-body-secondary"><?= lang('Accounting_reports.page_of', [$page, $pages]) ?></span>
                    <?php if ($page < $pages): ?>
                        <a class="btn btn-outline-secondary btn-sm" rel="next" href="<?= esc($pageUrl($page + 1), 'attr') ?>"><?= lang('Accounting_reports.next') ?></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
