<?php
/**
 * @var BalanceSheetReport                                                                            $report
 * @var string|null                                                                                   $error
 * @var array{to: string, level: int, display: StatementDisplay, entities: bool, branches: list<int>} $filters
 * @var bool                                                                                          $fromIgnored
 * @var list<int>                                                                                     $levels
 * @var list<object>                                                                                  $branches
 * @var string|null                                                                                   $scopeLabel
 * @var Closure                                                                                       $amount
 * @var Closure                                                                                       $date
 * @var Closure                                                                                       $ledgerUrl
 * @var string                                                                                        $otherUrl
 */

use App\Libraries\Accounting\BalanceSheetReport;
use App\Libraries\Accounting\StatementDisplay;

$equityRows = [['id' => 'result_unclosed', 'label' => lang('Accounting_statements.result_unclosed'), 'amount' => $report->unclosedResult]];
?>

<?= view('partial/header') ?>

<?= view('accounting_statements/toolbar', ['otherUrl' => $otherUrl, 'otherLabel' => lang('Accounting_statements.income_statement'), 'csvUrl' => $csvUrl]) ?>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_statements/balance_sheet'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_to"><?= lang('Accounting_statements.as_of') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($filters['to'], 'attr') ?>">
        </div>
        <?= view('accounting_statements/filters_common', ['filters' => $filters, 'levels' => $levels, 'branches' => $branches, 'offerEntities' => true]) ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_statements/balance_sheet'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_statements.entities_hint') ?></p>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="balance_sheet">
    <h1 class="h4"><?= lang('Accounting_statements.balance_sheet_as_of', [$date($report->asOf)]) ?></h1>

    <?= view('accounting_statements/notes', ['scope' => $report->scope, 'error' => $error, 'scopeLabel' => $scopeLabel]) ?>

    <?php if ($fromIgnored): ?>
        <p class="small text-body-secondary" id="report_from_ignored" role="note"><?= lang('Accounting_statements.first_day_ignored') ?></p>
    <?php endif; ?>

    <?php if ($report->interBranchDifference !== null): ?>
        <?php if (bccomp($report->interBranchDifference, '0', 4) === 0): ?>
            <p class="small text-body-secondary" id="report_inter_branch_ok"><?= lang('Accounting_reports.inter_branch_balanced') ?></p>
        <?php else: ?>
            <p class="alert alert-warning" id="report_inter_branch_difference" role="alert"><?= lang('Accounting_reports.inter_branch_difference', [$amount($report->interBranchDifference)]) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="table-responsive mb-3">
        <table class="table table-sm" id="balance_sheet_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_reports.code') ?></th>
                    <th scope="col"><?= lang('Accounting_reports.account') ?></th>
                    <th scope="col" class="text-end"><?= lang('Accounting_statements.amount') ?></th>
                </tr>
            </thead>
            <?= view('accounting_statements/section', ['section' => $report->assets, 'heading' => lang('Accounting_statements.assets'), 'totalLabel' => lang('Accounting_statements.total_assets'), 'totalId' => 'total_assets', 'total' => $report->assets->total, 'extra' => [], 'amount' => $amount, 'ledgerUrl' => $ledgerUrl]) ?>
            <?= view('accounting_statements/section', ['section' => $report->liabilities, 'heading' => lang('Accounting_statements.liabilities'), 'totalLabel' => lang('Accounting_statements.total_liabilities'), 'totalId' => 'total_liabilities', 'total' => $report->liabilities->total, 'extra' => [], 'amount' => $amount, 'ledgerUrl' => $ledgerUrl]) ?>
            <?= view('accounting_statements/section', ['section' => $report->equity, 'heading' => lang('Accounting_statements.capital_and_equity'), 'totalLabel' => lang('Accounting_statements.total_equity'), 'totalId' => 'total_equity', 'total' => $report->equityTotal(), 'extra' => $equityRows, 'amount' => $amount, 'ledgerUrl' => $ledgerUrl]) ?>
            <tfoot>
                <tr class="fw-bold table-light" id="total_liabilities_equity">
                    <th scope="row" colspan="2"><?= lang('Accounting_statements.total_liabilities_equity') ?></th>
                    <td class="text-end"><?= $amount($report->liabilitiesAndEquity()) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="small text-body-secondary" id="report_nominal_note"><?= lang('Accounting_statements.nominal_note_balance_sheet') ?></p>

    <?php if ($report->balances()): ?>
        <p class="text-success" id="report_balanced"><span class="bi bi-check-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_statements.balanced') ?></p>
    <?php else: ?>
        <p class="alert alert-danger" id="report_unbalanced" role="alert"><?= lang('Accounting_statements.unbalanced') ?></p>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
