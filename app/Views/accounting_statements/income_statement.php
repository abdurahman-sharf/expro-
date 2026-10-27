<?php
/**
 * @var IncomeStatementReport                                                                                       $report
 * @var string|null                                                                                                 $error
 * @var array{from: string, to: string, level: int, display: StatementDisplay, entities: bool, branches: list<int>} $filters
 * @var list<int>                                                                                                   $levels
 * @var list<object>                                                                                                $branches
 * @var string|null                                                                                                 $scopeLabel
 * @var Closure                                                                                                     $amount
 * @var Closure                                                                                                     $date
 * @var Closure                                                                                                     $ledgerUrl
 * @var string                                                                                                      $otherUrl
 */

use App\Libraries\Accounting\IncomeStatementReport;
use App\Libraries\Accounting\StatementDisplay;

?>

<?= view('partial/header') ?>

<?= view('accounting_statements/toolbar', ['otherUrl' => $otherUrl, 'otherLabel' => lang('Accounting_statements.balance_sheet')]) ?>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_statements/income_statement'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_from"><?= lang('Accounting_reports.from') ?></label>
            <input type="date" id="f_from" name="from" class="form-control form-control-sm" value="<?= esc($filters['from'], 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_to"><?= lang('Accounting_reports.to') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($filters['to'], 'attr') ?>">
        </div>
        <?= view('accounting_statements/filters_common', ['filters' => $filters, 'levels' => $levels, 'branches' => $branches, 'offerEntities' => false]) ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_statements/income_statement'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="income_statement">
    <h1 class="h4"><?= lang('Accounting_statements.income_statement') ?></h1>

    <p class="text-body-secondary mb-2" id="report_period">
        <?= $report->from === null
            ? lang('Accounting_statements.period_all_years', [$date($report->to)])
            : lang('Accounting_reports.period_between', [$date($report->from), $date($report->to)]) ?>
    </p>

    <?= view('accounting_statements/notes', ['scope' => $report->scope, 'error' => $error, 'scopeLabel' => $scopeLabel]) ?>

    <div class="table-responsive mb-3">
        <table class="table table-sm" id="income_statement_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_reports.code') ?></th>
                    <th scope="col"><?= lang('Accounting_reports.account') ?></th>
                    <th scope="col" class="text-end"><?= lang('Accounting_statements.amount') ?></th>
                </tr>
            </thead>
            <?= view('accounting_statements/section', ['section' => $report->revenue, 'heading' => lang('Accounting_statements.revenue'), 'totalLabel' => lang('Accounting_statements.total_revenue'), 'totalId' => 'total_revenue', 'total' => $report->revenue->total, 'extra' => [], 'amount' => $amount, 'ledgerUrl' => $ledgerUrl]) ?>
            <?= view('accounting_statements/section', ['section' => $report->expenses, 'heading' => lang('Accounting_statements.expenses'), 'totalLabel' => lang('Accounting_statements.total_expenses'), 'totalId' => 'total_expenses', 'total' => $report->expenses->total, 'extra' => [], 'amount' => $amount, 'ledgerUrl' => $ledgerUrl]) ?>
            <tfoot>
                <tr class="fw-bold table-light" id="net_income">
                    <th scope="row" colspan="2"><?= lang('Accounting_statements.net_income') ?></th>
                    <td class="text-end"><?= $amount($report->netIncome) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="small text-body-secondary" id="report_nominal_note"><?= lang('Accounting_statements.nominal_note_income_statement') ?></p>
</div>

<?= view('partial/footer') ?>
