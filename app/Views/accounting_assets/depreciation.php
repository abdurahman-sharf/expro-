<?php
/**
 * @var bool                      $can_run
 * @var array<string, string>     $scopes
 * @var string                    $scope
 * @var string                    $month
 * @var DepreciationPreview|null  $preview
 * @var string|null               $error
 * @var list<string>              $blockers
 * @var list<object>              $runs
 * @var Closure                   $amount
 * @var Closure                   $date
 * @var string|null               $notice
 */

use App\Libraries\Assets\DepreciationBranchStatus;
use App\Libraries\Assets\DepreciationPreview;
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_assets/tabs', ['active' => 'depreciation']) ?>

<div class="container-fluid py-3" id="depreciation_page">
    <h1 class="h4"><?= lang('Accounting_assets.depreciation_title') ?></h1>
    <p class="text-body-secondary"><?= lang('Accounting_assets.depreciation_intro') ?></p>

    <?php if (! $can_run): ?>
        <p class="text-body-secondary small" id="depreciation_permission_note" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_assets.depreciation_permission_note') ?></p>
    <?php elseif ($scopes !== []): ?>
        <form id="depreciation_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_assets/depreciation'), 'attr') ?>">
            <div class="acc-filter-row">
                <div class="acc-filter">
                    <label class="form-label" for="f_month"><?= lang('Accounting_assets.depreciation_month') ?></label>
                    <input type="month" name="month" id="f_month" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($month, 'attr') ?>">
                </div>
                <div class="acc-filter">
                    <label class="form-label" for="f_scope"><?= lang('Accounting_assets.depreciation_scope') ?></label>
                    <select name="scope" id="f_scope" class="form-select form-select-sm">
                        <?php foreach ($scopes as $value => $label): ?>
                            <option value="<?= esc((string) $value, 'attr') ?>"<?= (string) $value === $scope ? ' selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="acc-filter acc-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_assets.depreciation_preview') ?></button>
                </div>
            </div>
        </form>

        <?php if ($error !== null): ?>
            <p class="alert alert-danger" id="depreciation_error" role="alert"><?= esc($error) ?></p>
        <?php endif; ?>

        <?php if ($preview !== null): ?>
            <section id="depreciation_preview" aria-label="<?= esc(lang('Accounting_assets.depreciation_preview'), 'attr') ?>">
                <?php foreach ($blockers as $blocker): ?>
                    <p class="alert alert-warning depreciation-blocker" role="alert"><?= esc($blocker) ?></p>
                <?php endforeach; ?>

                <?php foreach ($preview->branches as $plan): ?>
                    <div class="depreciation-branch mb-4" id="depreciation_branch_<?= $plan->branchId ?>">
                        <h2 class="h6">
                            <bdi dir="ltr"><?= esc($plan->code) ?></bdi> <?= esc($plan->name) ?>
                            <?php if (! $plan->active): ?><span class="badge text-bg-secondary"><?= lang('Accounting_reports.branch_inactive') ?></span><?php endif; ?>
                            <span class="badge <?= $plan->status === DepreciationBranchStatus::Ready ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= lang('Accounting_assets.branch_status_' . $plan->status->value) ?></span>
                        </h2>
                        <?php if ($plan->lines === []): ?>
                            <p class="text-body-secondary mb-0"><?= lang('Accounting_assets.depreciation_nothing') ?></p>
                        <?php else: ?>
                            <div class="acc-lines-wrap">
                                <table class="table table-sm acc-lines">
                                    <thead>
                                        <tr>
                                            <th scope="col"><?= lang('Accounting_assets.column_code') ?></th>
                                            <th scope="col"><?= lang('Accounting_assets.column_name') ?></th>
                                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.preview_before') ?></th>
                                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.column_depreciation') ?></th>
                                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.preview_after') ?></th>
                                            <th scope="col"><?= lang('Accounting_assets.preview_missed') ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($plan->lines as $line): ?>
                                            <tr>
                                                <td data-label="<?= esc(lang('Accounting_assets.column_code'), 'attr') ?>"><a href="<?= esc(base_url('accounting_assets/view/' . (int) $line->asset->asset_id), 'attr') ?>"><bdi dir="ltr"><?= esc($line->asset->code) ?></bdi></a></td>
                                                <td data-label="<?= esc(lang('Accounting_assets.column_name'), 'attr') ?>"><?= esc($line->asset->name) ?></td>
                                                <td data-label="<?= esc(lang('Accounting_assets.preview_before'), 'attr') ?>" class="acc-amount"><?= $amount($line->accumulatedBefore) ?></td>
                                                <td data-label="<?= esc(lang('Accounting_assets.column_depreciation'), 'attr') ?>" class="acc-amount"><?= $amount($line->amount) ?></td>
                                                <td data-label="<?= esc(lang('Accounting_assets.preview_after'), 'attr') ?>" class="acc-amount"><?= $amount($line->accumulatedAfter()) ?></td>
                                                <td data-label="<?= esc(lang('Accounting_assets.preview_missed'), 'attr') ?>"><?= $line->missedMonths === [] ? '' : '<span class="badge text-bg-warning">' . esc(implode(', ', $line->missedMonths)) . '</span>' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="fw-semibold">
                                            <th scope="row" colspan="3"><?= lang('Accounting_assets.total') ?></th>
                                            <td class="acc-amount"><?= $amount($plan->total) ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($preview->canApprove()): ?>
                    <button type="button" id="depreciation_approve" class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_assets.approve_submit'), 'attr') ?>" data-href="<?= esc('accounting_assets/depreciation/approve_form?' . http_build_query(['month' => $month, 'scope' => $scope, 'fingerprint' => $preview->fingerprint]), 'attr') ?>" title="<?= esc(lang('Accounting_assets.approve_title'), 'attr') ?>">
                        <span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_assets.approve') ?>
                    </button>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <section class="mt-4" id="depreciation_runs">
        <h2 class="h6"><?= lang('Accounting_assets.runs_title') ?></h2>
        <?php if ($runs === []): ?>
            <p class="text-body-secondary" id="runs_empty"><?= lang('Accounting_assets.runs_empty') ?></p>
        <?php else: ?>
            <div class="acc-lines-wrap">
                <table class="table table-sm acc-lines" id="runs_table">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_assets.depreciation_month') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.column_branch') ?></th>
                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.total') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.runs_assets') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.history_state') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.runs_by') ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runs as $run): ?>
                            <tr class="run-row" data-run="<?= (int) $run->run_id ?>">
                                <td data-label="<?= esc(lang('Accounting_assets.depreciation_month'), 'attr') ?>"><?= esc(substr((string) $run->period_start, 0, 7)) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.column_branch'), 'attr') ?>"><bdi dir="ltr"><?= esc($run->branch_code) ?></bdi></td>
                                <td data-label="<?= esc(lang('Accounting_assets.total'), 'attr') ?>" class="acc-amount"><?= $amount((string) $run->total) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.runs_assets'), 'attr') ?>"><?= (int) $run->line_count ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.history_state'), 'attr') ?>"><?= lang('Accounting_assets.run_' . $run->status) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.runs_by'), 'attr') ?>"><?= esc((string) $run->created_by_name) ?></td>
                                <td class="print_hide">
                                    <a href="<?= esc(base_url('accounting_journals/view/' . (int) $run->journal_id), 'attr') ?>"><?= lang('Accounting_assets.journal') ?></a>
                                    <?php if ($can_run && $run->status === 'active'): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm modal-dlg depreciation-reverse" data-btn-submit="<?= esc(lang('Accounting_assets.reverse'), 'attr') ?>" data-href="<?= esc('accounting_assets/depreciation/reverse_form/' . (int) $run->run_id, 'attr') ?>" title="<?= esc(lang('Accounting_assets.reverse_title'), 'attr') ?>"><?= lang('Accounting_assets.reverse') ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<?= view('partial/footer') ?>
