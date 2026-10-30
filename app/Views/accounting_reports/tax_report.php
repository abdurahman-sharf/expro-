<?php
/**
 * @var TaxReportResult|null                                 $report
 * @var string|null                                          $error
 * @var array{from: string, to: string, branches: list<int>} $filters
 * @var list<object>                                         $branches
 * @var ReportScope                                          $scope
 * @var string|null                                          $scopeLabel
 * @var ReportScopeKind                                      $scopeKind
 * @var string                                               $csvUrl
 * @var Closure                                              $amount
 * @var Closure                                              $date
 * @var Closure                                              $ledgerUrl
 */

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;
use App\Libraries\Accounting\TaxReportResult;

$roleLabel = static fn (array $roles): string => implode(' / ', array_map(static fn (string $role): string => lang('Accounting_tax_reports.role_' . $role), $roles));
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <?php if ($report !== null): ?>
        <a class="btn btn-outline-secondary btn-sm float-end" id="export_csv" href="<?= esc($csvUrl, 'attr') ?>">
            <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_tax_reports.csv') ?>
        </a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_reports'), 'attr') ?>">
        <span class="bi bi-file-earmark-bar-graph" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.trial_balance') ?>
    </a>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_reports/tax'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_from"><?= lang('Accounting_reports.from') ?></label>
            <input type="date" id="f_from" name="from" class="form-control form-control-sm" value="<?= esc($filters['from'], 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_to"><?= lang('Accounting_reports.to') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($filters['to'], 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_basis"><?= lang('Accounting_tax_reports.basis') ?></label>
            <select id="f_basis" name="basis" class="form-select form-select-sm" aria-describedby="basis_note_cash">
                <option value="accrual" selected><?= lang('Accounting_tax_reports.basis_accrual') ?></option>
                <option value="cash" disabled><?= lang('Accounting_tax_reports.basis_cash') ?></option>
                <option value="partial" disabled><?= lang('Accounting_tax_reports.basis_partial') ?></option>
            </select>
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
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_reports/tax'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <p class="small text-body-secondary mb-0 mt-2" id="basis_note_cash"><?= lang('Accounting_tax_reports.basis_cash_note') ?></p>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="tax_report">
    <h1 class="h4"><?= lang('Accounting_tax_reports.title') ?></h1>

    <?php if ($report !== null): ?>
        <p class="text-body-secondary mb-2" id="report_period">
            <?= $report->from === null
                ? esc(lang('Accounting_tax_reports.period_all', [$date($report->to)]))
                : esc(lang('Accounting_reports.period_between', [$date($report->from), $date($report->to)])) ?>
        </p>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($scopeLabel !== null): ?>
        <?php if ($scopeKind === ReportScopeKind::Partial): ?>
            <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scopeLabel) ?></p>
        <?php else: ?>
            <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scopeLabel) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($scope->isEmpty()): ?>
        <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
    <?php endif; ?>

    <?php if ($report !== null): ?>
        <p class="small text-body-secondary" id="report_basis"><?= lang('Accounting_tax_reports.basis_accrual_note') ?></p>

        <?php if ($report->startDate !== null): ?>
            <?php if ($report->reachesBeforeBooks()): ?>
                <p class="alert alert-warning" id="report_books_start" role="note"><?= esc(lang('Accounting_tax_reports.start_note', [$date($report->startDate)])) ?> <?= lang('Accounting_tax_reports.start_reaches') ?></p>
            <?php else: ?>
                <p class="small text-body-secondary" id="report_books_start"><?= esc(lang('Accounting_tax_reports.start_note', [$date($report->startDate)])) ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($report->legacyDocuments > 0): ?>
            <p class="alert alert-warning" id="report_legacy" role="note"><?= esc(lang('Accounting_tax_reports.legacy_documents', [$report->legacyDocuments])) ?></p>
        <?php endif; ?>

        <?php if (! $report->outputRouted): ?>
            <p class="alert alert-danger" id="report_not_routed_output" role="alert"><?= lang('Accounting_tax_reports.not_routed_output') ?></p>
        <?php endif; ?>
        <?php if (! $report->inputRouted): ?>
            <p class="alert alert-danger" id="report_not_routed_input" role="alert"><?= lang('Accounting_tax_reports.not_routed_input') ?></p>
        <?php endif; ?>

        <?php if ($report->unmatchedDocuments > 0): ?>
            <p class="alert alert-warning" id="report_unmatched" role="note"><?= esc(lang('Accounting_tax_reports.unmatched', [$report->unmatchedDocuments])) ?></p>
        <?php endif; ?>

        <?php if (! $scope->isEmpty() && $report->isEmpty()): ?>
            <p class="alert alert-info" id="report_empty" role="note"><?= lang('Accounting_tax_reports.empty') ?></p>
        <?php endif; ?>

        <div class="table-responsive mb-3">
            <table class="table table-sm" id="tax_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_tax_reports.col_part') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_tax_reports.col_documents') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_tax_reports.col_base') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_tax_reports.col_tax') ?></th>
                    </tr>
                </thead>
                <?php foreach ($report->sections as $figures): ?>
                    <tbody data-section="<?= esc($figures->section->value, 'attr') ?>">
                        <tr class="fw-semibold" data-row="section">
                            <th scope="row"><?= lang('Accounting_tax_reports.section_' . $figures->section->value) ?></th>
                            <td class="text-end"><?= (int) $figures->documents ?></td>
                            <td class="text-end" data-cell="base"><?= $amount($figures->base) ?></td>
                            <td class="text-end" data-cell="tax"><?= $amount($figures->tax) ?></td>
                        </tr>
                        <?php foreach ($figures->rates as $rate): ?>
                            <tr class="text-body-secondary" data-row="rate" data-rate="<?= esc($rate->rate, 'attr') ?>">
                                <th scope="row" class="fw-normal ps-4"><?= esc(lang('Accounting_tax_reports.rate_row', [$rate->rate])) ?></th>
                                <td class="text-end"><?= (int) $rate->documents ?></td>
                                <td class="text-end" data-cell="base"><?= $amount($rate->base) ?></td>
                                <td class="text-end" data-cell="tax"><?= $amount($rate->tax) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($figures->discountedDocuments > 0): ?>
                            <tr class="text-body-secondary" data-row="discounted">
                                <th scope="row" class="fw-normal ps-4"><?= lang('Accounting_tax_reports.discounted_row') ?></th>
                                <td class="text-end"><?= (int) $figures->discountedDocuments ?></td>
                                <td class="text-end" data-cell="base"><?= $amount($figures->discountedBase) ?></td>
                                <td class="text-end" data-cell="tax"><?= $amount($figures->discountedTax) ?></td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                <?php endforeach; ?>
                <tfoot>
                    <tr data-total="output">
                        <th scope="row"><?= lang('Accounting_tax_reports.total_output') ?></th>
                        <td></td>
                        <td class="text-end" data-cell="base"><?= $amount($report->outputBase()) ?></td>
                        <td class="text-end" data-cell="tax"><?= $amount($report->output()) ?></td>
                    </tr>
                    <tr data-total="input">
                        <th scope="row"><?= lang('Accounting_tax_reports.total_input') ?></th>
                        <td></td>
                        <td class="text-end" data-cell="base"><?= $amount($report->inputBase()) ?></td>
                        <td class="text-end" data-cell="tax"><?= $amount($report->input()) ?></td>
                    </tr>
                    <tr class="fw-bold" data-total="net" data-direction="<?= bccomp($report->net(), '0', 4) < 0 ? 'refundable' : 'payable' ?>">
                        <th scope="row"><?= lang(bccomp($report->net(), '0', 4) < 0 ? 'Accounting_tax_reports.net_refundable' : 'Accounting_tax_reports.net_payable') ?></th>
                        <td></td>
                        <td></td>
                        <td class="text-end" data-cell="tax" id="report_net"><?= $amount($report->net()) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($report->accounts !== []): ?>
            <section class="mb-4" id="tax_accounts" aria-labelledby="tax_accounts_title">
                <h2 class="h5" id="tax_accounts_title"><?= lang('Accounting_tax_reports.accounts_title') ?></h2>
                <div class="table-responsive">
                    <table class="table table-sm" id="tax_accounts_table">
                        <thead>
                            <tr>
                                <th scope="col"><?= lang('Accounting_tax_reports.account') ?></th>
                                <th scope="col" class="text-end"><?= lang('Accounting_tax_reports.col_movement') ?></th>
                                <th scope="col" class="text-end"><?= lang('Accounting_tax_reports.col_reported') ?></th>
                                <th scope="col" class="text-end"><?= lang('Accounting_tax_reports.col_other') ?></th>
                                <th scope="col"><?= lang('Accounting_tax_reports.col_status') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($report->accounts as $account): ?>
                                <tr data-account="<?= (int) $account->accountId ?>">
                                    <th scope="row">
                                        <a href="<?= esc($ledgerUrl($account->accountId), 'attr') ?>"><bdi dir="ltr"><?= esc($account->code) ?></bdi></a>
                                        <?= esc($account->name) ?>
                                        <small class="text-body-secondary">(<?= esc($roleLabel($account->roles)) ?>)</small>
                                    </th>
                                    <td class="text-end" data-cell="movement"><?= $amount($account->movement) ?></td>
                                    <td class="text-end" data-cell="reported"><?= $amount($account->reported) ?></td>
                                    <td class="text-end" data-cell="other"><?= $amount($account->other()) ?></td>
                                    <td>
                                        <?php if ($account->ties()): ?>
                                            <span class="badge text-bg-success" data-status="ties"><?= lang('Accounting_tax_reports.agrees') ?></span>
                                        <?php else: ?>
                                            <span class="badge text-bg-danger" data-status="differs"><?= lang('Accounting_tax_reports.differs') ?></span>
                                            <?php if ($account->otherJournals > 0): ?>
                                                <small class="d-block text-body-secondary"><?= esc(lang('Accounting_tax_reports.tie_other', [$account->otherJournals])) ?></small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php if ($report->ties()): ?>
                <p class="text-success" id="report_ties"><span class="bi bi-check-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_tax_reports.ties_all') ?></p>
            <?php else: ?>
                <p class="alert alert-warning" id="report_differs" role="note"><?= lang('Accounting_tax_reports.differs_all') ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <p class="small text-body-secondary" id="report_base_note"><?= lang('Accounting_tax_reports.base_note') ?></p>
        <p class="small text-body-secondary" id="report_rates_note"><?= lang('Accounting_tax_reports.note_rates') ?> <?= lang('Accounting_tax_reports.note_expenses') ?></p>
        <p class="small text-body-secondary" id="report_parts_note"><?= lang('Accounting_tax_reports.note_parts') ?></p>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
