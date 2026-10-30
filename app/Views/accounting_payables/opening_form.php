<?php
/**
 * @var int    $supplier_id
 * @var string $supplier_name
 * @var string $today
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_payables/opening/' . $supplier_id, ['id' => 'supplier_opening_form', 'class' => 'app-form']) ?>
    <p class="fw-bold"><?= esc($supplier_name) ?></p>
    <p class="small text-body-secondary"><?= lang('Accounting_payables.opening_note') ?></p>

    <fieldset id="supplier_opening_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_payables.opening_side'), 'side', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <?= form_dropdown('side', ['we_owe' => lang('Accounting_payables.opening_we_owe'), 'they_owe' => lang('Accounting_payables.opening_they_owe')], 'we_owe', ['id' => 'side', 'class' => 'form-select form-select-sm']) ?>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_payables.amount'), 'amount', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_payables.date'), 'advance_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="advance_date" id="advance_date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($today, 'attr') ?>" value="<?= esc($today, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_payables.note_label'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#supplier_opening_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        toast_support.show(response.message, response.success ? 'success' : 'danger');
                        if (response.success) {
                            window.location.reload();
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                amount: 'required',
                advance_date: 'required'
            },

            messages: {
                amount: "<?= esc(lang('Accounting_payables.amount_required'), 'js') ?>",
                advance_date: "<?= esc(lang('Accounting_payables.date_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
