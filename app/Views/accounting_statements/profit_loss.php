<?php
/**
 * @var ProfitAndLossReport|CashProfitAndLossReport|null     $report
 * @var ProfitBasis                                          $basis
 * @var string|null                                          $error
 * @var ProfitPeriodKind                                     $kind
 * @var int|null                                             $level
 * @var list<int>                                            $levels
 * @var array{from: string, to: string, branches: list<int>} $filters
 * @var list<object>                                         $branches
 * @var string|null                                          $scopeLabel
 * @var string                                               $csvUrl
 * @var Closure                                              $amount
 * @var Closure                                              $date
 * @var Closure                                              $ledgerUrl
 */

use App\Libraries\Accounting\CashProfitAndLossReport;
use App\Libraries\Accounting\CashProfitLine;
use App\Libraries\Accounting\ProfitAndLossReport;
use App\Libraries\Accounting\ProfitBasis;
use App\Libraries\Accounting\ProfitPeriodKind;

$word  = static fn (CashProfitLine $line): string => $line->kind === CashProfitLine::ACCOUNT ? $line->code . ' ' . $line->name : lang('Accounting_profit_loss.line_' . $line->kind);
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <?php if ($report !== null): ?>
        <a class="btn btn-outline-secondary btn-sm float-end" id="profit_loss_csv" href="<?= esc($csvUrl, 'attr') ?>">
            <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?>
        </a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_cash_flow" href="<?= esc(base_url('accounting_statements/cash_flow'), 'attr') ?>">
        <span class="bi bi-cash-stack" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cash_flow.title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_income_statement" href="<?= esc(base_url('accounting_statements/income_statement'), 'attr') ?>">
        <span class="bi bi-graph-up" aria-hidden="true">&nbsp;</span><?= lang('Accounting_statements.income_statement') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_reports'), 'attr') ?>">
        <span class="bi bi-table" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.trial_balance') ?>
    </a>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_statements/profit_loss'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_basis"><?= lang('Accounting_profit_loss.basis') ?></label>
            <select id="f_basis" name="basis" class="form-select form-select-sm">
                <?php foreach (ProfitBasis::cases() as $option): ?>
                    <option value="<?= esc($option->value, 'attr') ?>"<?= $option === $basis ? ' selected' : '' ?>><?= lang('Accounting_profit_loss.basis_' . $option->value) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_period"><?= lang('Accounting_profit_loss.period') ?></label>
            <select id="f_period" name="period" class="form-select form-select-sm">
                <?php foreach (ProfitPeriodKind::cases() as $option): ?>
                    <option value="<?= esc($option->value, 'attr') ?>"<?= $option === $kind ? ' selected' : '' ?>><?= lang('Accounting_profit_loss.period_' . $option->value) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_level"><?= lang('Accounting_statements.level') ?></label>
            <select id="f_level" name="level" class="form-select form-select-sm">
                <?php foreach ($levels as $option): ?>
                    <option value="<?= (int) $option ?>"<?= $option === $level ? ' selected' : '' ?>><?= lang('Accounting_statements.level_n', [$option]) ?></option>
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
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_statements/profit_loss'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="profit_loss">
    <h1 class="h4"><?= lang('Accounting_profit_loss.title') ?></h1>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($report !== null): ?>
        <p class="mb-1" id="report_basis" data-basis="<?= esc($report->basis->value, 'attr') ?>"><strong><?= lang('Accounting_profit_loss.basis') ?>:</strong> <?= lang('Accounting_profit_loss.basis_' . $report->basis->value) ?></p>
        <p class="small text-body-secondary mb-2" id="report_basis_note"><?= lang('Accounting_profit_loss.basis_note_' . $report->basis->value) ?></p>

        <p class="text-body-secondary mb-2" id="report_period">
            <?= $report->from === null
                ? lang('Accounting_reports.period_to', [$date($report->to)])
                : lang('Accounting_reports.period_between', [$date($report->from), $date($report->to)]) ?>
        </p>

        <?= view('accounting_statements/notes', ['scope' => $report->scope, 'error' => null, 'scopeLabel' => $scopeLabel]) ?>

        <?php if ($report instanceof ProfitAndLossReport): ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm" id="profit_summary">
                    <caption class="visually-hidden"><?= lang('Accounting_profit_loss.summary') ?></caption>
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_profit_loss.summary') ?></th>
                            <?php foreach ($report->periods as $period): ?>
                                <th scope="col" class="text-end" data-period="<?= esc($period->label, 'attr') ?>"><?= esc($period->label) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="text-end"><?= lang('Accounting_profit_loss.total') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ([['profit', $report->profit, $report->totalProfit], ['revenue', $report->revenue, $report->totalRevenue], ['expenses', $report->expenses, $report->totalExpenses]] as [$key, $cells, $total]): ?>
                            <tr data-summary="<?= esc($key, 'attr') ?>"<?= $key === 'profit' ? ' class="fw-bold"' : '' ?>>
                                <th scope="row"><?= lang('Accounting_profit_loss.total_' . ($key === 'profit' ? 'profit' : $key)) ?></th>
                                <?php foreach ($cells as $cell): ?>
                                    <td class="text-end"><?= $amount($cell) ?></td>
                                <?php endforeach; ?>
                                <td class="text-end" data-cell="total"><?= $amount($total) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm" id="profit_matrix">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_profit_loss.account') ?></th>
                            <?php foreach ($report->periods as $period): ?>
                                <th scope="col" class="text-end"><?= esc($period->label) ?></th>
                            <?php endforeach; ?>
                            <th scope="col" class="text-end"><?= lang('Accounting_profit_loss.total') ?></th>
                        </tr>
                    </thead>
                    <?php foreach ([['revenue', $report->revenueLines, $report->revenue, $report->totalRevenue], ['expenses', $report->expenseLines, $report->expenses, $report->totalExpenses]] as [$section, $lines, $totals, $total]): ?>
                        <tbody id="section_<?= esc($section, 'attr') ?>">
                            <tr class="table-light">
                                <th scope="rowgroup" colspan="<?= count($report->periods) + 2 ?>"><?= lang('Accounting_profit_loss.' . $section) ?></th>
                            </tr>
                            <?php if ($lines === []): ?>
                                <tr><td colspan="<?= count($report->periods) + 2 ?>" class="text-body-secondary"><?= lang('Accounting_statements.empty') ?></td></tr>
                            <?php endif; ?>
                            <?php foreach ($lines as $line): ?>
                                <tr<?= $line->isGroup ? ' class="fw-bold"' : '' ?> data-account="<?= esc($line->code, 'attr') ?>">
                                    <td><span class="acc-depth acc-depth-<?= min(6, max(0, $line->level - 1)) ?>"><bdi dir="ltr"><?= esc($line->code) ?></bdi> <a href="<?= esc($ledgerUrl($line->accountId), 'attr') ?>"><?= esc($line->name) ?></a></span></td>
                                    <?php foreach ($line->amounts as $cell): ?>
                                        <td class="text-end"><?= $amount($cell) ?></td>
                                    <?php endforeach; ?>
                                    <td class="text-end" data-cell="total"><?= $amount($line->total) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="fw-bold" data-total="<?= esc($section, 'attr') ?>">
                                <th scope="row"><?= lang('Accounting_profit_loss.total_' . $section) ?></th>
                                <?php foreach ($totals as $cell): ?>
                                    <td class="text-end"><?= $amount($cell) ?></td>
                                <?php endforeach; ?>
                                <td class="text-end" data-cell="total"><?= $amount($total) ?></td>
                            </tr>
                        </tbody>
                    <?php endforeach; ?>
                </table>
            </div>

            <p class="small text-body-secondary" id="report_nominal_note"><?= lang('Accounting_profit_loss.nominal_note') ?></p>
        <?php else: ?>
            <div class="table-responsive mb-3">
                <table class="table table-sm" id="cash_profit_table">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_profit_loss.account') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_profit_loss.total') ?></th>
                        </tr>
                    </thead>
                    <?php foreach ([['revenue', $report->revenueLines, $report->totalRevenue], ['expenses', $report->expenseLines, $report->totalExpenses]] as [$section, $lines, $total]): ?>
                        <tbody id="section_<?= esc($section, 'attr') ?>">
                            <tr class="table-light"><th scope="rowgroup" colspan="2"><?= lang('Accounting_profit_loss.' . $section) ?></th></tr>
                            <?php foreach ($lines as $line): ?>
                                <tr data-line="<?= esc($line->code ?? $line->kind, 'attr') ?>">
                                    <td><?= esc($word($line)) ?></td>
                                    <td class="text-end" data-cell="amount"><?= $amount($line->amount) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="fw-bold" data-total="<?= esc($section, 'attr') ?>">
                                <th scope="row"><?= lang('Accounting_profit_loss.total_' . $section) ?></th>
                                <td class="text-end" data-cell="amount"><?= $amount($total) ?></td>
                            </tr>
                        </tbody>
                    <?php endforeach; ?>
                    <tfoot>
                        <tr class="fw-bold table-light" id="cash_profit">
                            <th scope="row"><?= lang('Accounting_profit_loss.total_profit') ?></th>
                            <td class="text-end" data-cell="amount"><?= $amount($report->profit) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-sm" id="cash_other_table">
                    <thead>
                        <tr><th scope="col" colspan="2"><?= lang('Accounting_profit_loss.other_movements') ?></th></tr>
                    </thead>
                    <tbody>
                        <?php if ($report->otherLines === []): ?>
                            <tr><td colspan="2" class="text-body-secondary"><?= lang('Accounting_profit_loss.other_none') ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($report->otherLines as $line): ?>
                            <tr data-line="<?= esc($line->code ?? $line->kind, 'attr') ?>">
                                <td><?= esc($word($line)) ?></td>
                                <td class="text-end" data-cell="amount"><?= $amount($line->amount) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold" data-total="other"><th scope="row"><?= lang('Accounting_profit_loss.other_total') ?></th><td class="text-end" data-cell="amount"><?= $amount($report->otherTotal) ?></td></tr>
                        <tr class="fw-bold" data-total="cash_change"><th scope="row"><?= lang('Accounting_profit_loss.cash_change') ?></th><td class="text-end" data-cell="amount"><?= $amount($report->cashChange) ?></td></tr>
                    </tfoot>
                </table>
            </div>

            <?php if ($report->ties()): ?>
                <p class="text-success" id="report_ties"><span class="bi bi-check-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_profit_loss.ties') ?></p>
            <?php else: ?>
                <p class="alert alert-danger" id="report_differs" role="alert"><?= lang('Accounting_profit_loss.differs') ?></p>
            <?php endif; ?>

            <p class="small text-body-secondary" id="report_cash_note"><?= lang('Accounting_profit_loss.cash_assumptions') ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
