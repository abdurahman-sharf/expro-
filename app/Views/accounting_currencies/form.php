<?php

use App\Libraries\Accounting\Currency;
use App\Libraries\Accounting\CurrencyCatalog;

/**
 * @var Currency|null $currency
 * @var bool          $in_use
 */
$isNew = $currency === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_currencies/store/' . ($isNew ? NEW_ENTRY : esc($currency->code, 'url')), ['id' => 'currency_form', 'class' => 'app-form']) ?>
    <fieldset id="currency_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_currencies.code'), 'code', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="code" id="code" class="form-control form-control-sm ltr-input text-uppercase" dir="ltr" maxlength="3" value="<?= esc($isNew ? '' : $currency->code, 'attr') ?>"<?= $isNew ? '' : ' disabled' ?>>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_currencies.name_ar'), 'name_ar', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name_ar" id="name_ar" class="form-control form-control-sm" maxlength="100" value="<?= esc($isNew ? '' : $currency->nameAr, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_currencies.name_en'), 'name_en', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name_en" id="name_en" class="form-control form-control-sm" dir="ltr" maxlength="100" value="<?= esc($isNew ? '' : $currency->nameEn, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_currencies.decimals'), 'decimals', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="decimals" id="decimals" class="form-select form-select-sm"<?= $in_use ? ' disabled' : '' ?>>
                        <?php foreach (CurrencyCatalog::DECIMALS as $decimals): ?>
                            <option value="<?= $decimals ?>"<?= (! $isNew && $currency->decimals === $decimals) || ($isNew && $decimals === 2) ? ' selected' : '' ?>><?= $decimals ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($in_use): ?>
                        <div class="form-text"><?= esc(lang('Accounting_currencies.decimals_fixed')) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#currency_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        toast_support.show(response.message, response.success ? 'success' : 'danger');
                        if (response.success) {
                            table_support.refresh();
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                code: 'required',
                name_ar: 'required',
                name_en: 'required'
            },

            messages: {
                code: "<?= esc(lang('Accounting_currencies.code_required'), 'js') ?>",
                name_ar: "<?= esc(lang('Accounting_currencies.name_required'), 'js') ?>",
                name_en: "<?= esc(lang('Accounting_currencies.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
