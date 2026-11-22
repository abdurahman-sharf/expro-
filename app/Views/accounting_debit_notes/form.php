<?php
/**
 * @var list<object> $accounts
 * @var string|null  $number
 * @var string       $today
 * @var bool         $issuing
 * @var list<string> $reasons
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_debit_notes/save', ['id' => 'debit_note_form', 'class' => 'app-form']) ?>
    <p class="small text-body-secondary"><?= lang('Accounting_debit_notes.form_note') ?></p>

    <fieldset id="debit_note_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_debit_notes.number'), 'debit_note_number', ['class' => 'form-label']) ?>
                <div class="field-control"><span class="form-control-plaintext" id="debit_note_number"><bdi dir="ltr"><?= esc($number ?? lang('Accounting_debit_notes.number_next')) ?></bdi></span></div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_debit_notes.customer'), 'customer_name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" id="customer_name" class="form-control form-control-sm" autocomplete="off" value="">
                    <input type="hidden" name="customer_id" id="customer_id" value="">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_debit_notes.date'), 'note_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="note_date" id="note_date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($today, 'attr') ?>" value="<?= esc($today, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_debit_notes.amount'), 'amount', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_debit_notes.tax_percent'), 'tax_percent', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="tax_percent" id="tax_percent" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="0">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_debit_notes.account'), 'account_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="account_id" id="account_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_debit_notes.account_routed') ?></option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= (int) $account->account_id ?>"><?= esc($account->code . ' ' . $account->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if ($issuing): ?>
                <div class="field">
                    <?= form_label(lang('Accounting_debit_notes.original_document'), 'original_document', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <input type="text" name="original_document" id="original_document" class="form-control form-control-sm ltr-input" dir="ltr" autocomplete="off" maxlength="127" value="">
                        <div class="form-text"><?= lang('Accounting_debit_notes.original_document_help') ?></div>
                    </div>
                </div>

                <div class="field">
                    <?= form_label(lang('Accounting_debit_notes.note_reason'), 'note_reason', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <select name="note_reason" id="note_reason" class="form-select form-select-sm" dir="ltr">
                            <?php foreach ($reasons as $index => $reason): ?>
                                <option value="<?= esc($reason, 'attr') ?>"<?= $index === 2 ? ' selected' : '' ?>><?= esc($reason) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field span-all">
                <?= form_label(lang('Accounting_debit_notes.note'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#customer_name').autocomplete({
            source: 'accounting_debit_notes/customers',
            minChars: 0,
            delay: 100,
            appendTo: '.modal-content',
            select: function(event, ui) {
                $('#customer_id').val(ui.item.value);
                $('#customer_name').val(ui.item.label);

                return false;
            },
            focus: function(event, ui) {
                $('#customer_name').val(ui.item.label);

                return false;
            }
        }).on('input', function() {
            $('#customer_id').val('');
        }).on('change', function() {
            if ($('#customer_id').val() === '') {
                $(this).val('');
            }
        });

        $('#debit_note_form').validate($.extend({
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
                customer_name: 'required',
                note_date: 'required',
                amount: 'required'<?= $issuing ? ",\n                original_document: 'required'" : '' ?>
            },

            messages: {
                customer_name: "<?= esc(lang('Accounting_debit_notes.customer_required'), 'js') ?>",
                note_date: "<?= esc(lang('Accounting_debit_notes.date_required'), 'js') ?>",
                amount: "<?= esc(lang('Accounting_debit_notes.amount_required'), 'js') ?>",
                original_document: "<?= esc(lang('Accounting_debit_notes.original_document_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
