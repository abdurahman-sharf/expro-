<?php
/**
 * @var CustomFieldDefinition|null $field
 * @var bool                       $canManage
 * @var list<CustomFieldType>      $types
 */
use App\Libraries\Documents\CustomFieldDefinition;
use App\Libraries\Documents\CustomFields;
use App\Libraries\Documents\CustomFieldType;

$isNew = $field === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('custom_fields/save/' . ($isNew ? NEW_ENTRY : $field->id), ['id' => 'custom_field_form', 'class' => 'app-form']) ?>
    <fieldset id="custom_field_fields">
        <div class="field">
            <?= form_label(lang('Custom_fields.entity'), 'entity', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?php if ($isNew): ?>
                    <select name="entity" id="entity" class="form-select form-select-sm">
                        <?php foreach (CustomFields::ENTITIES as $entity): ?>
                            <option value="<?= esc($entity, 'attr') ?>"><?= esc(lang('Custom_fields.entity_' . $entity)) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <span class="form-control-plaintext" id="entity"><?= esc(lang('Custom_fields.entity_' . $field->entity)) ?></span>
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Custom_fields.name'), 'name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="100" value="<?= $isNew ? '' : esc($field->name, 'attr') ?>">
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Custom_fields.type'), 'type', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <select name="type" id="type" class="form-select form-select-sm">
                    <?php foreach ($types as $type): ?>
                        <option value="<?= esc($type->value, 'attr') ?>"<?= ! $isNew && $field->type === $type ? ' selected' : '' ?>><?= esc(lang($type->languageKey())) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="form-text mb-0"><?= lang('Custom_fields.type_locked_note') ?></p>
            </div>
        </div>

        <div class="field" id="options_field">
            <?= form_label(lang('Custom_fields.options'), 'options', ['class' => 'form-label']) ?>
            <div class="field-control">
                <textarea name="options" id="options" class="form-control form-control-sm" rows="4"><?= $isNew ? '' : esc(implode("\n", $field->options)) ?></textarea>
                <p class="form-text mb-0"><?= lang('Custom_fields.options_help') ?></p>
            </div>
        </div>

        <div class="field">
            <div class="field-control">
                <input type="hidden" name="required" value="0">
                <label class="choice"><input type="checkbox" name="required" id="required" value="1" class="form-check-input"<?= ! $isNew && $field->required ? ' checked' : '' ?>> <?= lang('Custom_fields.required') ?></label>
                <input type="hidden" name="printable" value="0">
                <label class="choice"><input type="checkbox" name="printable" id="printable" value="1" class="form-check-input"<?= ! $isNew && $field->printable ? ' checked' : '' ?>> <?= lang('Custom_fields.printable') ?></label>
                <p class="form-text mb-0"><?= lang('Custom_fields.printable_help') ?></p>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        const showOptions = function() {
            $('#options_field').toggle($('#type').val() === 'select');
        };

        $('#type').on('change', showOptions);
        showOptions();

        $('#custom_field_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('custom_fields', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: 'required'
            },

            messages: {
                name: "<?= esc(lang('Custom_fields.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
