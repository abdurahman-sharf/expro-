<?php
/**
 * @var object $operation
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_assets/dispose_reverse/' . (int) $operation->operation_id, ['id' => 'disposal_reverse_form', 'class' => 'app-form']) ?>
    <fieldset>
        <p><?= esc(lang('Accounting_assets.reverse_disposal_summary', [$operation->asset_code, $operation->operation_date])) ?></p>
        <div class="form-grid">
            <div class="field span-all">
                <label class="form-label text-danger required" for="reason"><?= lang('Accounting_assets.reverse_reason') ?></label>
                <div class="field-control"><input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255" autocomplete="off"></div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#disposal_reverse_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            window.location.href = response.redirect;

                            return;
                        }
                        $('#error_message_box').empty().append($('<li></li>').text(response.message));
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {reason: 'required'},

            messages: {reason: "<?= esc(lang('Accounting_assets.reverse_reason_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
