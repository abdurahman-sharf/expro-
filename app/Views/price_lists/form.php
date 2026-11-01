<?php
/**
 * @var object|null $priceList
 */
$isNew = $priceList === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('price_lists/save/' . ($isNew ? NEW_ENTRY : (int) $priceList->price_list_id), ['id' => 'price_list_form', 'class' => 'app-form']) ?>
    <fieldset id="price_list_fields">
        <div class="field">
            <?= form_label(lang('Price_lists.name'), 'name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="100" value="<?= $isNew ? '' : esc($priceList->name, 'attr') ?>">
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Price_lists.status'), 'status', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?= form_dropdown('status', ['1' => lang('Price_lists.active'), '0' => lang('Price_lists.inactive')], $isNew ? '1' : (string) (int) $priceList->status, ['class' => 'form-select form-select-sm', 'id' => 'status']) ?>
                <p class="form-text mb-0"><?= lang('Price_lists.prices_on_item_form') ?></p>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#price_list_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('price_lists', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: 'required'
            },

            messages: {
                name: "<?= esc(lang('Price_lists.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
