<?php
/**
 * @var string      $resource
 * @var string      $kind
 * @var object|null $entry
 */
$isNew = $entry === null;
$idKey = $kind === 'item_brand' ? 'brand_id' : 'classification_id';
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open($resource . '/save/' . ($isNew ? NEW_ENTRY : (int) $entry->{$idKey}), ['id' => 'lookup_form', 'class' => 'app-form']) ?>
    <fieldset id="lookup_fields">
        <div class="field">
            <?= form_label(lang('Lookups.name'), 'name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="100" value="<?= $isNew ? '' : esc($entry->name, 'attr') ?>">
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Lookups.status'), 'status', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?= form_dropdown('status', ['1' => lang('Lookups.active'), '0' => lang('Lookups.inactive')], $isNew ? '1' : (string) (int) $entry->status, ['class' => 'form-select form-select-sm', 'id' => 'status']) ?>
                <p class="form-text mb-0"><?= lang('Lookups.inactive_help') ?></p>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#lookup_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('<?= esc($resource, 'js') ?>', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: 'required'
            },

            messages: {
                name: "<?= esc(lang('Lookups.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
