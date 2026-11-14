<?php
/**
 * @var BackfillBatch $batch
 * @var string        $fingerprint
 */

use App\Libraries\Accounting\Backfill\BackfillBatch;

?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_periods/backfill/approve/' . $batch->id, ['id' => 'backfill_approve_form', 'class' => 'app-form']) ?>
    <input type="hidden" name="fingerprint" value="<?= esc($fingerprint, 'attr') ?>">
    <fieldset>
        <p><?= esc(lang('Accounting_backfill.approve_summary', [$batch->name, (string) $batch->readyCount, (string) $batch->journalCount])) ?></p>
        <p class="text-danger"><?= lang('Accounting_backfill.approve_warning') ?></p>
        <p class="text-body-secondary small"><?= lang('Accounting_backfill.approve_steps_note') ?></p>

        <div class="form-check">
            <input type="checkbox" class="form-check-input" name="confirm" id="confirm" value="1">
            <label class="form-check-label" for="confirm"><?= lang('Accounting_backfill.approve_confirm') ?></label>
        </div>

        <p id="approve_progress" class="mt-3 mb-0 fw-semibold" role="status" aria-live="polite"></p>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#backfill_approve_form').validate($.extend({
            submitHandler: function(form) {
                const send = function() {
                    $(form).ajaxSubmit({
                        success: function(response) {
                            if (response.success && response.continue) {
                                $('#approve_progress').text(response.message);
                                send();
                                return;
                            }
                            if (response.success) {
                                dialog_support.hide();
                                window.location.href = response.redirect;
                                return;
                            }
                            $('#error_message_box').empty().append($('<li></li>').text(response.message));
                        },
                        error: function() {
                            $('#error_message_box').empty().append($('<li></li>').text("<?= esc(lang('Accounting_backfill.approve_interrupted'), 'js') ?>"));
                        },
                        dataType: 'json'
                    });
                };

                send();
            },

            errorLabelContainer: '#error_message_box',

            rules: {confirm: 'required'},

            messages: {confirm: "<?= esc(lang('Accounting_backfill.confirm_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
