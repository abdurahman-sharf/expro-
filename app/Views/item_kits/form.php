<?php
/**
 * @var object $item_kit_info
 * @var string $selected_kit_item
 * @var int $selected_kit_item_id
 * @var array $item_kit_items
 * @var string $controller_name
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("item_kits/save/$item_kit_info->item_kit_id", ['id' => 'item_kit_form', 'class' => 'app-form']) ?>
    <fieldset id="item_kit_basic_info" class="form-grid">

        <div class="field">
            <?= form_label(lang('Item_kits.item_kit_number'), 'item_kit_number', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group">
                    <span class="input-group-text"><span class="bi bi-upc-scan" aria-hidden="true"></span></span>
                    <?= form_input([
                        'name'  => 'item_kit_number',
                        'id'    => 'item_kit_number',
                        'class' => 'form-control form-control-sm',
                        'value' => $item_kit_info->item_kit_number
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Item_kits.name'), 'name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'  => 'name',
                    'id'    => 'name',
                    'class' => 'form-control form-control-sm',
                    'value' => $item_kit_info->name
                ]) ?>
            </div>
        </div>

        <div class="field span-all">
            <?= form_label(lang('Item_kits.find_kit_item'), 'item_name', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?= form_input([
                        'name'  => 'item_name',
                        'id'    => 'item_name',
                        'class' => 'form-control form-control-sm',
                        'size'  => '50',
                        'value' => $selected_kit_item
                    ]) ?>
                    <?= form_hidden('kit_item_id', (string)$selected_kit_item_id) ?>

                </div>
            </div>
        </div>

        <div class="field">
            <span id="kit_discount_type_label" class="form-label"><?= lang('Item_kits.discount_type') ?></span>
            <div class="field-control" role="radiogroup" aria-labelledby="kit_discount_type_label">
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'kit_discount_type',
                        'type'    => 'radio',
                        'value'   => 0,
                        'checked' => $item_kit_info->kit_discount_type == PERCENT
                    ]) ?> <?= lang('Item_kits.discount_percent') ?>
                </label>
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'kit_discount_type',
                        'type'    => 'radio',
                        'value'   => 1,
                        'checked' => $item_kit_info->kit_discount_type == FIXED
                    ]) ?> <?= lang('Item_kits.discount_fixed') ?>
                </label>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Item_kits.discount'), 'kit_discount', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?= form_input([
                        'name'      => 'kit_discount',
                        'size'      => '5',
                        'maxlength' => '5',
                        'id'        => 'kit_discount',
                        'class'     => 'form-control form-control-sm',
                        'value'     => $item_kit_info->kit_discount_type === FIXED ? to_currency_no_money($item_kit_info->kit_discount) : to_decimals($item_kit_info->kit_discount)
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="field span-all">
            <span id="price_option_label" class="form-label<?= !empty($basic_version) ? ' text-danger required' : '' ?>"><?= lang('Item_kits.price_option') ?></span>
            <div class="field-control" role="radiogroup" aria-labelledby="price_option_label">
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'price_option',
                        'type'    => 'radio',
                        'value'   => 0,
                        'checked' => $item_kit_info->price_option == PRICE_ALL
                    ]) ?> <?= lang('Item_kits.kit_and_components') ?>
                </label>
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'price_option',
                        'type'    => 'radio',
                        'value'   => 1,
                        'checked' => $item_kit_info->price_option == PRICE_KIT
                    ]) ?> <?= lang('Item_kits.kit_only') ?>
                </label>
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'price_option',
                        'type'    => 'radio',
                        'value'   => 2,
                        'checked' => $item_kit_info->price_option == PRICE_KIT_ITEMS    // TODO: === for all of these?
                    ]) ?> <?= lang('Item_kits.kit_and_stock') ?>
                </label>
            </div>
        </div>

        <div class="field span-all">
            <span id="print_option_label" class="form-label<?= !empty($basic_version) ? ' text-danger required' : '' ?>"><?= lang('Item_kits.print_option') ?></span>
            <div class="field-control" role="radiogroup" aria-labelledby="print_option_label">
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'print_option',
                        'type'    => 'radio',
                        'value'   => 0,
                        'checked' => $item_kit_info->print_option == PRINT_ALL
                    ]) ?> <?= lang('Item_kits.all') ?>
                </label>
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'print_option',
                        'type'    => 'radio',
                        'value'   => 1,
                        'checked' => $item_kit_info->print_option == PRINT_PRICED
                    ]) ?> <?= lang('Item_kits.priced_only') ?>
                </label>
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'print_option',
                        'type'    => 'radio',
                        'value'   => 2,
                        'checked' => $item_kit_info->print_option == PRINT_KIT
                    ]) ?> <?= lang('Item_kits.kit_only') ?>
                </label>
            </div>
        </div>


        <div class="field span-all">
            <?= form_label(lang('Item_kits.description'), 'description', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_textarea([
                    'name'  => 'description',
                    'id'    => 'description',
                    'class' => 'form-control form-control-sm',
                    'value' => $item_kit_info->description
                ]) ?>
            </div>
        </div>

        <div class="field span-all">
            <?= form_label(lang('Item_kits.add_item'), 'item', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'  => 'item',
                    'id'    => 'item',
                    'class' => 'form-control form-control-sm'
                ]) ?>
            </div>
        </div>

        <div class="span-all table-responsive">
            <table id="item_kit_items" class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th><?= lang('Common.delete') ?></th>
                        <th><?= lang('Item_kits.sequence') ?></th>
                        <th><?= lang('Item_kits.item') ?></th>
                        <th><?= lang('Item_kits.quantity') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($item_kit_items as $item_kit_item) { ?>
                        <tr>
                            <td><button type="button" class="btn btn-outline-danger btn-sm kit-row-delete" aria-label="<?= lang('Common.delete') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button></td>
                            <td><input class="quantity form-control form-control-sm" id="item_seq_<?= $item_kit_item['item_id'] ?>" name="item_kit_seq[<?= $item_kit_item['item_id'] ?>]" value="<?= parse_decimals($item_kit_item['kit_sequence'], 0) ?>" aria-label="<?= lang('Item_kits.sequence') . ': ' . esc($item_kit_item['name']) ?>"></td>
                            <td><?= esc($item_kit_item['name']) ?></td>
                            <td><input class="quantity form-control form-control-sm" id="item_qty_<?= $item_kit_item['item_id'] ?>" name="item_kit_qty[<?= $item_kit_item['item_id'] ?>]" value="<?= to_quantity_decimals($item_kit_item['quantity']) ?>" aria-label="<?= lang('Item_kits.quantity') . ': ' . esc($item_kit_item['name']) ?>"></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $('#item').autocomplete({
            source: '<?= "items/suggest" ?>',
            minChars: 0,
            autoFocus: false,
            delay: 10,
            appendTo: '.modal-content',
            select: function(e, ui) {
                const $quantity = $('#item_qty_' + ui.item.value);

                if ($quantity.length == 1) {
                    $quantity.val(parseFloat($quantity.val()) + 1);
                } else {
                    const label = DOMPurify.sanitize(ui.item.label);
                    $('#item_kit_items tbody').append('<tr>' +
                        '<td><button type="button" class="btn btn-outline-danger btn-sm kit-row-delete" aria-label="<?= esc(lang('Common.delete'), 'js') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button></td>' +
                        '<td><input class="quantity form-control form-control-sm" id="item_seq_' + ui.item.value + '" name="item_kit_seq[' + ui.item.value + ']" value="0" aria-label="<?= esc(lang('Item_kits.sequence'), 'js') ?>: ' + label + '"></td>' +
                        '<td>' + label + '</td>' +
                        '<td><input class="quantity form-control form-control-sm" id="item_qty_' + ui.item.value + '" name="item_kit_qty[' + ui.item.value + ']" value="1" aria-label="<?= esc(lang('Item_kits.quantity'), 'js') ?>: ' + label + '"></td>' +
                        '</tr>');
                }
                $('#item').val('');
                return false;
            }
        });

        $('#item_kit_items').on('click', '.kit-row-delete', function() {
            $(this).closest('tr').remove();
        });

        $("input[name='item_name']").change(function() {
            if (!$("input[name='item_name']").val()) {
                $("input[name='kit_item_id']").val('');
            }
        });

        const fill_value = function(event, ui) {
            event.preventDefault();
            $("input[name='kit_item_id']").val(ui.item.value);
            $("input[name='item_name']").val(DOMPurify.sanitize(ui.item.label));
        };


        $('#item_name').autocomplete({
            source: "<?= 'items/suggestKits' ?>",
            minChars: 0,
            delay: 15,
            cacheLength: 1,
            appendTo: '.modal-content',
            select: fill_value,
            focus: fill_value
        });

        $('#item_kit_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit("<?= esc($controller_name) ?>", response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: 'required',
                category: 'required',
                item_kit_number: {
                    required: false,
                    remote: {
                        url: '<?= esc("$controller_name/checkItemNumber") ?>',
                        type: 'POST',
                        data: {
                            'item_kit_id': "<?= $item_kit_info->item_kit_id ?>",
                            'item_kit_number': function() {
                                return $('#item_kit_number').val();
                            }
                        }
                    }
                }
            },

            messages: {
                name: "<?= lang('Items.name_required') ?>",
                category: "<?= lang('Items.category_required') ?>",
                item_kit_number: "<?= lang('Item_kits.item_number_duplicate') ?>"
            }
        }, form_support.error));
    });

</script>
