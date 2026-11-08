<?php
/**
 * @var list<object>          $years
 * @var int|null              $year_id
 * @var array<string, string> $scopes
 * @var string                $scope
 * @var bool                  $can_run
 * @var YearEndPreview|null   $preview
 * @var string|null           $error
 * @var list<string>          $blockers
 * @var list<object>          $closings
 * @var string|null           $notice
 * @var Closure               $amount
 * @var Closure               $date
 * @var Closure               $status_text
 * @var string|null           $csv_url
 */

use App\Libraries\Accounting\YearEndBranchPlan;
use App\Libraries\Accounting\YearEndBranchStatus;
use App\Libraries\Accounting\YearEndPreview;

$side = static fn (string $value, Closure $amount): string => bccomp($value, '0', 4) === 0 ? '' : $amount($value);
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_periods/tabs', ['active' => 'year_end']) ?>

<div class="container-fluid py-3" id="year_end_page">
    <h1 class="h4"><?= lang('Accounting_periods.year_end_title') ?></h1>
    <p class="text-body-secondary"><?= lang('Accounting_periods.year_end_intro') ?></p>

    <?php if (! $can_run): ?>
        <p class="text-body-secondary small" id="year_end_permission_note" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_periods.year_end_permission_note') ?></p>
    <?php endif; ?>

    <?php if ($can_run): ?>
        <?php if ($years === []): ?>
            <p class="alert alert-info" id="year_end_no_years"><?= lang('Accounting_periods.year_end_no_years') ?></p>
        <?php else: ?>
            <form id="year_end_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_periods/year_end'), 'attr') ?>">
                <div class="acc-filter-row">
                    <div class="acc-filter">
                        <label class="form-label" for="f_year"><?= lang('Accounting_periods.year_end_year') ?></label>
                        <select name="year" id="f_year" class="form-select form-select-sm">
                            <?php foreach ($years as $year): ?>
                                <option value="<?= (int) $year->fiscal_year_id ?>"<?= (int) $year->fiscal_year_id === $year_id ? ' selected' : '' ?>><?= esc($year->name) ?><?= (int) $year->closing_count > 0 ? ' (' . esc(lang('Accounting_periods.year_end_closed_mark')) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="acc-filter">
                        <label class="form-label" for="f_scope"><?= lang('Accounting_periods.year_end_scope') ?></label>
                        <select name="scope" id="f_scope" class="form-select form-select-sm">
                            <?php foreach ($scopes as $value => $label): ?>
                                <option value="<?= esc((string) $value, 'attr') ?>"<?= (string) $value === $scope ? ' selected' : '' ?>><?= esc($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="acc-filter acc-filter-actions">
                        <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.year_end_preview') ?></button>
                    </div>
                </div>
            </form>

            <?php if ($error !== null): ?>
                <p class="alert alert-danger" id="year_end_error" role="alert"><?= esc($error) ?></p>
            <?php endif; ?>

            <?php if ($preview !== null): ?>
                <section id="year_end_preview" aria-label="<?= esc(lang('Accounting_periods.year_end_preview'), 'attr') ?>">
                    <p class="text-body-secondary mb-2">
                        <?= esc(lang('Accounting_periods.year_end_period', [$preview->year->name, $date($preview->year->start_date), $date($preview->year->end_date)])) ?>
                        <?php if ($preview->retainedAccount !== null): ?>
                            <br><?= lang('Accounting_periods.year_end_retained') ?>: <bdi dir="ltr"><?= esc($preview->retainedAccount->code) ?></bdi> <?= esc($preview->retainedAccount->name) ?>
                        <?php endif; ?>
                    </p>

                    <?php foreach ($blockers as $blocker): ?>
                        <p class="alert alert-warning year-end-blocker" role="alert"><?= esc($blocker) ?></p>
                    <?php endforeach; ?>

                    <?php foreach ($preview->branches as $plan): ?>
                        <?php /** @var YearEndBranchPlan $plan */ ?>
                        <div class="year-end-branch mb-4" id="year_end_branch_<?= $plan->branchId ?>">
                            <h2 class="h6">
                                <bdi dir="ltr"><?= esc($plan->code) ?></bdi> <?= esc($plan->name) ?>
                                <?php if (! $plan->active): ?><span class="badge text-bg-secondary"><?= lang('Accounting_reports.branch_inactive') ?></span><?php endif; ?>
                                <span class="badge <?= $plan->status === YearEndBranchStatus::Ready ? 'text-bg-primary' : 'text-bg-secondary' ?>"><?= esc($status_text($plan)) ?></span>
                            </h2>

                            <?php if ($plan->status === YearEndBranchStatus::AlreadyClosed): ?>
                                <p class="text-body-secondary mb-0">
                                    <?= esc(lang('Accounting_periods.year_end_already_closed_note')) ?>
                                    <a href="<?= esc(base_url('accounting_journals/view/' . (int) $plan->closing->journal_id), 'attr') ?>"><?= lang('Accounting_periods.year_end_journal') ?></a>
                                </p>
                            <?php elseif ($plan->status === YearEndBranchStatus::NoActivity): ?>
                                <p class="text-body-secondary mb-0"><?= lang('Accounting_periods.year_end_nothing') ?></p>
                            <?php else: ?>
                                <div class="acc-lines-wrap">
                                    <table class="table table-sm acc-lines">
                                        <thead>
                                            <tr>
                                                <th scope="col"><?= lang('Accounting_periods.year_end_account') ?></th>
                                                <th scope="col" class="acc-amount"><?= lang('Accounting_journals.debit') ?></th>
                                                <th scope="col" class="acc-amount"><?= lang('Accounting_journals.credit') ?></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($plan->lines as $line): ?>
                                                <tr>
                                                    <td data-label="<?= esc(lang('Accounting_periods.year_end_account'), 'attr') ?>"><bdi dir="ltr"><?= esc($line->code) ?></bdi> <?= esc($line->name) ?></td>
                                                    <td data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>" class="acc-amount"><?= $side($line->closingDebit(), $amount) ?></td>
                                                    <td data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>" class="acc-amount"><?= $side($line->closingCredit(), $amount) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if ($preview->retainedAccount !== null && ($plan->isProfit() || $plan->isLoss())): ?>
                                                <tr class="year-end-retained-row">
                                                    <td data-label="<?= esc(lang('Accounting_periods.year_end_account'), 'attr') ?>"><bdi dir="ltr"><?= esc($preview->retainedAccount->code) ?></bdi> <?= esc($preview->retainedAccount->name) ?></td>
                                                    <td data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>" class="acc-amount"><?= $plan->isLoss() ? $amount(bcsub('0', $plan->result, 4)) : '' ?></td>
                                                    <td data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>" class="acc-amount"><?= $plan->isProfit() ? $amount($plan->result) : '' ?></td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <dl class="row mb-0 year-end-totals">
                                    <dt class="col-sm-3"><?= lang('Accounting_periods.year_end_revenue') ?></dt>
                                    <dd class="col-sm-9"><?= $amount($plan->revenue) ?></dd>
                                    <dt class="col-sm-3"><?= lang('Accounting_periods.year_end_expense') ?></dt>
                                    <dd class="col-sm-9"><?= $amount($plan->expense) ?></dd>
                                    <dt class="col-sm-3"><?= $plan->isLoss() ? lang('Accounting_periods.year_end_loss') : lang('Accounting_periods.year_end_profit') ?></dt>
                                    <dd class="col-sm-9"><?= $amount($plan->isLoss() ? bcsub('0', $plan->result, 4) : $plan->result) ?></dd>
                                </dl>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($csv_url !== null): ?>
                        <div class="print_hide mb-3 clearfix" id="year_end_files">
                            <a class="btn btn-outline-secondary btn-sm float-end" id="year_end_csv" href="<?= esc($csv_url, 'attr') ?>"><span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?></a>
                            <?= view('partial/report_files', ['csvUrl' => $csv_url, 'id' => 'year_end_csv']) ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()"><span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($preview->canApprove()): ?>
                        <button type="button" id="year_end_approve" class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_periods.year_end_approve_submit'), 'attr') ?>" data-href="<?= esc('accounting_periods/year_end/approve_form?' . http_build_query(['year' => $year_id, 'scope' => $scope, 'fingerprint' => $preview->fingerprint]), 'attr') ?>" title="<?= esc(lang('Accounting_periods.year_end_approve_title'), 'attr') ?>">
                            <span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.year_end_approve') ?>
                        </button>
                    <?php elseif ($blockers === [] && $preview->ready() === []): ?>
                        <p class="text-body-secondary" id="year_end_no_ready"><?= lang('Accounting_periods.year_end_no_ready') ?></p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <section class="mt-4" id="year_end_closings" aria-label="<?= esc(lang('Accounting_periods.year_end_closings'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_periods.year_end_closings') ?></h2>

        <?php if ($closings === []): ?>
            <p class="text-body-secondary" id="year_end_no_closings"><?= lang('Accounting_periods.year_end_no_closings') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm acc-lines" id="year_end_closings_table">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_periods.year_end_year') ?></th>
                            <th scope="col"><?= lang('Accounting_periods.year_end_branch') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_periods.year_end_result') ?></th>
                            <th scope="col"><?= lang('Accounting_periods.year_end_journal') ?></th>
                            <th scope="col"><?= lang('Accounting.status') ?></th>
                            <th scope="col"><?= lang('Accounting_periods.year_end_closed_by') ?></th>
                            <th scope="col"><?= lang('Accounting_periods.year_end_taken_back_by') ?></th>
                            <th scope="col"><span class="visually-hidden"><?= lang('Accounting_periods.year_end_reverse') ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($closings as $closing): ?>
                            <?php $active = $closing->status === 'active'; ?>
                            <tr>
                                <td><?= esc($closing->year_name) ?></td>
                                <td><bdi dir="ltr"><?= esc($closing->branch_code) ?></bdi> <?= esc($closing->branch_name) ?></td>
                                <td class="text-end"><?= $amount($closing->result_amount) ?></td>
                                <td><a href="<?= esc(base_url('accounting_journals/view/' . (int) $closing->journal_id), 'attr') ?>">#<?= (int) $closing->journal_id ?></a></td>
                                <td><span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= lang('Accounting_periods.year_end_closing_status_' . ($active ? 'active' : 'reversed')) ?></span></td>
                                <td><?= esc((string) $closing->created_by_name) ?> <bdi dir="ltr" class="text-body-secondary"><?= esc($date(substr((string) $closing->created_at, 0, 10))) ?></bdi></td>
                                <td><?php if (! $active): ?><?= esc((string) $closing->reversed_by_name) ?> <bdi dir="ltr" class="text-body-secondary"><?= esc($date(substr((string) $closing->reversed_at, 0, 10))) ?></bdi> <?= esc((string) $closing->reverse_reason) ?> <?php if ($closing->reversal_journal_id !== null): ?><a href="<?= esc(base_url('accounting_journals/view/' . (int) $closing->reversal_journal_id), 'attr') ?>">#<?= (int) $closing->reversal_journal_id ?></a><?php endif; ?><?php endif; ?></td>
                                <td>
                                    <?php if ($can_run && $active): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm modal-dlg year-end-reverse" data-btn-submit="<?= esc(lang('Accounting_periods.year_end_reverse_submit'), 'attr') ?>" data-href="<?= esc('accounting_periods/year_end/reverse_form/' . (int) $closing->closing_id, 'attr') ?>" title="<?= esc(lang('Accounting_periods.year_end_reverse_title'), 'attr') ?>">
                                            <span class="bi bi-arrow-counterclockwise" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.year_end_reverse') ?>
                                        </button>
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
