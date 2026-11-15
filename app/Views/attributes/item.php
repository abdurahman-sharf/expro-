<?php
/**
 * @var array $definition_names
 * @var array $definition_values
 * @var int   $item_id
 * @var array $config
 */
?>

<div class="field">
    <?= form_label(lang('Attributes.definition_name'), 'definition_name', ['class' => 'form-label']) ?>
    <div class="field-control">
        <?= form_dropdown([
            'name'     => 'definition_name',
            'options'  => esc($definition_names),
            'selected' => -1,
            'class'    => 'form-select',
            'id'       => 'definition_name',
        ]) ?>
    </div>
</div>

<?php foreach ($definition_values as $definition_id => $definition_value) { ?>

    <div class="field">
        <?= form_label(esc($definition_value['definition_name']), "attribute_link_{$definition_id}", ['class' => 'form-label']) ?>
        <div class="field-control">
            <div class="input-group">
                <?= form_hidden("attribute_ids[{$definition_id}]", (string) ($definition_value['attribute_id']));
    $attribute_value = $definition_value['attribute_value'];

    switch ($definition_value['definition_type']) {
        case DATE:
            $value = (empty($attribute_value) || empty($attribute_value->attribute_date)) ? NOW : strtotime($attribute_value->attribute_date);
            echo form_input([
                'name'               => "attribute_links[{$definition_id}]",
                'id'                 => "attribute_link_{$definition_id}",
                'value'              => to_date($value),
                'class'              => 'form-control form-control-sm datetime',
                'data-definition-id' => $definition_id,
                'readonly'           => 'true',
            ]);
            break;

        case DROPDOWN:
            $selected_value = $definition_value['selected_value'];
            echo form_dropdown([
                'name'               => "attribute_links[{$definition_id}]",
                'id'                 => "attribute_link_{$definition_id}",
                'options'            => esc($definition_value['values']),
                'selected'           => $selected_value,
                'class'              => 'form-select',
                'data-definition-id' => $definition_id,
            ]);
            break;

        case TEXT:
            $value = (empty($attribute_value) || empty($attribute_value->attribute_value)) ? $definition_value['selected_value'] : $attribute_value->attribute_value;
            echo form_input([
                'name'               => "attribute_links[{$definition_id}]",
                'id'                 => "attribute_link_{$definition_id}",
                'value'              => $value,
                'class'              => 'form-control valid_chars',
                'data-definition-id' => $definition_id,
            ]);
            break;

        case DECIMAL:
            $value = (empty($attribute_value) || empty($attribute_value->attribute_decimal)) ? $definition_value['selected_value'] : $attribute_value->attribute_decimal;
            echo form_input([
                'name'               => "attribute_links[{$definition_id}]",
                'id'                 => "attribute_link_{$definition_id}",
                'value'              => to_decimals((float) $value),
                'class'              => 'form-control valid_chars',
                'data-definition-id' => $definition_id,
            ]);
            break;

        case CHECKBOX:
            $value = (empty($attribute_value) || empty($attribute_value->attribute_value)) ? $definition_value['selected_value'] : $attribute_value->attribute_value;

            // Sends 0 if the box is unchecked instead of not sending anything.
            echo form_input([
                'type'               => 'hidden',
                'name'               => "attribute_links[{$definition_id}]",
                'value'              => 0,
                'data-definition-id' => $definition_id,
            ]);
            echo form_checkbox([
                'name'               => "attribute_links[{$definition_id}]",
                'id'                 => "attribute_link_{$definition_id}",
                'value'              => 1,
                'checked'            => $value == 1,
                'class'              => 'form-check-input',
                'data-definition-id' => $definition_id,
            ]);
            break;
    }
    ?>
                <button type="button" class="btn btn-outline-secondary remove_attribute_btn" aria-label="<?= lang('Common.delete') ?>">
                    <span class="bi bi-trash" aria-hidden="true"></span>
                </button>
            </div>
        </div>
    </div>

<?php } ?>

<script type="text/javascript">
    (function() {
        <?= view('partial/datepicker_locale', ['format' => dateformatFlatpickr($config['dateformat'])]) ?>

        const enable_delete = function() {
            $('.remove_attribute_btn').click(function() {
                $(this).parents('.field').remove();
            });
        };

        enable_delete();

        $("input[name*='attribute_links']").change(function() {
            const definition_id = $(this).data('definition-id');
            $("input[name='attribute_ids[" + definition_id + "]']").val('');
        }).autocomplete({
            source: function(request, response) {
                $.get('<?= 'attributes/suggestAttribute/' ?>' + this.element.data('definition-id') + '?term=' + request.term, function(data) {
                    return response(data);
                }, 'json');
            },
            appendTo: '.modal-content',
            select: function(event, ui) {
                event.preventDefault();
                $(this).val(ui.item.label);
            },
            delay: 10
        });

        const definition_values = function() {
            const result = {};
            $("[name*='attribute_links'").each(function() {
                const definition_id = $(this).data('definition-id');
                const element = $(this);
                
                // For checkboxes, use the visible checkbox, not the hidden input
                if (element.attr('type') === 'hidden' && element.siblings('input[type="checkbox"]').length > 0) {
                    // Skip hidden inputs that have a corresponding checkbox
                    return;
                }
                
                // For checkboxes, get the checked state
                if (element.attr('type') === 'checkbox') {
                    result[definition_id] = element.prop('checked') ? '1' : '0';
                } else {
                    result[definition_id] = element.val();
                }
            });
            return result;
        };

        const refresh = function() {
            const definition_id = $("#definition_name option:selected").val();
            let attribute_values = definition_values();
            attribute_values[definition_id] = '';
            $('#attributes').load('<?= "items/attributes/{$item_id}" ?>', {
                'definition_ids': JSON.stringify(attribute_values)
            }, enable_delete);
        };

        $('#definition_name').change(function() {
            refresh();
        });
    })();
</script>
