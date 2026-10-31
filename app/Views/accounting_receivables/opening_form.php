<?php
/**
 * @var string      $action
 * @var string      $customer_name
 * @var object|null $opening
 * @var string      $today
 */
$editing = $opening !== null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open($action, ['id' => 'customer_opening_form', 'class' => 'app-form']) ?>
    <p class="fw-bold"><?= esc($customer_name) ?></p>
    <p class="small text-body-secondary"><?= lang($editing ? 'Accounting_receivables.opening_edit_note' : 'Accounting_receivables.opening_note') ?></p>

    <fieldset id="customer_opening_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_receivables.opening_side'), 'side', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <?= form_dropdown('side', ['owes_us' => lang('Accounting_receivables.opening_owes_us'), 'we_owe' => lang('Accounting_receivables.opening_we_owe')], $editing ? $opening->side : 'owes_us', ['id' => 'side', 'class' => 'form-select form-select-sm']) ?>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_receivables.amount'), 'amount', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= $editing ? esc(rtrim(rtrim((string) $opening->amount, '0'), '.'), 'attr') : '' ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_receivables.opening_date'), 'opening_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="opening_date" id="opening_date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($today, 'attr') ?>" value="<?= esc($editing ? (string) $opening->opening_date : $today, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_receivables.note_label'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"><?= $editing ? esc((string) $opening->note) : '' ?></textarea>
                </div>
            </div>

            <?php if ($editing): ?>
                <div class="field span-all">
                    <?= form_label(lang('Accounting_receivables.opening_reason'), 'reason', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <input type="text" name="reason" id="reason" class="form-control form-control-sm" maxlength="255">
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#customer_opening_form').validate($.extend({
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
                opening_date: 'required'<?= $editing ? ",\n                reason: 'required'" : '' ?>
            },

            messages: {
                amount: "<?= esc(lang('Accounting_receivables.amount_required'), 'js') ?>",
                opening_date: "<?= esc(lang('Accounting_receivables.date_required'), 'js') ?>"<?= $editing ? ",\n                reason: \"" . esc(lang('Accounting_receivables.opening_reason_required'), 'js') . '"' : '' ?>
            }
        }, form_support.error));
    });
</script>
