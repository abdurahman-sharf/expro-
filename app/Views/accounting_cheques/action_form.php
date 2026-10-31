<?php
/**
 * @var object $cheque
 * @var string $action
 * @var string $today
 * @var bool   $asksFees
 * @var string $number
 */

use App\Libraries\Accounting\AmountText;

?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_cheques/act/' . $action . '/' . (int) $cheque->cheque_id, ['id' => 'cheque_action_form', 'class' => 'app-form']) ?>
    <p class="mb-2"><?= esc(lang('Accounting_cheques.action_' . $action)) ?>: <bdi dir="ltr"><?= esc($number) ?></bdi> · <bdi dir="ltr"><?= esc($cheque->cheque_number) ?></bdi> · <bdi dir="ltr"><?= esc(AmountText::forField((string) $cheque->amount)) ?></bdi></p>
    <fieldset>
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_cheques.action_date'), 'date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="date" id="date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($action === 'collect' && $cheque->due_date > $today ? substr((string) $cheque->due_date, 0, 10) : $today, 'attr') ?>">
                </div>
            </div>

            <?php if ($asksFees): ?>
                <div class="field">
                    <?= form_label(lang('Accounting_cheques.deposit_fees'), 'fees', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <input type="text" name="fees" id="fees" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="0.00">
                        <p class="form-text"><?= lang('Accounting_cheques.deposit_fees_note') ?></p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <?= form_label(lang('Accounting_cheques.note'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"></textarea>
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#cheque_action_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_cheques', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                date: 'required'<?= $asksFees ? ",\n                fees: 'required'" : '' ?>
            },

            messages: {
                date: "<?= esc(lang('Accounting_cheques.date_required'), 'js') ?>"<?= $asksFees ? ',
                fees: "' . esc(lang('Accounting_cheques.fees_required'), 'js') . '"' : '' ?>
            }
        }, form_support.error));
    });
</script>
