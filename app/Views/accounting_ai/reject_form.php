<?php
/**
 * @var object $proposal
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_ai/reject/' . (int) $proposal->proposal_id, ['id' => 'ai_reject_form', 'class' => 'app-form']) ?>
    <p class="fw-bold"><?= esc((string) $proposal->title_text) ?></p>
    <p class="small text-body-secondary"><?= lang('Accounting_ai.reject_note') ?></p>

    <div class="form-grid">
        <div class="field span-all">
            <?= form_label(lang('Accounting_ai.reject_reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
            </div>
        </div>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#ai_reject_form').validate($.extend({
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
