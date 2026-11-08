<?php
/**
 * @var list<CustomFieldDefinition> $fields
 * @var array<int, string>          $values
 * @var string                      $idPrefix
 */
use App\Libraries\Documents\CustomFieldDefinition;
use App\Libraries\Documents\CustomFieldType;

$idPrefix ??= 'custom_';
?>
<?php foreach ($fields as $field): ?>
    <?php
    $inputId = $idPrefix . $field->id;
    $name    = 'custom[' . $field->id . ']';
    $value   = $values[$field->id] ?? '';
    ?>
    <div class="field custom-field" data-field-id="<?= $field->id ?>">
        <?= form_label(esc($field->name), $inputId, ['class' => 'form-label' . ($field->required ? ' text-danger required' : '')]) ?>
        <div class="field-control">
            <?php if ($field->type === CustomFieldType::Textarea): ?>
                <textarea name="<?= esc($name, 'attr') ?>" id="<?= esc($inputId, 'attr') ?>" class="form-control form-control-sm" rows="3" maxlength="<?= CustomFieldType::MAX_TEXTAREA ?>"><?= esc($value) ?></textarea>
            <?php elseif ($field->type === CustomFieldType::Select): ?>
                <select name="<?= esc($name, 'attr') ?>" id="<?= esc($inputId, 'attr') ?>" class="form-select form-select-sm">
                    <option value=""></option>
                    <?php foreach ($field->options as $option): ?>
                        <option value="<?= esc($option, 'attr') ?>"<?= $option === $value ? ' selected' : '' ?>><?= esc($option) ?></option>
                    <?php endforeach; ?>
                    <?php if ($value !== '' && ! in_array($value, $field->options, true)): ?>
                        <option value="<?= esc($value, 'attr') ?>" selected><?= esc($value) ?></option>
                    <?php endif; ?>
                </select>
            <?php elseif ($field->type === CustomFieldType::Checkbox): ?>
                <input type="hidden" name="<?= esc($name, 'attr') ?>" value="0">
                <input type="checkbox" name="<?= esc($name, 'attr') ?>" id="<?= esc($inputId, 'attr') ?>" value="1" class="form-check-input"<?= $value === '1' ? ' checked' : '' ?>>
            <?php else: ?>
                <?php
                $htmlType = match ($field->type) {
                    CustomFieldType::Date  => 'date',
                    CustomFieldType::Url   => 'url',
                    CustomFieldType::Email => 'email',
                    CustomFieldType::Phone => 'tel',
                    default                => 'text',
                };
                ?>
                <input type="<?= $htmlType ?>" name="<?= esc($name, 'attr') ?>" id="<?= esc($inputId, 'attr') ?>" class="form-control form-control-sm<?= in_array($field->type, [CustomFieldType::Number, CustomFieldType::Date, CustomFieldType::Url, CustomFieldType::Email, CustomFieldType::Phone], true) ? ' ltr-input' : '' ?>"<?= $field->type === CustomFieldType::Number ? ' inputmode="decimal"' : '' ?> maxlength="<?= $field->type === CustomFieldType::Url ? CustomFieldType::MAX_URL : CustomFieldType::MAX_TEXT ?>" value="<?= esc($value, 'attr') ?>">
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
