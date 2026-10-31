<?php
/**
 * @var CashFlowReport                                       $report
 * @var string|null                                          $error
 * @var array{from: string, to: string, branches: list<int>} $filters
 * @var list<object>                                         $branches
 * @var string|null                                          $scopeLabel
 * @var string                                               $csvUrl
 * @var bool                                                 $canSetUp
 * @var Closure                                              $amount
 * @var Closure                                              $date
 * @var Closure                                              $title
 * @var Closure                                              $ledgerUrl
 */

use App\Libraries\Accounting\CashFlowReport;
use App\Libraries\Accounting\CashFlowSection;

?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" id="cash_flow_csv" href="<?= esc($csvUrl, 'attr') ?>">
        <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="cash_flow_settings_link" href="<?= esc(base_url('accounting_statements/cash_flow/settings'), 'attr') ?>">
        <span class="bi bi-sliders" aria-hidden="true">&nbsp;</span><?= lang($canSetUp ? 'Accounting_cash_flow.settings_edit' : 'Accounting_cash_flow.settings_view') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" id="link_profit_loss" href="<?= esc(base_url('accounting_statements/profit_loss'), 'attr') ?>">
        <span class="bi bi-graph-up-arrow" aria-hidden="true">&nbsp;</span><?= lang('Accounting_profit_loss.title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_reports'), 'attr') ?>">
        <span class="bi bi-table" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.trial_balance') ?>
    </a>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_statements/cash_flow'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
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
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_statements/cash_flow'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="cash_flow">
    <h1 class="h4"><?= lang('Accounting_cash_flow.title') ?></h1>

    <p class="text-body-secondary mb-2" id="report_period">
        <?= lang('Accounting_cash_flow.period_beginning') ?>: <?= esc($date($report->from)) ?> &middot; <?= lang('Accounting_cash_flow.period_ending') ?>: <?= esc($date($report->to)) ?>
    </p>

    <?= view('accounting_statements/notes', ['scope' => $report->scope, 'error' => $error, 'scopeLabel' => $scopeLabel]) ?>

    <div class="table-responsive mb-3">
        <table class="table table-sm" id="cash_flow_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_cash_flow.line') ?></th>
                    <th scope="col" class="text-end"><?= lang('Accounting_cash_flow.amount') ?></th>
                </tr>
            </thead>
            <?php foreach (CashFlowSection::cases() as $section): ?>
                <tbody data-section="<?= esc($section->value, 'attr') ?>">
                    <tr class="table-light"><th scope="rowgroup" colspan="2"><?= lang('Accounting_cash_flow.section_' . $section->value) ?></th></tr>
                    <?php if ($report->lines[$section->value] === []): ?>
                        <tr><td colspan="2" class="text-body-secondary"><?= lang('Accounting_cash_flow.section_empty') ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($report->lines[$section->value] as $line): ?>
                        <tr data-line="<?= esc($line->key, 'attr') ?>">
                            <td><?= esc($title($line)) ?></td>
                            <td class="text-end" data-cell="amount"><?= $amount($line->amount) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="fw-bold" data-total="<?= esc($section->value, 'attr') ?>">
                        <th scope="row"><?= lang('Accounting_cash_flow.total_' . $section->value) ?></th>
                        <td class="text-end" data-cell="amount"><?= $amount($report->totals[$section->value]) ?></td>
                    </tr>
                </tbody>
            <?php endforeach; ?>
            <tbody data-section="other">
                <tr class="table-light"><th scope="rowgroup" colspan="2"><?= lang('Accounting_cash_flow.other_changes') ?></th></tr>
                <?php if ($report->otherItems === []): ?>
                    <tr><td colspan="2" class="text-body-secondary"><?= lang('Accounting_cash_flow.other_none') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($report->otherItems as $item): ?>
                    <tr data-account="<?= esc($item['code'], 'attr') ?>">
                        <td><bdi dir="ltr"><?= esc($item['code']) ?></bdi> <a href="<?= esc($ledgerUrl($item['accountId']), 'attr') ?>"><?= esc($item['name']) ?></a></td>
                        <td class="text-end" data-cell="amount"><?= $amount($item['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="fw-bold" data-total="other">
                    <th scope="row"><?= lang('Accounting_cash_flow.other_total') ?></th>
                    <td class="text-end" data-cell="amount"><?= $amount($report->other) ?></td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="fw-bold table-light" data-total="change">
                    <th scope="row"><?= lang('Accounting_cash_flow.total_change') ?></th>
                    <td class="text-end" data-cell="amount"><?= $amount($report->totalChange) ?></td>
                </tr>
                <tr data-total="opening">
                    <th scope="row"><?= lang('Accounting_cash_flow.cash_opening') ?></th>
                    <td class="text-end" data-cell="amount"><?= $amount($report->cashOpening) ?></td>
                </tr>
                <tr class="fw-bold" data-total="closing">
                    <th scope="row"><?= lang('Accounting_cash_flow.cash_closing') ?></th>
                    <td class="text-end" data-cell="amount"><?= $amount($report->cashClosing) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($report->ties()): ?>
        <p class="text-success" id="report_ties"><span class="bi bi-check-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cash_flow.ties') ?></p>
    <?php else: ?>
        <p class="alert alert-danger" id="report_differs" role="alert"><?= lang('Accounting_cash_flow.differs') ?></p>
    <?php endif; ?>

    <p class="small text-body-secondary" id="report_note"><?= lang('Accounting_cash_flow.note') ?></p>
</div>

<?= view('partial/footer') ?>
