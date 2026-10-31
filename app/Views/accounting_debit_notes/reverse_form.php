<?php
/**
 * @var int    $debit_note_id
 * @var string $number
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_debit_notes/reverse/' . $debit_note_id, ['id' => 'debit_note_reverse_form', 'class' => 'app-form']) ?>
    <p class="fw-bold"><bdi dir="ltr"><?= esc($number) ?></bdi></p>
    <p class="small text-body-secondary"><?= lang('Accounting_debit_notes.reverse_note') ?></p>

    <div class="form-grid">
        <div class="field span-all">
            <?= form_label(lang('Accounting_debit_notes.reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
            </div>
        </div>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#debit_note_reverse_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        toast_support.show(response.message, response.success ? 'success' : 'danger');
                        if (response.success) {
                            window.location.reload();
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                reason: 'required'
            },

            messages: {
                reason: "<?= esc(lang('Accounting_debit_notes.reason_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
