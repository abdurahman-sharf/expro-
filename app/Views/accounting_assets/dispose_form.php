<?php
/**
 * @var object       $asset
 * @var string       $kind
 * @var list<object> $treasuries
 * @var string       $today
 * @var string       $book_value
 */
$sale = $kind === 'sale';
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open('accounting_assets/dispose/' . esc($kind, 'attr') . '/' . (int) $asset->asset_id, ['id' => 'dispose_form', 'class' => 'app-form']) ?>
    <fieldset>
        <p><?= esc(lang('Accounting_assets.dispose_summary', [$asset->code, $book_value])) ?></p>
        <p class="small text-body-secondary"><?= lang($sale ? 'Accounting_assets.sale_note' : 'Accounting_assets.write_off_note') ?></p>
        <div class="form-grid">
            <div class="field">
                <label class="form-label text-danger required" for="date"><?= lang('Accounting_assets.operation_date') ?></label>
                <div class="field-control"><input type="date" name="date" id="date" class="form-control form-control-sm ltr-input" dir="ltr" max="<?= esc($today, 'attr') ?>" value="<?= esc($today, 'attr') ?>"></div>
            </div>
            <?php if ($sale): ?>
                <div class="field">
                    <label class="form-label text-danger required" for="proceeds"><?= lang('Accounting_assets.operation_proceeds') ?></label>
                    <div class="field-control"><input type="text" name="proceeds" id="proceeds" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off"></div>
                </div>
                <div class="field">
                    <label class="form-label text-danger required" for="treasury_id"><?= lang('Accounting_assets.field_treasury') ?></label>
                    <div class="field-control">
                        <select name="treasury_id" id="treasury_id" class="form-select form-select-sm">
                            <option value=""><?= lang('Accounting_assets.none') ?></option>
                            <?php foreach ($treasuries as $treasury): ?>
                                <option value="<?= (int) $treasury->treasury_id ?>"><?= esc($treasury->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>
            <div class="field span-all">
                <label class="form-label" for="note"><?= lang('Accounting_assets.operation_note') ?></label>
                <div class="field-control"><textarea name="note" id="note" class="form-control form-control-sm" rows="2" maxlength="500"></textarea></div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#dispose_form').validate($.extend({
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

            rules: {date: 'required'<?= $sale ? ", proceeds: 'required', treasury_id: 'required'" : '' ?>},

            messages: {date: "<?= esc(lang('Accounting_assets.date_required'), 'js') ?>"<?= $sale ? ', proceeds: "' . esc(lang('Accounting_assets.proceeds_required'), 'js') . '", treasury_id: "' . esc(lang('Accounting_assets.proceeds_required'), 'js') . '"' : '' ?>}
        }, form_support.error));
    });
</script>
