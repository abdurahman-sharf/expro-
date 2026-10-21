<?php
/**
 * @var string $scope
 * @var string $cutoff
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_periods/closings/reopen', ['id' => 'reopen_form', 'class' => 'app-form']) ?>
    <input type="hidden" name="scope" value="<?= esc($scope, 'attr') ?>">
    <fieldset>
        <p><?= $cutoff === '' ? esc(lang('Accounting_periods.nothing_closed')) : esc(lang('Accounting_periods.reopen_warning', [$cutoff])) ?></p>

        <div class="form-grid">
            <div class="field span-all">
                <?= form_label(lang('Accounting_periods.reopen_reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#reopen_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            window.location.reload();
                            return;
                        }
                        $('#error_message_box').empty().append($('<li></li>').text(response.message));
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {reason: 'required'},

            messages: {reason: "<?= esc(lang('Accounting_periods.reason_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
