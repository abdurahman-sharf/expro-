<?php
/**
 * @var object|null           $voucher
 * @var ?string               $number
 * @var list<object>          $categories
 * @var list<object>          $accounts
 * @var list<object>          $treasuries
 * @var array<string, string> $payment_options
 * @var string                $today
 */

$isNew = $voucher === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('revenues/save/' . ($isNew ? NEW_ENTRY : (int) $voucher->revenue_id), ['id' => 'revenue_form', 'class' => 'app-form']) ?>
    <fieldset id="revenue_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Revenues.number'), 'revenue_number', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <span class="form-control-plaintext" id="revenue_number"><bdi dir="ltr"><?= esc($number ?? lang('Revenues.number_automatic')) ?></bdi></span>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Revenues.date'), 'date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="date" id="date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= $isNew ? esc($today, 'attr') : esc(substr((string) $voucher->date, 0, 10), 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Revenues.amount'), 'amount', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= $isNew ? '' : esc(bcadd((string) $voucher->amount, '0', 2), 'attr') ?>">
                    <p class="form-text"><?= lang('Revenues.amount_note') ?></p>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Revenues.tax_amount'), 'tax_amount', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="tax_amount" id="tax_amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= $isNew ? '0.00' : esc(bcadd((string) $voucher->tax_amount, '0', 2), 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Revenues.payment_type'), 'payment_type', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <select name="payment_type" id="payment_type" class="form-select form-select-sm">
                        <?php foreach ($payment_options as $label): ?>
                            <option value="<?= esc($label, 'attr') ?>"<?= ! $isNew && $voucher->payment_type === $label ? ' selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-text"><?= lang('Revenues.payment_type_note') ?></p>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Revenues.treasury'), 'treasury_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="treasury_id" id="treasury_id" class="form-select form-select-sm">
                        <option value="0"><?= lang('Revenues.treasury_default') ?></option>
                        <?php foreach ($treasuries as $treasury): ?>
                            <option value="<?= (int) $treasury->treasury_id ?>"<?= ! $isNew && (int) $voucher->treasury_id === (int) $treasury->treasury_id ? ' selected' : '' ?>><?= esc($treasury->name . ' (' . $treasury->branch_code . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Revenues.category'), 'category_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="category_id" id="category_id" class="form-select form-select-sm">
                        <option value="0"><?= lang('Revenues.category_none') ?></option>
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= (int) $category->revenue_category_id ?>"<?= ! $isNew && (int) $voucher->revenue_category_id === (int) $category->revenue_category_id ? ' selected' : '' ?>><?= esc($category->category_name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Revenues.account'), 'account_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="account_id" id="account_id" class="form-select form-select-sm">
                        <option value="0"><?= lang('Revenues.account_automatic') ?></option>
                        <?php foreach ($accounts as $account): ?>
                            <option value="<?= (int) $account->account_id ?>"<?= ! $isNew && (int) $voucher->account_id === (int) $account->account_id ? ' selected' : '' ?>><?= esc($account->code . ' - ' . $account->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-text"><?= lang('Revenues.account_note') ?></p>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Revenues.description'), 'description', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="description" id="description" class="form-control form-control-sm" rows="2" maxlength="500"><?= $isNew ? '' : esc((string) $voucher->description) ?></textarea>
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#revenue_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('revenues', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                date: 'required',
                amount: 'required',
                payment_type: 'required'
            },

            messages: {
                date: "<?= esc(lang('Revenues.date_required'), 'js') ?>",
                amount: "<?= esc(lang('Revenues.amount_required'), 'js') ?>",
                payment_type: "<?= esc(lang('Revenues.payment_type_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
