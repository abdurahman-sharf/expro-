<?php
/**
 * @var object|null $center
 * @var list<object> $parents
 * @var string $suggested_code
 */

$isNew = $center === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_cost_centers/save/' . ($isNew ? NEW_ENTRY : (int) $center->cost_center_id), ['id' => 'cost_center_form', 'class' => 'app-form']) ?>
    <fieldset id="cost_center_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_cost_centers.code'), 'code', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="code" id="code" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="32" value="<?= esc($isNew ? $suggested_code : $center->code, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_cost_centers.name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="255" value="<?= esc($isNew ? '' : $center->name, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_cost_centers.parent'), 'parent_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="parent_id" id="parent_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_cost_centers.no_parent') ?></option>
                        <?php foreach ($parents as $parent): ?>
                            <option value="<?= (int) $parent->cost_center_id ?>"<?= ! $isNew && $center->parent_id !== null && (int) $center->parent_id === (int) $parent->cost_center_id ? ' selected' : '' ?>><?= esc($parent->code . ' - ' . $parent->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (! $isNew): ?>
                <div class="field">
                    <?= form_label(lang('Accounting.status'), 'active', ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <select name="active" id="active" class="form-select form-select-sm">
                            <option value="1"<?= (int) $center->active === 1 ? ' selected' : '' ?>><?= lang('Accounting.state_active') ?></option>
                            <option value="0"<?= (int) $center->active === 0 ? ' selected' : '' ?>><?= lang('Accounting.state_inactive') ?></option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#cost_center_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_cost_centers', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                code: 'required',
                name: 'required'
            },

            messages: {
                code: "<?= esc(lang('Accounting_cost_centers.code_required'), 'js') ?>",
                name: "<?= esc(lang('Accounting_cost_centers.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
