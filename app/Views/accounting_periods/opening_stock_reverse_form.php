<?php
/**
 * @var int $branch_id
 * @var int $location_id
 * @var string $location_name
 * @var string $start_date
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="stock_form_error_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_periods/opening/stock/reverse', ['id' => 'opening_stock_reverse_form', 'class' => 'app-form']) ?>
    <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
    <input type="hidden" name="location_id" value="<?= $location_id ?>">
    <fieldset>
        <p><strong><?= esc($location_name) ?></strong></p>
        <p><?= lang('Accounting_periods.opening_stock_reverse_warning') ?></p>

        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_journals.date'), 'date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="date" id="date" class="form-control form-control-sm" value="<?= esc(date('Y-m-d'), 'attr') ?>">
                </div>
            </div>

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
        $('#opening_stock_reverse_form').validate($.extend({}, form_support.error, {
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            window.location.href = response.redirect;
                            return;
                        }
                        $('#stock_form_error_box').empty().append($('<li></li>').text(response.message));
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#stock_form_error_box',

            rules: {date: 'required', reason: 'required'},

            messages: {
                date: "<?= esc(lang('Accounting_periods.date_required'), 'js') ?>",
                reason: "<?= esc(lang('Accounting_periods.reason_required'), 'js') ?>"
            }
        }));
    });
</script>
