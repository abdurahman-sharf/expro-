<?php

use App\Libraries\Accounting\Currency;

/**
 * @var Currency    $currency
 * @var object|null $rate
 * @var string      $today
 */
$isNew = $rate === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_currencies/rate_store/' . esc($currency->code, 'url') . ($isNew ? '' : '/' . (int) $rate->rate_id), ['id' => 'rate_form', 'class' => 'app-form']) ?>
    <fieldset id="rate_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_currencies.effective_date'), 'effective_date', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="date" name="effective_date" id="effective_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($isNew ? $today : $rate->effective_date, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_currencies.rate'), 'rate', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="rate" id="rate" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" maxlength="19" value="<?= esc($isNew ? '' : rtrim(rtrim((string) $rate->rate, '0'), '.'), 'attr') ?>">
                    <div class="form-text"><?= esc(lang('Accounting_currencies.rate_hint')) ?> <bdi dir="ltr">1 <?= esc($currency->code) ?> = ? </bdi></div>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_currencies.note'), 'note', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="note" id="note" class="form-control form-control-sm" maxlength="255" value="<?= esc($isNew ? '' : (string) $rate->note, 'attr') ?>">
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#rate_form').validate($.extend({
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
                effective_date: 'required',
                rate: 'required'
            },

            messages: {
                effective_date: "<?= esc(lang('Accounting_currencies.date_required'), 'js') ?>",
                rate: "<?= esc(lang('Accounting_currencies.rate_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
