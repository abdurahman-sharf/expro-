<?php
/**
 * @var int                                                                                   $journal_id Zero while the journal is not saved yet.
 * @var list<array{id: int, name: string, size: string, by: string, at: string, url: string}> $attachments
 * @var bool                                                                                  $can_attach
 */

use App\Libraries\Accounting\JournalAttachments;

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$accept    = implode(',', array_map(static fn (string $extension): string => '.' . $extension, JournalAttachments::extensions()));
$maxMb     = JournalAttachments::MAX_BYTES / 1048576;
?>

<section class="acc-attachments print_hide" aria-labelledby="attachments_title">
    <h2 id="attachments_title" class="acc-section-title"><?= lang('Accounting_journals.attachments') ?></h2>

    <p class="text-body-secondary small<?= $attachments === [] ? '' : ' d-none' ?>" id="attachments_empty"><?= lang('Accounting_journals.attachments_none') ?></p>
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
                    'delete'  => lang('Accounting_journals.attachment_delete'),
                    'failed'  => lang('Accounting_journals.request_failed'),
                ], $jsonFlags) ?>;
                const tokenName = <?= json_encode(config('Security')->tokenName, $jsonFlags) ?>;
                const $list = $('#attachments_list');
                const $empty = $('#attachments_empty');

                const refreshEmpty = function() {
                    $empty.toggleClass('d-none', $list.children().length > 0);
                };

                const item = function(file) {
                    const $meta = $('<span class="text-body-secondary small"></span>')
                        .append($('<bdi dir="ltr"></bdi>').text(file.size))
                        .append(document.createTextNode(' · ' + file.by + ' · '))
                        .append($('<bdi dir="ltr"></bdi>').text(file.at));

                    return $('<li class="d-flex flex-wrap align-items-center gap-2 py-1"></li>')
                        .append('<span class="bi bi-paperclip" aria-hidden="true"></span>')
                        .append($('<a></a>').attr('href', file.url).append($('<bdi></bdi>').text(file.name)))
                        .append($meta)
                        .append($('<button type="button" class="btn btn-outline-danger btn-sm"></button>')
                            .attr({'data-delete-attachment': file.id, 'title': text.delete, 'aria-label': text.delete})
                            .append('<span class="bi bi-trash" aria-hidden="true"></span>'));
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
                        .done(function(response) {
                            toast_support.show(response.message, response.success ? 'success' : 'danger');

                            if (response.success && response.file) {
                                $list.append(item(response.file));
                                input.value = '';
                                refreshEmpty();
                            }
                        })
                        .fail(function() {
                            toast_support.show(text.failed, 'danger');
                        });
                });

                $list.on('click', '[data-delete-attachment]', function() {
                    const $row = $(this).closest('li');

                    if (!confirm(text.confirm)) {
                        return;
                    }

                    $.post('accounting_journals/delete_attachment/' + $(this).data('delete-attachment'), {}, function(response) {
                        toast_support.show(response.message, response.success ? 'success' : 'danger');

                        if (response.success) {
                            $row.remove();
                            refreshEmpty();
                        }
                    }, 'json').fail(function() {
                        toast_support.show(text.failed, 'danger');
                    });
                });
            });
        </script>
    <?php endif; ?>
</section>
