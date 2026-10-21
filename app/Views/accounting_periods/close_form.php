<?php
/**
 * @var array<string|int, string> $scopes
 * @var string $scope
 * @var string $previous
 * @var string $suggested
 * @var string $max_date
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_periods/closings/close', ['id' => 'close_form', 'class' => 'app-form']) ?>
    <fieldset>
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_periods.scope'), 'close_scope', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="scope" id="close_scope" class="form-select form-select-sm">
                        <?php foreach ($scopes as $key => $label): ?>
                            <option value="<?= esc((string) $key, 'attr') ?>"<?= (string) $key === $scope ? ' selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_periods.close_through'), 'close_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="date" id="close_date" class="form-control form-control-sm" max="<?= esc($max_date, 'attr') ?>" value="<?= esc($suggested, 'attr') ?>">
                </div>
                <p class="form-text" id="previous_note"><?= $previous === '' ? '' : esc(lang('Accounting_periods.previous_closing', [$previous])) ?></p>
            </div>

            <div class="field span-all">
                <?= form_label(lang('Accounting_periods.note'), 'close_note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="note" id="close_note" class="form-control form-control-sm" maxlength="255">
                </div>
            </div>

            <div class="field span-all" id="preview_box" aria-live="polite"></div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        const text = <?= json_encode([
            'journals' => lang('Accounting_periods.preview_journals'),
            'drafts' => lang('Accounting_periods.preview_drafts'),
            'none' => lang('Accounting_periods.preview_none'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        const $box = $('#preview_box');

        const refreshPreview = function() {
            const date = $('#close_date').val();

            $box.empty();

            if (!date) {
                return;
            }

            $.getJSON('accounting_periods/closings/preview', {scope: $('#close_scope').val(), date: date}, function(response) {
                if (!response.success) {
                    $box.append($('<p class="text-danger"></p>').text(response.message));
                    return;
                }

                $box.append($('<p></p>').text(text.journals.replace('{0}', response.journals).replace('{1}', response.total_debit)));

                if (response.draft_count > 0) {
                    const $list = $('<ul class="mb-0"></ul>');

                    response.drafts.forEach(function(draft) {
                        const $item = $('<li></li>');
                        $item.append($('<a target="_blank" rel="noopener"></a>').attr('href', draft.url).text('#' + draft.id));
                        $item.append(document.createTextNode(' ' + draft.date + ' ' + draft.branch + ' ' + draft.description));
                        $list.append($item);
                    });

                    $box.append($('<p class="text-danger mb-1"></p>').text(text.drafts.replace('{0}', response.draft_count)));
                    $box.append($list);
                }
            });
        };

        $('#close_date, #close_scope').on('change', refreshPreview);
        refreshPreview();

        $('#close_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            window.location.reload();
                            return;
                        }
                        $('#error_message_box').empty().append($('<li></li>').text(response.message));
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {date: 'required'},

            messages: {date: "<?= esc(lang('Accounting_periods.date_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
