<?php

use App\Libraries\Stock\UnitTemplate;

/**
 * @var UnitTemplate|null $template
 * @var bool              $used
 */
$units = $template === null || $template->units === [] ? [null] : $template->units;
$id    = $template === null ? '-1' : (string) $template->templateId;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('stock_units/saveTemplate/' . $id, ['id' => 'template_form', 'class' => 'app-form']) ?>
    <?php if ($used): ?>
        <p class="small text-warning"><?= lang('Stock_units.used_note') ?></p>
    <?php endif; ?>

    <div class="form-grid">
        <div class="field">
            <?= form_label(lang('Stock_units.template_name'), 'name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control"><input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="100" value="<?= esc($template->name ?? '', 'attr') ?>"></div>
        </div>
        <div class="field">
            <?= form_label(lang('Stock_units.main_unit_name'), 'main_unit_name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control"><input type="text" name="main_unit_name" id="main_unit_name" class="form-control form-control-sm" maxlength="50" value="<?= esc($template->mainUnitName ?? '', 'attr') ?>"></div>
        </div>
        <div class="field">
            <?= form_label(lang('Stock_units.main_small_name'), 'main_small_name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control"><input type="text" name="main_small_name" id="main_small_name" class="form-control form-control-sm" maxlength="25" value="<?= esc($template->mainSmallName ?? '', 'attr') ?>"></div>
        </div>
        <div class="field">
            <?= form_label(lang('Stock_units.main_unit_code'), 'main_unit_code', ['class' => 'form-label']) ?>
            <div class="field-control">
                <input type="text" name="main_unit_code" id="main_unit_code" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="3" value="<?= esc($template->mainUnitCode ?? '', 'attr') ?>">
                <p class="form-text mb-0"><?= lang('Stock_units.main_unit_code_help') ?></p>
            </div>
        </div>
        <div class="field">
            <?= form_label(lang('Stock_units.active'), 'active', ['class' => 'form-label']) ?>
            <div class="field-control">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" class="form-check-input" name="active" id="active" value="1"<?= ($template->active ?? true) ? ' checked' : '' ?>>
            </div>
        </div>
    </div>

    <h3 class="h6 mt-3"><?= lang('Stock_units.larger_units') ?></h3>
    <div id="unit_rows">
        <?php foreach ($units as $unit): ?>
            <div class="form-grid unit-row mb-2">
                <div class="field"><input type="text" name="unit_name[]" class="form-control form-control-sm" maxlength="50" value="<?= esc($unit->name ?? '', 'attr') ?>" aria-label="<?= esc(lang('Stock_units.unit_name'), 'attr') ?>" placeholder="<?= esc(lang('Stock_units.unit_name'), 'attr') ?>"></div>
                <div class="field"><input type="text" name="unit_factor[]" class="form-control form-control-sm" inputmode="decimal" dir="ltr" value="<?= esc($unit === null ? '' : rtrim(rtrim($unit->factor, '0'), '.'), 'attr') ?>" aria-label="<?= esc(lang('Stock_units.factor'), 'attr') ?>" placeholder="<?= esc(lang('Stock_units.factor'), 'attr') ?>"></div>
                <div class="field"><input type="text" name="unit_small_name[]" class="form-control form-control-sm" maxlength="25" value="<?= esc($unit->smallName ?? '', 'attr') ?>" aria-label="<?= esc(lang('Stock_units.small_name'), 'attr') ?>" placeholder="<?= esc(lang('Stock_units.small_name'), 'attr') ?>"></div>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" class="btn btn-link btn-sm p-0" id="add_unit_row"><?= lang('Stock_units.add_unit_row') ?></button>

    <?php if ($used): ?>
        <div class="form-check mt-3">
            <input type="checkbox" class="form-check-input" name="as_new_version" id="as_new_version" value="1">
            <label class="form-check-label" for="as_new_version"><?= lang('Stock_units.save_as_new_version') ?></label>
        </div>
    <?php endif; ?>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#add_unit_row').on('click', function() {
            $('#unit_rows .unit-row:last').clone().find('input').val('').end().appendTo('#unit_rows');
        });

        $('#template_form').validate($.extend({
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
            rules: {name: 'required', main_unit_name: 'required', main_small_name: 'required'}
        }, form_support.error));
    });
</script>
