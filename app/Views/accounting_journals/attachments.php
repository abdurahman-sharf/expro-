<?php
/**
 * @var int $journal_id Zero while the journal is not saved yet.
 * @var list<array{id: int, name: string, size: string, by: string, at: string, url: string}> $attachments
 * @var bool $can_attach
 */

use App\Libraries\Accounting\JournalAttachments;

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$accept    = implode(',', array_map(static fn (string $extension): string => '.' . $extension, JournalAttachments::extensions()));
$maxMb     = JournalAttachments::MAX_BYTES / 1048576;
?>

<section class="acc-attachments print_hide" aria-labelledby="attachments_title">
    <h2 id="attachments_title" class="acc-section-title"><?= lang('Accounting_journals.attachments') ?></h2>

    <?php if ($attachments === []): ?>
        <p class="text-body-secondary small" id="attachments_empty"><?= lang('Accounting_journals.attachments_none') ?></p>
    <?php else: ?>
        <ul class="list-unstyled" id="attachments_list">
            <?php foreach ($attachments as $file): ?>
                <li class="d-flex flex-wrap align-items-center gap-2 py-1">
                    <span class="bi bi-paperclip" aria-hidden="true"></span>
                    <a href="<?= esc($file['url'], 'attr') ?>"><bdi><?= esc($file['name']) ?></bdi></a>
                    <span class="text-body-secondary small"><bdi dir="ltr"><?= esc($file['size']) ?></bdi> · <?= esc($file['by']) ?> · <bdi dir="ltr"><?= esc($file['at']) ?></bdi></span>
                    <?php if ($can_attach): ?>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-delete-attachment="<?= (int) $file['id'] ?>" title="<?= esc(lang('Accounting_journals.attachment_delete'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.attachment_delete'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ($journal_id === 0): ?>
        <p class="form-text" role="note"><?= lang('Accounting_journals.attachments_save_first') ?></p>
    <?php elseif ($can_attach): ?>
        <form id="attachment_form" class="d-flex flex-wrap align-items-end gap-2" enctype="multipart/form-data" novalidate>
            <div>
                <label class="form-label" for="attachment_file"><?= lang('Accounting_journals.attachment_file') ?></label>
                <input type="file" id="attachment_file" name="file" class="form-control form-control-sm" accept="<?= esc($accept, 'attr') ?>">
            </div>
            <button type="submit" class="btn btn-outline-primary btn-sm"><span class="bi bi-upload" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.attachment_upload') ?></button>
        </form>
        <p class="form-text"><?= lang('Accounting_journals.attachments_limits', [(string) $maxMb, (string) JournalAttachments::MAX_PER_JOURNAL, implode(', ', JournalAttachments::extensions())]) ?></p>

        <script type="text/javascript">
            $(document).ready(function() {
                const journalId = <?= (int) $journal_id ?>;
                const text = <?= json_encode([
                    'confirm' => lang('Accounting_journals.attachment_confirm_delete'),
                    'failed'  => lang('Accounting_journals.request_failed'),
                ], $jsonFlags) ?>;
                const tokenName = <?= json_encode(config('Security')->tokenName, $jsonFlags) ?>;

                const done = function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.reload();
                    }
                };

                $('#attachment_form').on('submit', function(event) {
                    event.preventDefault();
                    const input = document.getElementById('attachment_file');

                    if (!input.files.length) {
                        return;
                    }

                    const data = new FormData();
                    data.append('file', input.files[0]);
                    data.append(tokenName, csrf_token());

                    $.ajax({url: 'accounting_journals/attach/' + journalId, method: 'POST', data: data, processData: false, contentType: false, dataType: 'json'})
                        .done(done)
                        .fail(function() {
                            toast_support.show(text.failed, 'danger');
                        });
                });

                $('[data-delete-attachment]').on('click', function() {
                    if (confirm(text.confirm)) {
                        $.post('accounting_journals/delete_attachment/' + $(this).data('delete-attachment'), {}, done, 'json');
                    }
                });
            });
        </script>
    <?php endif; ?>
</section>
