<?php
/**
 * @var object|null           $definition
 * @var array<string, string> $modeOptions
 * @var array<int, string>    $accountOptions
 * @var bool                  $canAssignAccount
 */
$isNew = $definition === null;
$mode  = $isNew || $definition->tax_included === null ? '' : ((int) $definition->tax_included === 1 ? 'included' : 'excluded');
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('tax_definitions/save/' . ($isNew ? NEW_ENTRY : (int) $definition->tax_definition_id), ['id' => 'tax_definition_form', 'class' => 'app-form']) ?>
    <fieldset id="tax_definition_fields">
        <div class="field">
            <?= form_label(lang('Tax_definitions.name'), 'tax_name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="tax_name" id="tax_name" class="form-control form-control-sm" maxlength="255" value="<?= $isNew ? '' : esc($definition->tax_name, 'attr') ?>"<?= $isNew ? '' : ' readonly' ?>>
                <p class="form-text mb-0"><?= lang('Tax_definitions.name_desc') ?></p>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Tax_definitions.mode'), 'tax_mode', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('tax_mode', $modeOptions, $mode, ['class' => 'form-select form-select-sm', 'id' => 'tax_mode']) ?>
                <p class="form-text mb-0"><?= lang('Tax_definitions.mode_desc') ?></p>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Tax_definitions.account'), 'account_id', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('account_id', $accountOptions, (string) ($isNew ? 0 : (int) $definition->liability_account_id), ['class' => 'form-select form-select-sm', 'id' => 'account_id'] + ($canAssignAccount ? [] : ['disabled' => 'disabled'])) ?>
                <p class="form-text mb-0"><?= lang($canAssignAccount ? 'Tax_definitions.account_desc' : 'Tax_definitions.account_locked') ?></p>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#tax_definition_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('tax_definitions', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                tax_name: 'required'
            },

            messages: {
                tax_name: "<?= esc(lang('Tax_definitions.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
