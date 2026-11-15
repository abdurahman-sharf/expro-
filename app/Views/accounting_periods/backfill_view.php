<?php
/**
 * @var BackfillBatch                                                                                   $batch
 * @var array{documents: int, ready: int, refused: int, journals: int, debit: string}                   $figures
 * @var list<array{reason: string, kind: string, documents: int, net: string, gross: string}>           $classes
 * @var array{documents: int, from: string, to: string, first: string, last: string, missing: int}|null $outsideYears
 * @var bool                                                                                            $canCreateYears
 * @var array{total: int, rows: list<object>}                                                           $items
 * @var array{total: int, rows: list<object>}                                                           $journals
 * @var array<int, list<object>>                                                                        $lines
 * @var list<object>                                                                                    $totals
 * @var string|null                                                                                     $outcome
 * @var int                                                                                             $documentsPage
 * @var int                                                                                             $journalsPage
 * @var int                                                                                             $pageSize
 * @var array<string, int|string>                                                                       $pageQuery
 * @var list<string>                                                                                    $blockers
 * @var string|null                                                                                     $notice
 * @var Closure                                                                                         $date
 * @var Closure                                                                                         $amount
 * @var string                                                                                          $statusText
 * @var Closure                                                                                         $viewUrl
 */

use App\Libraries\Accounting\Backfill\BackfillBatch;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillRefusalText;
use App\Libraries\Accounting\Backfill\BackfillStatus;

$collecting = $batch->status === BackfillStatus::Collecting;
$approvable = in_array($batch->status, [BackfillStatus::Preview, BackfillStatus::Verifying, BackfillStatus::Approving], true) && $batch->fingerprint !== null && $batch->readyCount > 0;
$cancelable = in_array($batch->status, [BackfillStatus::Collecting, BackfillStatus::Preview, BackfillStatus::Verifying, BackfillStatus::Approving], true);
$reasonText = static fn (string $reason): string => BackfillRefusalText::reason($reason);
$pager      = static function (int $total, int $page, int $size, string $param, Closure $url, array $query): string {
    $pages = max(1, (int) ceil($total / $size));

    if ($pages === 1) {
        return '';
    }

    $html = '<nav class="print_hide" aria-label="' . esc(lang('Accounting_backfill.pages'), 'attr') . '"><ul class="pagination pagination-sm">';

    for ($number = 1; $number <= $pages; $number++) {
        $html .= '<li class="page-item' . ($number === $page ? ' active' : '') . '"><a class="page-link" href="' . esc($url([...$query, $param => $number]), 'attr') . '">' . $number . '</a></li>';
    }

    return $html . '</ul></nav>';
};
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_periods/tabs', ['active' => 'backfill']) ?>

<div class="container-fluid py-3" id="backfill_view" data-status="<?= esc($batch->status->value, 'attr') ?>">
    <p><a href="<?= esc(base_url('accounting_periods/backfill'), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true"></span> <?= lang('Accounting_backfill.title') ?></a></p>

    <h1 class="h4">#<?= $batch->id ?> <?= esc($batch->name) ?> <span class="badge text-bg-secondary" id="backfill_status"><?= esc($statusText) ?></span></h1>

    <dl class="row" id="backfill_header">
        <dt class="col-sm-3"><?= lang('Accounting_backfill.range') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc($date($batch->from)) ?> - <?= esc($date($batch->to)) ?></bdi></dd>
        <dt class="col-sm-3"><?= lang('Accounting_backfill.branch') ?></dt>
        <dd class="col-sm-9"><?= $batch->branchId === null ? esc(lang('Accounting_backfill.scope_company')) : '<bdi dir="ltr">' . esc((string) $batch->branchCode) . '</bdi>' ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_backfill.families') ?></dt>
        <dd class="col-sm-9"><?= esc(implode(', ', array_map(static fn (BackfillFamily $family): string => lang('Accounting_backfill.family_' . $family->value), $batch->families))) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_backfill.documents') ?></dt>
        <dd class="col-sm-9" id="backfill_figures"><?= esc(lang('Accounting_backfill.figures', [(string) $figures['documents'], (string) $figures['ready'], (string) $figures['refused'], (string) $figures['journals']])) ?> <?= $amount($figures['debit']) ?></dd>
        <?php if ($batch->fingerprint !== null): ?>
            <dt class="col-sm-3"><?= lang('Accounting_backfill.fingerprint') ?></dt>
            <dd class="col-sm-9"><code id="backfill_fingerprint"><?= esc($batch->fingerprint) ?></code></dd>
        <?php endif; ?>
        <?php if ($batch->approvedCount > 0 && in_array($batch->status, [BackfillStatus::Approving, BackfillStatus::Cancelled], true)): ?>
            <dt class="col-sm-3"><?= lang('Accounting_backfill.approved_count') ?></dt>
            <dd class="col-sm-9"><?= $batch->approvedCount ?> / <?= $batch->readyCount ?></dd>
        <?php endif; ?>
    </dl>

    <?php if (in_array($batch->status, [BackfillStatus::Preview, BackfillStatus::Verifying, BackfillStatus::Approving], true)): ?>
        <?php foreach ($blockers as $blocker): ?>
            <?php [$code, $subject] = array_pad(explode(':', $blocker, 2), 2, ''); ?>
            <p class="alert alert-warning backfill-blocker" role="alert"><?= esc(lang('Accounting_backfill.blocker_' . $code, [$subject])) ?></p>
        <?php endforeach; ?>
    <?php endif; ?>

    <div class="print_hide mb-3">
        <?php if ($collecting): ?>
            <?= form_open('accounting_periods/backfill/preview/' . $batch->id, ['id' => 'backfill_preview_form', 'class' => 'd-inline']) ?>
                <button type="submit" class="btn btn-primary btn-sm" id="backfill_preview"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_backfill.preview_run') ?></button>
            <?= form_close() ?>
        <?php endif; ?>
        <?php if ($approvable): ?>
            <button type="button" id="backfill_approve" class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_backfill.approve_submit'), 'attr') ?>" data-href="<?= esc('accounting_periods/backfill/approve_form/' . $batch->id . '?' . http_build_query(['fingerprint' => $batch->fingerprint]), 'attr') ?>" title="<?= esc(lang('Accounting_backfill.approve_title'), 'attr') ?>">
                <span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_backfill.approve') ?>
            </button>
        <?php endif; ?>
        <?php if ($cancelable): ?>
            <button type="button" id="backfill_cancel" class="btn btn-outline-danger btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_backfill.cancel_submit'), 'attr') ?>" data-href="<?= esc('accounting_periods/backfill/cancel_form/' . $batch->id, 'attr') ?>" title="<?= esc(lang('Accounting_backfill.cancel_title'), 'attr') ?>">
                <span class="bi bi-x-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_backfill.cancel') ?>
            </button>
        <?php endif; ?>
    </div>

    <?php if ($outsideYears !== null): ?>
        <section class="alert alert-warning" id="backfill_outside_years" role="alert">
            <p class="mb-2"><?= esc(lang('Accounting_backfill.outside_years_notice', [(string) $outsideYears['documents'], $date($outsideYears['from']), $date($outsideYears['to']), $outsideYears['first'], $outsideYears['last']])) ?></p>
            <?php if ($outsideYears['missing'] === 0): ?>
                <p class="mb-0"><?= lang('Accounting_backfill.outside_years_exist') ?></p>
            <?php else: ?>
                <?php if ($canCreateYears): ?>
                    <?= form_open('accounting_periods/backfill/create_years/' . $batch->id, ['id' => 'backfill_years_form', 'class' => 'd-inline print_hide']) ?>
                        <button type="submit" class="btn btn-warning btn-sm" id="backfill_create_years"><span class="bi bi-calendar-plus" aria-hidden="true">&nbsp;</span><?= lang('Accounting_backfill.outside_years_create') ?></button>
                    <?= form_close() ?>
                <?php else: ?>
                    <p class="mb-1"><?= lang('Accounting_backfill.outside_years_permission') ?></p>
                <?php endif; ?>
                <p class="mb-0 mt-2 small"><?= lang('Accounting_backfill.outside_years_command') ?> <code dir="ltr">php spark accounting:enable</code></p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($classes !== []): ?>
        <section class="mt-3" id="backfill_reasons" aria-label="<?= esc(lang('Accounting_backfill.reasons'), 'attr') ?>">
            <h2 class="h5"><?= lang('Accounting_backfill.reasons') ?></h2>
            <p class="text-body-secondary small"><?= lang('Accounting_backfill.classes_intro') ?></p>
            <div class="table-responsive">
                <table class="table table-sm" id="backfill_classes_table">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_backfill.classes_class') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.documents') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.classes_net') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.classes_gross') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.classes_todo') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td><?= esc($reasonText($class['reason'])) ?><?= $class['kind'] === '' ? '' : ' <span class="text-body-secondary">(' . esc(BackfillRefusalText::kind($class['reason'], $class['kind'])) . ')</span>' ?></td>
                                <td class="text-end"><?= $class['documents'] ?></td>
                                <td class="text-end"><?= $amount($class['net']) ?></td>
                                <td class="text-end"><?= $amount($class['gross']) ?></td>
                                <td><?= esc(BackfillRefusalText::help($class['reason'], $class['kind'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-active">
                            <th scope="row"><?= lang('Accounting_backfill.classes_total') ?></th>
                            <td class="text-end"><?= array_sum(array_column($classes, 'documents')) ?></td>
                            <td class="text-end"><?= $amount(array_reduce($classes, static fn (string $sum, array $class): string => bcadd($sum, $class['net'], 4), '0.0000')) ?></td>
                            <td class="text-end"><?= $amount(array_reduce($classes, static fn (string $sum, array $class): string => bcadd($sum, $class['gross'], 4), '0.0000')) ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <p class="print_hide"><a class="btn btn-outline-secondary btn-sm" id="backfill_export_refused" href="<?= esc(base_url('accounting_periods/backfill/refused/' . $batch->id), 'attr') ?>"><span class="bi bi-download" aria-hidden="true">&nbsp;</span><?= lang('Accounting_backfill.export_refused') ?></a></p>
        </section>
    <?php endif; ?>

    <?php if ($totals !== []): ?>
        <section class="mt-4" id="backfill_totals" aria-label="<?= esc(lang('Accounting_backfill.totals'), 'attr') ?>">
            <h2 class="h5"><?= lang('Accounting_backfill.totals') ?></h2>
            <div class="table-responsive">
                <table class="table table-sm" id="backfill_totals_table">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_backfill.account') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.before') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_journals.debit') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_journals.credit') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.after') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($totals as $row): ?>
                            <tr>
                                <td><bdi dir="ltr"><?= esc($row->code) ?></bdi> <?= esc($row->name) ?></td>
                                <td class="text-end"><?= $amount($row->before_balance) ?></td>
                                <td class="text-end"><?= $amount($row->debit) ?></td>
                                <td class="text-end"><?= $amount($row->credit) ?></td>
                                <td class="text-end"><?= $amount($row->after_balance) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <section class="mt-4" id="backfill_documents" aria-label="<?= esc(lang('Accounting_backfill.documents'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_backfill.documents') ?></h2>
        <p class="print_hide">
            <?php foreach ([null => 'all', 'ready' => 'ready', 'refused' => 'refused'] as $value => $key): ?>
                <a class="btn btn-sm <?= $outcome === $value ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= esc($viewUrl(['journals_page' => $journalsPage] + ($value === null ? [] : ['outcome' => $value])), 'attr') ?>"><?= lang('Accounting_backfill.filter_' . $key) ?></a>
            <?php endforeach; ?>
        </p>
        <?php if ($items['rows'] === []): ?>
            <p class="text-body-secondary" id="backfill_no_documents"><?= lang('Accounting_backfill.no_documents') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm" id="backfill_documents_table">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_backfill.family') ?></th>
                            <th scope="col">#</th>
                            <th scope="col"><?= lang('Accounting_backfill.date') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.branch') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.outcome') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.reason') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.journals') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_backfill.amount') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items['rows'] as $row): ?>
                            <tr class="backfill-<?= esc($row->outcome, 'attr') ?>">
                                <td><?= esc(lang('Accounting_backfill.family_' . $row->family)) ?></td>
                                <td><?= (int) $row->document_id ?></td>
                                <td><bdi dir="ltr"><?= esc($date($row->document_date)) ?></bdi></td>
                                <td><bdi dir="ltr"><?= esc((string) $row->branch_code) ?></bdi></td>
                                <td><span class="badge <?= $row->outcome === 'ready' ? 'text-bg-success' : 'text-bg-warning' ?>"><?= lang('Accounting_backfill.outcome_' . $row->outcome) ?></span></td>
                                <td><?= $row->reason === null ? ($row->detail !== null && str_starts_with((string) $row->detail, 'stamp:') ? esc(lang('Accounting_backfill.stamp_note')) : '') : esc($reasonText((string) $row->reason)) . ($row->detail === null ? '' : ' <bdi dir="ltr" class="text-body-secondary">' . esc((string) $row->detail) . '</bdi>') ?></td>
                                <td class="text-end"><?= (int) $row->journal_count ?></td>
                                <td class="text-end"><?= $amount($row->total_debit) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $pager($items['total'], $documentsPage, $pageSize, 'documents_page', $viewUrl, $pageQuery) ?>
        <?php endif; ?>
    </section>

    <section class="mt-4" id="backfill_journals" aria-label="<?= esc(lang('Accounting_backfill.would_be'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_backfill.would_be') ?></h2>
        <?php if ($journals['rows'] === []): ?>
            <p class="text-body-secondary" id="backfill_no_journals"><?= lang('Accounting_backfill.no_journals') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm" id="backfill_journals_table">
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col"><?= lang('Accounting_backfill.date') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.source') ?></th>
                            <th scope="col"><?= lang('Accounting_backfill.account') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_journals.debit') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_journals.credit') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($journals['rows'] as $journal): ?>
                            <?php foreach ($lines[(int) $journal->journal_id] ?? [] as $index => $line): ?>
                                <tr<?= $index === 0 ? ' class="table-active"' : '' ?>>
                                    <td><?= $index === 0 ? (int) $journal->journal_id : '' ?></td>
                                    <td><?= $index === 0 ? '<bdi dir="ltr">' . esc($date($journal->journal_date)) . '</bdi>' : '' ?></td>
                                    <td><?= $index === 0 ? esc($journal->source_type) . ' #' . (int) $journal->source_id : '' ?></td>
                                    <td><bdi dir="ltr"><?= esc($line->account_code) ?></bdi> <?= esc($line->account_name) ?></td>
                                    <td class="text-end"><?= bccomp($line->debit, '0', 4) === 0 ? '' : $amount($line->debit) ?></td>
                                    <td class="text-end"><?= bccomp($line->credit, '0', 4) === 0 ? '' : $amount($line->credit) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= $pager($journals['total'], $journalsPage, $pageSize, 'journals_page', $viewUrl, $pageQuery) ?>
        <?php endif; ?>
    </section>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('#backfill_preview_form, #backfill_years_form').on('submit', function(event) {
            event.preventDefault();
            $(this).ajaxSubmit({
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.redirect;
                        return;
                    }
                    toast_support.show(response.message, 'danger');
                }
            });
        });
    });
</script>

<?= view('partial/footer') ?>
