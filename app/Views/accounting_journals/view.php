<?php

use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\Money;

/**
 * @var object                                                                                $journal
 * @var list<array{time: string, action: string, employee: string, details: string}>          $audit
 * @var string                                                                                $currency
 * @var bool                                                                                  $can_draft
 * @var bool                                                                                  $can_post
 * @var bool                                                                                  $can_reverse
 * @var bool                                                                                  $canEditDetails
 * @var list<array{id: int, name: string, size: string, by: string, at: string, url: string}> $attachments
 * @var bool                                                                                  $canAttach
 * @var string|null                                                                           $notice
 * @var string                                                                                $status_badge
 * @var string                                                                                $source_label
 * @var string                                                                                $number_label
 * @var bool                                                                                  $show_branch
 */
$isDraft    = $journal->status === 'draft';
$isPosted   = $journal->status === 'posted';
$format     = static fn (?string $value): string => to_currency_no_money($value ?? '0');
$date       = static fn (?string $value): string => $value === null || strtotime($value) === false ? '' : to_date(strtotime($value));
$dateTime   = static fn (?string $value): string => $value === null || strtotime($value) === false ? '' : to_datetime(strtotime($value));
$jsonFlags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$hasForeign = array_filter($journal->lines, static fn (object $line): bool => ($line->foreign_amount ?? null) !== null) !== [];
$currencies = new Currencies();
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        const confirmations = <?= json_encode([
            'delete' => lang('Accounting_journals.confirm_delete_draft'),
            'post'   => lang('Accounting_journals.confirm_post'),
        ], $jsonFlags) ?>;

        $('#post_draft').on('click', function() {
            if (!confirm(confirmations.post)) {
                return;
            }
            $.post('accounting_journals/post_drafts', {'ids[]': [<?= (int) $journal->journal_id ?>]}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });

        $('#delete_draft').on('click', function() {
            if (!confirm(confirmations.delete)) {
                return;
            }
            $.post('accounting_journals/delete_draft/<?= (int) $journal->journal_id ?>', {}, function(response) {
                if (response.success) {
                    window.location.href = response.redirect;
                } else {
                    toast_support.show(response.message, 'danger');
                }
            }, 'json');
        });
    });
</script>

<div class="acc-journal-bar print_hide">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_journals'), 'attr') ?>"><span class="bi bi-arrow-<?= isRtl() ? 'right' : 'left' ?>" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.back') ?></a>
    <a class="btn btn-outline-secondary btn-sm" id="journal_print_link" href="<?= esc(base_url('accounting_journals/print/' . (int) $journal->journal_id), 'attr') ?>" target="_blank" rel="noopener"><span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?></a>
    <a class="btn btn-outline-secondary btn-sm" id="journal_pdf_link" href="<?= esc(base_url('accounting_journals/pdf/' . (int) $journal->journal_id), 'attr') ?>" target="_blank" rel="noopener"><span class="bi bi-file-earmark-pdf" aria-hidden="true">&nbsp;</span><?= lang('Sales.pdf') ?></a>
    <?php if ($isDraft && $can_draft): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_journals/edit/' . (int) $journal->journal_id), 'attr') ?>"><span class="bi bi-pencil-square" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.edit') ?></a>
    <?php endif; ?>
    <?php if ($isDraft && $can_post): ?>
        <button type="button" id="post_draft" class="btn btn-primary btn-sm"><span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.post') ?></button>
    <?php endif; ?>
    <?php if ($isDraft && $can_draft): ?>
        <button type="button" id="delete_draft" class="btn btn-outline-danger btn-sm"><span class="bi bi-trash" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.delete_draft') ?></button>
    <?php endif; ?>
    <?php if ($canEditDetails): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_journals/edit_details/' . (int) $journal->journal_id), 'attr') ?>"><span class="bi bi-pencil-square" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.edit_details') ?></a>
    <?php endif; ?>
    <?php if ($isPosted && $can_reverse && $journal->reverses_journal_id === null): ?>
        <button type="button" class="btn btn-outline-danger btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_journals.reverse'), 'attr') ?>" data-href="<?= esc('accounting_journals/reverse_form/' . (int) $journal->journal_id, 'attr') ?>" title="<?= esc(lang('Accounting_journals.reverse'), 'attr') ?>">
            <span class="bi bi-arrow-counterclockwise" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.reverse') ?>
        </button>
    <?php endif; ?>
</div>

<section class="acc-journal-view">
    <dl class="acc-summary">
        <div>
            <dt><?= lang('Accounting_journals.number') ?></dt>
            <dd><bdi dir="ltr"><?= $number_label === '' ? esc(lang('Accounting_journals.number_auto')) : esc($number_label) ?></bdi></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_journals.date') ?></dt>
            <dd><bdi dir="ltr"><?= esc($date($journal->journal_date)) ?></bdi></dd>
        </div>
        <?php if ($show_branch): ?>
            <div>
                <dt><?= lang('Accounting_journals.branch') ?></dt>
                <dd><bdi dir="ltr"><?= esc((string) $journal->branch_code) ?></bdi> <?= esc((string) $journal->branch_name) ?></dd>
            </div>
        <?php endif; ?>
        <div>
            <dt><?= lang('Accounting.status') ?></dt>
            <dd><?= $status_badge ?></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_journals.source') ?></dt>
            <dd><?= esc($source_label) ?></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_journals.currency') ?></dt>
            <dd><bdi dir="ltr"><?= esc($journal->currency_code ?? $currency) ?></bdi></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_journals.created_by') ?></dt>
            <dd><?= esc((string) $journal->created_by_name) ?> <bdi dir="ltr" class="text-body-secondary"><?= esc($dateTime($journal->created_at)) ?></bdi></dd>
        </div>
        <?php if ($journal->posted_at !== null): ?>
            <div>
                <dt><?= lang('Accounting_journals.posted_by') ?></dt>
                <dd><?= esc((string) $journal->posted_by_name) ?> <bdi dir="ltr" class="text-body-secondary"><?= esc($dateTime($journal->posted_at)) ?></bdi></dd>
            </div>
        <?php endif; ?>
        <?php if ($journal->reverses_journal_id !== null): ?>
            <div>
                <dt><?= lang('Accounting_journals.reverses') ?></dt>
                <dd><a href="<?= esc(base_url('accounting_journals/view/' . (int) $journal->reverses_journal_id), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $journal->reverses_number) ?></bdi></a></dd>
            </div>
        <?php endif; ?>
        <?php if ($journal->reversed_by_id !== null): ?>
            <div>
                <dt><?= lang('Accounting_journals.reversed_by') ?></dt>
                <dd><a href="<?= esc(base_url('accounting_journals/view/' . (int) $journal->reversed_by_id), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $journal->reversed_by_number) ?></bdi></a></dd>
            </div>
        <?php endif; ?>
    </dl>

    <?php if ((string) $journal->description !== ''): ?>
        <p class="acc-journal-description"><?= esc($journal->description) ?></p>
    <?php endif; ?>

    <div class="acc-lines-wrap">
        <table class="table acc-lines acc-lines-readonly">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_journals.account') ?></th>
                    <th scope="col"><?= lang('Accounting_journals.line_description') ?></th>
                    <th scope="col"><?= lang('Accounting_journals.cost_center') ?></th>
                    <th scope="col" class="acc-amount"><?= lang('Accounting_journals.debit') ?></th>
                    <th scope="col" class="acc-amount"><?= lang('Accounting_journals.credit') ?></th>
                    <?php if ($hasForeign): ?>
                        <th scope="col" class="acc-amount"><?= lang('Accounting_journals.foreign_amount') ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($journal->lines as $line): ?>
                    <tr>
                        <td data-label="<?= esc(lang('Accounting_journals.account'), 'attr') ?>"><bdi dir="ltr"><?= esc($line->account_code) ?></bdi> <?= esc($line->account_name) ?></td>
                        <td data-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>">
                            <?= esc((string) $line->description) ?>
                            <?php foreach ($line->analysis->tags as $tag): ?>
                                <span class="badge text-bg-light"><?= esc($tag) ?></span>
                            <?php endforeach; ?>
                            <?php if ($line->analysis->tax !== null): ?>
                                <span class="form-text d-block"><?= esc(lang('Accounting_journals.tax_note') . ': ' . $line->analysis->tax['code'] . ' ' . to_decimals($line->analysis->tax['percent']) . '%, ' . lang('Accounting_journals.tax_amount') . ' ' . $format($line->analysis->tax['amount'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td data-label="<?= esc(lang('Accounting_journals.cost_center'), 'attr') ?>">
                            <?php if ($line->analysis->distribution === []): ?>
                                <?= $line->cost_center_id === null ? '' : esc($line->cost_center_code . ' - ' . $line->cost_center_name) ?>
                            <?php else: ?>
                                <ul class="list-unstyled mb-0 small">
                                    <?php foreach ($line->analysis->distribution as $share): ?>
                                        <li><?= esc($centerLabels[$share['cost_center_id']] ?? (string) $share['cost_center_id']) ?>: <bdi dir="ltr"><?= esc(rtrim(rtrim($share['percent'], '0'), '.')) ?>%</bdi> (<bdi dir="ltr"><?= esc($format($share['amount'])) ?></bdi>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                        <td class="acc-amount" data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>"><bdi dir="ltr"><?= bccomp($line->debit, '0', 4) === 0 ? '' : esc($format($line->debit)) ?></bdi></td>
                        <td class="acc-amount" data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>"><bdi dir="ltr"><?= bccomp($line->credit, '0', 4) === 0 ? '' : esc($format($line->credit)) ?></bdi></td>
                        <?php if ($hasForeign): ?>
                            <td class="acc-amount" data-label="<?= esc(lang('Accounting_journals.foreign_amount'), 'attr') ?>"><bdi dir="ltr"><?= $line->foreign_amount === null ? '' : esc(Money::format((string) $line->foreign_amount, $currencies->decimalsOf((string) $line->currency_code), (string) $line->currency_code)) ?></bdi></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="3"><?= lang('Accounting_journals.totals') ?></th>
                    <td class="acc-amount"><bdi dir="ltr"><?= esc($format($journal->total_debit)) ?></bdi></td>
                    <td class="acc-amount"><bdi dir="ltr"><?= esc($format($journal->total_credit)) ?></bdi></td>
                    <?php if ($hasForeign): ?>
                        <td></td>
                    <?php endif; ?>
                </tr>
            </tfoot>
        </table>
    </div>

    <?= view('accounting_journals/attachments', ['journalId' => (int) $journal->journal_id, 'attachments' => $attachments, 'canAttach' => $canAttach]) ?>

    <?php if ($audit !== []): ?>
        <h2 class="acc-section-title"><?= lang('Accounting_journals.history') ?></h2>
        <ul class="acc-history">
            <?php foreach ($audit as $entry): ?>
                <li>
                    <bdi dir="ltr" class="text-body-secondary"><?= esc($entry['time']) ?></bdi>
                    <strong><?= esc($entry['action']) ?></strong>
                    <?= esc($entry['employee']) ?>
                    <?php if ($entry['details'] !== ''): ?>
                        <span class="text-body-secondary"><?= esc($entry['details']) ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<?= view('partial/footer') ?>
