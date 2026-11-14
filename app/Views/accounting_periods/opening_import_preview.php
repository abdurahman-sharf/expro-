<?php
/**
 * @var OpeningImportKind                                        $kind
 * @var string                                                   $token
 * @var OpeningImportPlan                                        $plan
 * @var array{positive: string, negative: string, net: string}   $totals
 * @var array{rows: int, pending: int, posted: int, error: int}  $counts
 * @var list<OpeningImportRow>                                   $rows
 * @var array<int, string>                                       $accounts
 * @var string|null                                              $state
 * @var int                                                      $page
 * @var int                                                      $pages
 * @var Closure                                                  $urlOf
 * @var Closure                                                  $amountOf
 * @var string|null                                              $notice
 */

use App\Libraries\Accounting\OpeningImport\OpeningImportError;
use App\Libraries\Accounting\OpeningImport\OpeningImportKind;
use App\Libraries\Accounting\OpeningImport\OpeningImportPlan;
use App\Libraries\Accounting\OpeningImport\OpeningImportRow;

$approvable = $counts['error'] === 0 && $counts['pending'] > 0;
$positive   = $kind->sideOf(true);
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_periods/tabs', ['active' => 'opening_import']) ?>

<div class="container-fluid py-3" id="opening_import_preview" data-kind="<?= esc($kind->value, 'attr') ?>">
    <p><a href="<?= esc(base_url('accounting_periods/opening_import'), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true"></span> <?= lang('Accounting_opening_import.back') ?></a></p>

    <h1 class="h4"><?= lang('Accounting_opening_import.preview_title') ?> <span class="badge text-bg-secondary"><?= lang('Accounting_opening_import.kind_' . $kind->value) ?></span></h1>

    <dl class="row" id="opening_import_summary">
        <dt class="col-sm-3"><?= lang('Accounting_opening_import.summary_rows') ?></dt>
        <dd class="col-sm-9" id="opening_import_rows"><?= $counts['rows'] ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_opening_import.summary_ready') ?></dt>
        <dd class="col-sm-9" id="opening_import_pending"><?= $counts['pending'] ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_opening_import.summary_posted') ?></dt>
        <dd class="col-sm-9" id="opening_import_posted"><?= $counts['posted'] ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_opening_import.summary_errors') ?></dt>
        <dd class="col-sm-9" id="opening_import_errors"><?= $counts['error'] ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_backfill.fingerprint') ?></dt>
        <dd class="col-sm-9"><code id="opening_import_fingerprint"><?= esc($plan->fingerprint) ?></code></dd>
    </dl>

    <section class="mt-3" id="opening_import_totals" aria-label="<?= esc(lang('Accounting_opening_import.totals_title'), 'attr') ?>">
        <h2 class="h5"><?= lang('Accounting_opening_import.totals_title') ?></h2>
        <table class="table table-sm w-auto">
            <tbody>
                <tr><th scope="row"><?= lang('Accounting_opening_import.totals_' . $kind->value . '_positive') ?></th><td class="text-end"><?= $amountOf($totals['positive']) ?></td></tr>
                <tr><th scope="row"><?= lang('Accounting_opening_import.totals_' . $kind->value . '_negative') ?></th><td class="text-end"><?= $amountOf($totals['negative']) ?></td></tr>
                <tr class="table-active"><th scope="row"><?= lang('Accounting_opening_import.totals_net') ?></th><td class="text-end"><?= $amountOf($totals['net']) ?></td></tr>
            </tbody>
        </table>
        <p class="text-body-secondary small"><?= lang('Accounting_opening_import.totals_equity') ?></p>
    </section>

    <?php if ($counts['error'] > 0): ?>
        <p class="alert alert-warning" role="alert" id="opening_import_blocked"><?= esc(lang('Accounting_opening_import.blocked_errors', [(string) $counts['error']])) ?></p>
    <?php elseif ($counts['pending'] === 0): ?>
        <p class="alert alert-success" role="status" id="opening_import_nothing"><?= lang('Accounting_opening_import.nothing_to_post') ?></p>
    <?php endif; ?>

    <div class="print_hide mb-3">
        <?php if ($approvable): ?>
            <button type="button" id="opening_import_approve" class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_opening_import.approve_submit'), 'attr') ?>" data-href="<?= esc('accounting_periods/opening_import/approve_form/' . $kind->value . '/' . $token . '?' . http_build_query(['fingerprint' => $plan->fingerprint]), 'attr') ?>" title="<?= esc(lang('Accounting_opening_import.approve_title'), 'attr') ?>">
                <span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_opening_import.approve') ?>
            </button>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" id="opening_import_export" href="<?= esc(base_url('accounting_periods/opening_import/export/' . $kind->value . '/' . $token), 'attr') ?>"><span class="bi bi-download" aria-hidden="true">&nbsp;</span><?= lang('Accounting_opening_import.export_report') ?></a>
    </div>

    <section class="mt-3" id="opening_import_rows_section" aria-label="<?= esc(lang('Accounting_opening_import.summary_rows'), 'attr') ?>">
        <p class="print_hide">
            <?php foreach ([null => 'all', 'error' => 'error', 'pending' => 'pending', 'posted' => 'posted'] as $value => $key): ?>
                <a class="btn btn-sm <?= $state === $value ? 'btn-secondary' : 'btn-outline-secondary' ?>" href="<?= esc($urlOf($value === null ? [] : ['state' => $value]), 'attr') ?>"><?= lang('Accounting_opening_import.filter_' . $key) ?></a>
            <?php endforeach; ?>
        </p>

        <?php if ($rows === []): ?>
            <p class="text-body-secondary" id="opening_import_no_rows"><?= lang('Accounting_opening_import.no_rows') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm" id="opening_import_table">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_opening_import.col_line') ?></th>
                            <th scope="col"><?= lang('Accounting_opening_import.col_party') ?></th>
                            <th scope="col"><?= lang('Accounting_opening_import.col_name') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_opening_import.col_amount') ?></th>
                            <th scope="col"><?= lang('Accounting_opening_import.col_date') ?></th>
                            <th scope="col"><?= lang('Accounting_opening_import.col_state') ?></th>
                            <th scope="col"><?= lang('Accounting_opening_import.col_journal') ?></th>
                            <th scope="col"><?= lang('Accounting_opening_import.col_problems') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $row): ?>
                            <tr class="opening-import-<?= esc($row->state(), 'attr') ?>">
                                <td><?= $row->line ?></td>
                                <td><bdi dir="ltr"><?= esc($row->party) ?></bdi></td>
                                <td><?= $row->partyId === null ? '' : '<bdi dir="ltr">#' . $row->partyId . '</bdi> ' . esc($row->partyName) ?></td>
                                <td class="text-end"><?= $row->amount === null ? '' : $amountOf(($row->side === $positive ? '' : '-') . $row->amount) ?></td>
                                <td><bdi dir="ltr"><?= esc((string) $row->date) ?></bdi></td>
                                <td><span class="badge <?= $row->state() === 'error' ? 'text-bg-warning' : ($row->state() === 'posted' ? 'text-bg-success' : 'text-bg-secondary') ?>"><?= lang('Accounting_opening_import.state_' . $row->state()) ?></span></td>
                                <td><?= $row->debitAccountId === null ? '' : esc(($accounts[$row->debitAccountId] ?? '#' . $row->debitAccountId) . ' / ' . ($accounts[$row->creditAccountId] ?? '#' . $row->creditAccountId)) ?></td>
                                <td><?= esc(implode(' ', array_map(static fn (OpeningImportError $error): string => lang($error->languageKey()), $row->errors))) ?><?= $row->detail === '' ? '' : ' <bdi dir="ltr" class="text-body-secondary">' . esc($row->detail) . '</bdi>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1): ?>
                <nav class="print_hide" aria-label="<?= esc(lang('Accounting_opening_import.pages'), 'attr') ?>"><ul class="pagination pagination-sm">
                    <?php for ($number = 1; $number <= $pages; $number++): ?>
                        <li class="page-item<?= $number === $page ? ' active' : '' ?>"><a class="page-link" href="<?= esc($urlOf(['page' => $number] + ($state === null ? [] : ['state' => $state])), 'attr') ?>"><?= $number ?></a></li>
                    <?php endfor; ?>
                </ul></nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?= view('partial/footer') ?>
