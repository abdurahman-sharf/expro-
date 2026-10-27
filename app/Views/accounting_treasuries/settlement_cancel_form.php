<?php
/**
 * @var object $settlement
 * @var string $amount
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_treasuries/inter_branch/cancel/' . (int) $settlement->settlement_id, ['id' => 'cancel_settlement_form', 'class' => 'app-form']) ?>
    <p>
        <bdi dir="ltr"><?= esc($settlement->branch_code) ?></bdi> &harr; <bdi dir="ltr"><?= esc($settlement->counterpart_code) ?></bdi>
        <span class="text-body-secondary">&middot; <?= esc(lang('Accounting_treasuries.settlement_kind_' . $settlement->kind)) ?> <bdi dir="ltr"><?= esc($amount) ?></bdi></span>
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
        $('#cancel_settlement_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            window.reloadPairs();
                        }

                        table_support.handle_submit('accounting_treasuries/inter_branch/settlements', response);
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
