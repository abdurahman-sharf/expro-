<?php
/**
 * @var BackfillBatch $batch
 * @var int           $documents
 * @var int           $journals
 * @var bool          $approving
 */

use App\Libraries\Accounting\Backfill\BackfillBatch;

?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_periods/backfill/cancel/' . $batch->id, ['id' => 'backfill_cancel_form', 'class' => 'app-form']) ?>

    <fieldset>
        <p><?= esc(lang('Accounting_backfill.cancel_summary', [$batch->name, (string) $documents, (string) $journals])) ?></p>
        <p class="text-danger"><?= lang($approving ? 'Accounting_backfill.cancel_warning_approving' : 'Accounting_backfill.cancel_warning') ?></p>

        <div class="form-check">
            <input type="checkbox" class="form-check-input" name="confirm" id="confirm" value="1">
            <label class="form-check-label" for="confirm"><?= lang('Accounting_backfill.cancel_confirm') ?></label>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#backfill_cancel_form').validate($.extend({
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

            rules: {confirm: 'required'},

            messages: {confirm: "<?= esc(lang('Accounting_backfill.confirm_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
