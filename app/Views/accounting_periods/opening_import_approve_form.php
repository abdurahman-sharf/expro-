<?php
/**
 * @var OpeningImportKind $kind
 * @var string            $token
 * @var string            $fingerprint
 * @var int               $count
 */

use App\Libraries\Accounting\OpeningImport\OpeningImportKind;

?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_periods/opening_import/approve', ['id' => 'opening_import_approve_form', 'class' => 'app-form']) ?>
    <input type="hidden" name="kind" value="<?= esc($kind->value, 'attr') ?>">
    <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">
    <input type="hidden" name="fingerprint" value="<?= esc($fingerprint, 'attr') ?>">
    <fieldset>
        <p><?= esc(lang('Accounting_opening_import.approve_summary', [(string) $count, lang('Accounting_opening_import.kind_' . $kind->value)])) ?></p>
        <p class="text-danger"><?= lang('Accounting_opening_import.approve_warning') ?></p>
        <p class="text-body-secondary small"><?= lang('Accounting_opening_import.approve_steps') ?></p>

        <div class="form-check">
            <input type="checkbox" class="form-check-input" name="confirm" id="confirm" value="1">
            <label class="form-check-label" for="confirm"><?= lang('Accounting_opening_import.approve_confirm') ?></label>
        </div>

        <p id="approve_progress" class="mt-3 mb-0 fw-semibold" role="status" aria-live="polite"></p>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#opening_import_approve_form').validate($.extend({
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
                            $('#error_message_box').empty().append($('<li></li>').text("<?= esc(lang('Accounting_opening_import.approve_interrupted'), 'js') ?>"));
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
