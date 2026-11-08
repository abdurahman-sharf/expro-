<?php
/**
 * @var object $finding
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_ai/decide/' . (int) $finding->finding_id, ['id' => 'ai_decide_form', 'class' => 'app-form']) ?>
    <p class="fw-bold"><?= esc((string) $finding->message) ?></p>

    <div class="form-grid">
        <div class="field span-all">
            <?= form_label(lang('Accounting_ai.decision'), 'decision', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <select name="decision" id="decision" class="form-select form-select-sm">
                    <option value="confirmed"><?= lang('Accounting_ai.finding_status_confirmed') ?></option>
                    <option value="dismissed"><?= lang('Accounting_ai.finding_status_dismissed') ?></option>
                </select>
            </div>
        </div>
        <div class="field span-all">
            <?= form_label(lang('Accounting_ai.decision_reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
            </div>
        </div>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#ai_decide_form').validate($.extend({
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

            messages: {reason: "<?= esc(lang('Accounting_ai.reason_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
