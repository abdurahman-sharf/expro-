<?php
/**
 * @var object $asset
 * @var string $month
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_assets/usage/' . (int) $asset->asset_id, ['id' => 'usage_form', 'class' => 'app-form']) ?>
    <fieldset>
        <p><?= esc(lang('Accounting_assets.usage_summary', [$asset->code, (string) $asset->unit_name])) ?></p>
        <div class="form-grid">
            <div class="field">
                <label class="form-label text-danger required" for="month"><?= lang('Accounting_assets.depreciation_month') ?></label>
                <div class="field-control"><input type="month" name="month" id="month" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($month, 'attr') ?>"></div>
            </div>
            <div class="field">
                <label class="form-label text-danger required" for="units"><?= lang('Accounting_assets.usage_units') ?></label>
                <div class="field-control"><input type="text" name="units" id="units" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off"></div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#usage_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            window.location.href = response.redirect;

                            return;
                        }
                        $('#error_message_box').empty().append($('<li></li>').text(response.message));
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {month: 'required', units: 'required'},

            messages: {month: "<?= esc(lang('Accounting_assets.usage_required'), 'js') ?>", units: "<?= esc(lang('Accounting_assets.usage_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
