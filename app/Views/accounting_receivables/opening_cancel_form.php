<?php
/**
 * @var int $opening_id
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_receivables/opening_cancel/' . $opening_id, ['id' => 'customer_opening_cancel_form', 'class' => 'app-form']) ?>
    <p class="small text-body-secondary"><?= lang('Accounting_receivables.opening_cancel_note') ?></p>

    <div class="field">
        <?= form_label(lang('Accounting_receivables.opening_reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
        <div class="field-control">
            <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
        </div>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#customer_opening_cancel_form').validate($.extend({
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
                reason: "<?= esc(lang('Accounting_receivables.opening_reason_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
