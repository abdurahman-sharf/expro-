<?php
/**
 * @var object $transfer
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_treasuries/cancel_transfer/' . (int) $transfer->transfer_id, ['id' => 'cancel_transfer_form', 'class' => 'app-form']) ?>
    <p>
        <?= esc($transfer->from_name) ?> &rarr; <?= esc($transfer->to_name) ?>:
        <bdi dir="ltr"><?= esc(to_currency_no_money((string) $transfer->amount)) ?></bdi>
    </p>

    <div class="field">
        <?= form_label(lang('Accounting_treasuries.cancel_reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
        <div class="field-control">
            <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
        </div>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#cancel_transfer_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_treasuries/transfers', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                reason: 'required'
            },

            messages: {
                reason: "<?= esc(lang('Accounting_treasuries.cancel_reason_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
