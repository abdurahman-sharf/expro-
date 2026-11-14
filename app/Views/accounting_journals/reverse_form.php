<?php
/**
 * @var object $journal
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<p><?= lang('Accounting_journals.reverse_hint', [esc($journal->number)]) ?></p>

<?= form_open('accounting_journals/reverse/' . (int) $journal->journal_id, ['id' => 'reverse_form', 'class' => 'app-form']) ?>
    <fieldset id="reverse_fields">
        <div class="field">
            <?= form_label(lang('Accounting_journals.reverse_date'), 'reverse_date', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="date" name="date" id="reverse_date" class="form-control form-control-sm" value="<?= esc(date('Y-m-d'), 'attr') ?>" required>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Accounting_journals.reverse_reason'), 'reverse_reason', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <textarea name="reason" id="reverse_reason" class="form-control form-control-sm" rows="3" maxlength="255"></textarea>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#reverse_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            window.location.href = response.redirect;
                            return;
                        }
                        $('#error_message_box').empty().append($('<li></li>').text(response.message));
                        toast_support.show(response.message, 'danger');
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                date: 'required',
                reason: 'required'
            },

            messages: {
                date: "<?= esc(lang('Accounting_journals.reverse_date_required'), 'js') ?>",
                reason: "<?= esc(lang('Accounting.error_reason_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
