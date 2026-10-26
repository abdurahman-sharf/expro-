<?php
/**
 * @var TrialBalanceReport                                   $report
 * @var TrialBalanceType                                     $type
 * @var string|null                                          $error
 * @var bool                                                 $from_ignored
 * @var array{from: string, to: string, branches: list<int>} $filters
 * @var bool                                                 $expand
 * @var bool                                                 $show_empty
 * @var list<object>                                         $branches
 * @var string|null                                          $scope_label
 * @var ReportScopeKind                                      $scope_kind
 * @var Closure                                              $amount
 * @var Closure                                              $date
 * @var Closure                                              $ledger_url
 */

use App\Libraries\Accounting\ReportScopeKind;
use App\Libraries\Accounting\TrialBalanceReport;
use App\Libraries\Accounting\TrialBalanceRow;
use App\Libraries\Accounting\TrialBalanceType;

$side = static fn (string $value, Closure $amount): string => bccomp($value, '0', 4) === 0 ? '' : $amount($value);
$span = $type->showsOpening() ? 8 : ($type->showsMovement() ? 6 : 4);
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_reports/ledger'), 'attr') ?>">
        <span class="bi bi-journal-text" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.ledger') ?>
    </a>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_reports'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_type"><?= lang('Accounting_reports.type') ?></label>
            <select id="f_type" name="type" class="form-select form-select-sm">
                <?php foreach (TrialBalanceType::cases() as $option): ?>
                    <option value="<?= esc($option->value, 'attr') ?>"<?= $option === $type ? ' selected' : '' ?>><?= lang('Accounting_reports.type_' . $option->value) ?></option>
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
        <fieldset class="acc-filter acc-filter-status">
            <legend class="form-label"><?= lang('Accounting_reports.show') ?></legend>
            <label class="choice"><input type="checkbox" class="form-check-input" id="f_entities" name="entities" value="1"<?= $expand ? ' checked' : '' ?>> <?= lang('Accounting_reports.show_entities') ?></label>
            <label class="choice"><input type="checkbox" class="form-check-input" id="f_empty" name="empty" value="1"<?= $show_empty ? ' checked' : '' ?>> <?= lang('Accounting_reports.show_empty') ?></label>
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
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_reports'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="trial_balance">
    <h1 class="h4"><?= lang('Accounting_reports.trial_balance') ?> <small class="text-body-secondary fs-6">— <?= lang('Accounting_reports.type_' . $type->value) ?></small></h1>

    <p class="text-body-secondary mb-2" id="report_period">
        <?= $report->from === null
            ? lang('Accounting_reports.period_to', [$date($report->to)])
            : lang('Accounting_reports.period_between', [$date($report->from), $date($report->to)]) ?>
    </p>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($scope_label !== null): ?>
        <?php if ($scope_kind === ReportScopeKind::Partial): ?>
            <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scope_label) ?></p>
        <?php else: ?>
            <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scope_label) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($from_ignored): ?>
        <p class="small text-body-secondary" id="report_from_ignored" role="note"><?= lang('Accounting_reports.from_ignored') ?></p>
    <?php endif; ?>

    <?php if ($report->scope->isEmpty()): ?>
        <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
    <?php endif; ?>

    <?php if ($report->interBranchDifference !== null): ?>
        <?php if (bccomp($report->interBranchDifference, '0', 4) === 0): ?>
            <p class="small text-body-secondary" id="report_inter_branch_ok"><?= lang('Accounting_reports.inter_branch_balanced') ?></p>
        <?php else: ?>
            <p class="alert alert-warning" id="report_inter_branch_difference" role="alert"><?= lang('Accounting_reports.inter_branch_difference', [$amount($report->interBranchDifference)]) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <div class="table-responsive mb-3">
        <table class="table table-sm" id="trial_balance_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_reports.code') ?></th>
                    <th scope="col"><?= lang('Accounting_reports.account') ?></th>
                    <?php if ($type->showsOpening()): ?>
                        <th scope="col" class="text-end"><?= lang('Accounting_reports.opening_debit') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_reports.opening_credit') ?></th>
                    <?php endif; ?>
                    <?php if ($type->showsMovement()): ?>
                        <th scope="col" class="text-end"><?= lang('Accounting_reports.period_debit') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_reports.period_credit') ?></th>
                    <?php endif; ?>
                    <th scope="col" class="text-end"><?= lang('Accounting_reports.closing_debit') ?></th>
                    <th scope="col" class="text-end"><?= lang('Accounting_reports.closing_credit') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($report->rows === []): ?>
                    <tr><td colspan="<?= $span ?>" class="text-body-secondary" id="report_empty"><?= lang('Accounting_reports.empty') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($report->rows as $row): ?>
                    <?php
                    [$openingDebit, $openingCredit] = TrialBalanceRow::sides($row->opening);
                    [$closingDebit, $closingCredit] = TrialBalanceRow::sides($row->closing());
                    ?>
                    <tr<?= $row->isGroup ? ' class="fw-bold"' : '' ?> data-account="<?= esc($row->code, 'attr') ?>">
                        <td><bdi dir="ltr"><?= esc($row->code) ?></bdi></td>
                        <td><span class="acc-depth acc-depth-<?= min(6, max(0, $row->depth)) ?>"><a href="<?= esc($ledger_url($row->accountId), 'attr') ?>"><?= esc($row->name) ?></a></span></td>
                        <?php if ($type->showsOpening()): ?>
                            <td class="text-end"><?= $side($openingDebit, $amount) ?></td>
                            <td class="text-end"><?= $side($openingCredit, $amount) ?></td>
                        <?php endif; ?>
                        <?php if ($type->showsMovement()): ?>
                            <td class="text-end"><?= $side($row->debit, $amount) ?></td>
                            <td class="text-end"><?= $side($row->credit, $amount) ?></td>
                        <?php endif; ?>
                        <td class="text-end"><?= $side($closingDebit, $amount) ?></td>
                        <td class="text-end"><?= $side($closingCredit, $amount) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold" id="trial_balance_totals">
                    <th scope="row" colspan="2"><?= lang('Accounting_reports.totals') ?></th>
                    <?php if ($type->showsOpening()): ?>
                        <td class="text-end"><?= $amount($report->totals['opening_debit']) ?></td>
                        <td class="text-end"><?= $amount($report->totals['opening_credit']) ?></td>
                    <?php endif; ?>
                    <?php if ($type->showsMovement()): ?>
                        <td class="text-end"><?= $amount($report->totals['debit']) ?></td>
                        <td class="text-end"><?= $amount($report->totals['credit']) ?></td>
                    <?php endif; ?>
                    <td class="text-end"><?= $amount($report->totals['closing_debit']) ?></td>
                    <td class="text-end"><?= $amount($report->totals['closing_credit']) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <p class="small text-body-secondary" id="report_nominal_note"><?= lang('Accounting_reports.nominal_note') ?></p>

    <?php if ($report->balances()): ?>
        <p class="text-success" id="report_balanced"><span class="bi bi-check-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.balanced') ?></p>
    <?php else: ?>
        <p class="alert alert-danger" id="report_unbalanced" role="alert"><?= lang('Accounting_reports.unbalanced') ?></p>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
