<?php
/**
 * @var list<object> $from_treasuries
 * @var list<object> $to_treasuries
 * @var string       $today
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_treasuries/transfer', ['id' => 'transfer_form', 'class' => 'app-form']) ?>
    <fieldset id="transfer_fields">
        <div class="form-grid">
            <?php foreach (['from_treasury_id' => ['from_treasury', $from_treasuries], 'to_treasury_id' => ['to_treasury', $to_treasuries]] as $name => [$label, $treasuries]): ?>
                <div class="field">
                    <?= form_label(lang('Accounting_treasuries.' . $label), $name, ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <select name="<?= $name ?>" id="<?= $name ?>" class="form-select form-select-sm">
                            <option value=""></option>
                            <?php foreach ($treasuries as $treasury): ?>
                                <option value="<?= (int) $treasury->treasury_id ?>"><?= esc($treasury->name . ' (' . $treasury->branch_code . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.amount'), 'amount', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.date'), 'transfer_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="transfer_date" id="transfer_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($today, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.note_label'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $.validator.addMethod('differentTreasury', function(value) {
            return value === '' || value !== $('#from_treasury_id').val();
        });

        $('#transfer_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_treasuries/transfers', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                from_treasury_id: 'required',
                to_treasury_id: {required: true, differentTreasury: true},
                amount: 'required',
                transfer_date: 'required'
            },

            messages: {
                from_treasury_id: "<?= esc(lang('Accounting_treasuries.different_required'), 'js') ?>",
                to_treasury_id: {
                    required: "<?= esc(lang('Accounting_treasuries.different_required'), 'js') ?>",
                    differentTreasury: "<?= esc(lang('Accounting_treasuries.different_required'), 'js') ?>"
                },
                amount: "<?= esc(lang('Accounting_treasuries.amount_required'), 'js') ?>",
                transfer_date: "<?= esc(lang('Accounting_treasuries.date_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
