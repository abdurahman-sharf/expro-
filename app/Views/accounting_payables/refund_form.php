<?php
/**
 * @var int                  $supplier_id
 * @var string               $supplier_name
 * @var array<string,string> $payment_options
 * @var array<int,string>    $treasuries
 * @var string               $today
 * @var string               $request_key
 * @var string               $free
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_payables/refund/' . $supplier_id, ['id' => 'supplier_refund_form', 'class' => 'app-form']) ?>
    <input type="hidden" name="request_key" value="<?= esc($request_key, 'attr') ?>">
    <p class="fw-bold"><?= esc($supplier_name) ?></p>
    <p class="small text-body-secondary"><?= lang('Accounting_payables.refund_note') ?></p>
    <p class="small"><?= lang('Accounting_payables.credit_free') ?> <strong id="refund_free"><?= $free ?></strong></p>

    <fieldset id="supplier_refund_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_payables.amount'), 'amount', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_payables.date'), 'refund_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="refund_date" id="refund_date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($today, 'attr') ?>" value="<?= esc($today, 'attr') ?>" aria-describedby="refund_date_note">
                    <p class="form-text mb-0" id="refund_date_note"><?= lang('Accounting_payables.refund_date_note') ?></p>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_payables.payment_type'), 'payment_type', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <?= form_dropdown('payment_type', $payment_options, '', ['id' => 'payment_type', 'class' => 'form-select form-select-sm']) ?>
                </div>
            </div>

            <?php if ($treasuries !== []): ?>
                <div class="field">
                    <?= form_label(lang('Accounting_payables.treasury'), 'treasury_id', ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <?= form_dropdown('treasury_id', $treasuries, '0', ['id' => 'treasury_id', 'class' => 'form-select form-select-sm']) ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <?= form_label(lang('Accounting_payables.reference'), 'reference_code', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="reference_code" id="reference_code" class="form-control form-control-sm" maxlength="64">
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
        $('#supplier_refund_form').validate($.extend({
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
                refund_date: 'required'
            },

            messages: {
                amount: "<?= esc(lang('Accounting_payables.amount_required'), 'js') ?>",
                refund_date: "<?= esc(lang('Accounting_payables.date_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
