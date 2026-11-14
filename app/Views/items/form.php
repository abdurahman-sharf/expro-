<?php
/**
 * @var object                                                                      $item_info
 * @var array                                                                       $categories
 * @var int                                                                         $selected_category
 * @var bool                                                                        $standard_item_locked
 * @var bool                                                                        $item_kit_disabled
 * @var int                                                                         $allow_temp_item
 * @var array                                                                       $suppliers
 * @var int                                                                         $selected_supplier
 * @var bool                                                                        $use_destination_based_tax
 * @var float                                                                       $default_tax_1_rate
 * @var float                                                                       $default_tax_2_rate
 * @var string                                                                      $tax_category
 * @var int                                                                         $tax_category_id
 * @var bool                                                                        $include_hsn
 * @var string                                                                      $hsn_code
 * @var array                                                                       $stock_locations
 * @var bool                                                                        $logo_exists
 * @var string                                                                      $image_path
 * @var string                                                                      $selected_low_sell_item
 * @var int                                                                         $selected_low_sell_item_id
 * @var string                                                                      $controller_name
 * @var array                                                                       $config
 * @var bool                                                                        $minimumPriceEnabled
 * @var bool                                                                        $maxDiscountEnabled
 * @var list<array{id: int, name: string, active: bool, price: string}>             $priceListRows
 * @var list<array{key: string, selected: ?int, used: bool, options: list<object>}> $itemAccountRows
 * @var array<int, string>                                                          $brandOptions
 * @var array<int, string>                                                          $statusOptions
 * @var array{brand_id: int, status: int, supplier_code: string}|null               $masterItem
 */

use App\Models\Item;

$minimumPriceEnabled ??= false;
$maxDiscountEnabled ??= false;
$priceListRows ??= [];
$itemAccountRows ??= [];
$masterItem ??= null;

$margin = Item::marginPercent((string) $item_info->unit_price, (string) $item_info->cost_price);
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("items/save/{$item_info->item_id}", ['id' => 'item_form', 'enctype' => 'multipart/form-data', 'class' => 'app-form']) ?>
    <fieldset id="item_basic_info" class="form-grid">

        <div class="field">
            <?= form_label(lang('Items.item_number'), 'item_number', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="input-group">
                    <span class="input-group-text"><span class="bi bi-upc-scan" aria-hidden="true"></span></span>
                    <?= form_input([
                        'name'  => 'item_number',
                        'id'    => 'item_number',
                        'class' => 'form-control form-control-sm',
                        'value' => $item_info->item_number,
                    ]) ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.name'), 'name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'  => 'name',
                    'id'    => 'name',
                    'class' => 'form-control form-control-sm',
                    'value' => $item_info->name,
                ]) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.category'), $config['category_dropdown'] ? 'category_select' : 'category', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <div class="input-group">
                    <span class="input-group-text"><span class="bi bi-tag" aria-hidden="true"></span></span>
                    <?php
                    if ($config['category_dropdown']) {
                        echo form_dropdown('category', $categories, $selected_category, ['class' => 'form-select', 'id' => 'category_select']);
                    } else {
                        echo form_input([
                            'name'  => 'category',
                            'id'    => 'category',
                            'class' => 'form-control form-control-sm',
                            'value' => $item_info->category,
                        ]);
                    }
?>
                </div>
            </div>
        </div>

        <div id="attributes" class="attributes-slot">
            <script type="text/javascript">
                $('#attributes').load('<?= "items/attributes/{$item_info->item_id}" ?>');
            </script>
        </div>

        <?php if (! empty($custom_fields)): ?>
            <?= view('custom_fields/inputs', ['fields' => $custom_fields, 'values' => $custom_values ?? [], 'idPrefix' => 'item_custom_']) ?>
        <?php endif; ?>

        <div class="field">
            <span id="stock_type_label" class="form-label<?= ! empty($basic_version) ? ' text-danger required' : '' ?>"><?= lang('Items.stock_type') ?></span>
            <div class="field-control" role="radiogroup" aria-labelledby="stock_type_label">
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'stock_type',
                        'type'    => 'radio',
                        'id'      => 'stock_type_0',
                        'value'   => 0,
                        'checked' => $item_info->stock_type === HAS_STOCK,
                    ]) ?> <?= lang('Items.stock') ?>
                </label>
                <label class="choice">
                    <?= form_radio([
                        'name'    => 'stock_type',
                        'type'    => 'radio',
                        'id'      => 'stock_type_1',
                        'value'   => 1,
                        'checked' => $item_info->stock_type === HAS_NO_STOCK,
                    ]) ?><?= lang('Items.nonstock') ?>
                </label>
            </div>
        </div>

        <div class="field span-all">
            <span id="item_type_label" class="form-label<?= ! empty($basic_version) ? ' text-danger required' : '' ?>"><?= lang('Items.type') ?></span>
            <div class="field-control" role="radiogroup" aria-labelledby="item_type_label">
                <label class="choice">
                    <?php
$radio_button = [
    'name'    => 'item_type',
    'type'    => 'radio',
    'id'      => 'item_type_0',
    'value'   => 0,
    'checked' => $item_info->item_type === ITEM,
];

if ($standard_item_locked) {
    $radio_button['disabled'] = true;
}
echo form_radio($radio_button) ?> <?= lang('Items.standard') ?>
                </label>
                <label class="choice">
                    <?php
$radio_button = [
    'name'    => 'item_type',
    'type'    => 'radio',
    'id'      => 'item_type_1',
    'value'   => 1,
    'checked' => $item_info->item_type === ITEM_KIT,
];

if ($item_kit_disabled) {
    $radio_button['disabled'] = true;
}
echo form_radio($radio_button) ?> <?= lang('Items.kit') ?>
                </label>
                <?php if ($config['derive_sale_quantity'] === '1') { ?>
                    <label class="choice">
                        <?= form_radio([
                            'name'    => 'item_type',
                            'type'    => 'radio',
                            'id'      => 'item_type_2',
                            'value'   => 2,
                            'checked' => $item_info->item_type === ITEM_AMOUNT_ENTRY,
                        ]) ?><?= lang('Items.amount_entry') ?>
                    </label>
                <?php } ?>
                <?php if ($allow_temp_item === 1) { ?>
                    <label class="choice">
                        <?= form_radio([
                            'name'    => 'item_type',
                            'type'    => 'radio',
                            'id'      => 'item_type_3',
                            'value'   => 3,
                            'checked' => $item_info->item_type === ITEM_TEMP,
                        ]) ?> <?= lang('Items.temp') ?>
                    </label>
                <?php } ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.supplier'), 'supplier', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('supplier_id', $suppliers, $selected_supplier, ['class' => 'form-select', 'id' => 'supplier']) ?>
            </div>
        </div>

        <?php if ($masterItem !== null): ?>
            <?= form_hidden('master_data', '1') ?>

            <div class="field" id="supplier_code_row">
                <?= form_label(lang('Items.supplier_code'), 'supplier_code', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_input(['name' => 'supplier_code', 'id' => 'supplier_code', 'class' => 'form-control form-control-sm', 'maxlength' => '100', 'dir' => 'ltr', 'value' => $masterItem['supplier_code']]) ?>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Items.brand'), 'brand_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_dropdown('brand_id', array_map('esc', $brandOptions), (string) $masterItem['brand_id'], ['class' => 'form-select', 'id' => 'brand_id']) ?>
                    <p class="form-text mb-0"><?= anchor('item_brands', lang('Items.brands_manage'), ['target' => '_blank', 'rel' => 'noopener']) ?></p>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Items.status'), 'status', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_dropdown('status', array_map('esc', $statusOptions), (string) $masterItem['status'], ['class' => 'form-select', 'id' => 'status']) ?>
                    <p class="form-text mb-0"><?= lang('Items.status_help') ?></p>
                </div>
            </div>
        <?php endif; ?>

        <div class="field">
            <?= form_label(lang('Items.cost_price'), 'cost_price', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'    => 'cost_price',
                        'id'      => 'cost_price',
                        'class'   => 'form-control form-control-sm',
                        'onClick' => 'this.select();',
                        'value'   => to_currency_no_money($item_info->cost_price),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.unit_price'), 'unit_price', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <div class="input-group input-group-sm">
                    <?php if (! is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                    <?= form_input([
                        'name'    => 'unit_price',
                        'id'      => 'unit_price',
                        'class'   => 'form-control form-control-sm',
                        'onClick' => 'this.select();',
                        'value'   => to_currency_no_money($item_info->unit_price),
                    ]) ?>
                    <?php if (is_right_side_currency_symbol()): ?>
                        <span class="input-group-text"><b><?= esc($config['currency_symbol']) ?></b></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($minimumPriceEnabled): ?>
            <div class="field" id="item_margin_field">
                <?= form_label(lang('Items.margin'), 'item_margin', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <span id="item_margin" dir="ltr"><?= $margin === null ? '' : esc($margin) . '%' ?></span>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Items.minimum_price'), 'minimum_price', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <div class="input-group input-group-sm">
                        <?= form_input([
                            'name'      => 'minimum_price',
                            'id'        => 'minimum_price',
                            'class'     => 'form-control form-control-sm',
                            'inputmode' => 'decimal',
                            'dir'       => 'ltr',
                            'onClick'   => 'this.select();',
                            'value'     => to_currency_exact((string) ($item_info->minimum_price ?? 0)),
                        ]) ?>
                    </div>
                    <p class="form-text mb-0"><?= lang('Items.minimum_price_hint') ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($maxDiscountEnabled): ?>
            <div class="field">
                <?= form_label(lang('Items.max_discount_percent'), 'max_discount_percent', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <div class="input-group input-group-sm">
                        <?= form_input([
                            'name'      => 'max_discount_percent',
                            'id'        => 'max_discount_percent',
                            'class'     => 'form-control form-control-sm',
                            'inputmode' => 'decimal',
                            'dir'       => 'ltr',
                            'value'     => ($item_info->max_discount_percent ?? null) === null ? '' : to_decimals($item_info->max_discount_percent),
                        ]) ?>
                        <span class="input-group-text"><b>%</b></span>
                    </div>
                    <p class="form-text mb-0"><?= lang('Items.max_discount_hint') ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($priceListRows as $row): ?>
            <div class="field price-list-price">
                <?= form_label(esc($row['name']) . ($row['active'] ? '' : ' (' . lang('Price_lists.inactive') . ')'), 'price_list_price_' . $row['id'], ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_input([
                        'name'        => 'price_list_prices[' . $row['id'] . ']',
                        'id'          => 'price_list_price_' . $row['id'],
                        'class'       => 'form-control form-control-sm',
                        'inputmode'   => 'decimal',
                        'dir'         => 'ltr',
                        'placeholder' => lang('Price_lists.price_placeholder'),
                        'value'       => $row['price'],
                    ]) ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if ($itemAccountRows !== []): ?>
            <div class="field item-accounts">
                <span class="form-label fw-semibold"><?= lang('Items.accounting_accounts_title') ?></span>
                <div class="field-control">
                    <p class="form-text mb-0"><?= lang('Items.accounting_accounts_hint') ?></p>
                </div>
            </div>

            <?php foreach ($itemAccountRows as $row): ?>
                <?php $fieldId = 'item_account_' . str_replace('.', '_', $row['key']); ?>
                <div class="field item-account">
                    <?= form_label(lang('Items.accounting_account_' . str_replace('.', '_', $row['key'])), $fieldId, ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <select name="item_accounts[<?= esc($row['key'], 'attr') ?>]" id="<?= esc($fieldId, 'attr') ?>" class="form-select form-select-sm">
                            <option value=""><?= lang('Items.accounting_account_default') ?></option>
                            <?php foreach ($row['options'] as $option): ?>
                                <option value="<?= (int) $option->account_id ?>"<?= $row['selected'] === (int) $option->account_id ? ' selected' : '' ?>><?= esc($option->code . ' - ' . $option->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (! $row['used']): ?>
                            <p class="form-text mb-0"><?= lang('Items.accounting_account_not_used') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php if (! $use_destination_based_tax) { ?>
            <div class="field">
                <?= form_label(lang('Items.tax_1'), 'tax_name_1', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <div class="input-group">
                        <?= form_input([
                            'name'  => 'tax_names[]',
                            'id'    => 'tax_name_1',
                            'class' => 'form-control form-control-sm',
                            'value' => $item_tax_info[0]['name'] ?? $config['default_tax_1_name'],
                        ]) ?>
                        <?= form_input([
                            'name'       => 'tax_percents[]',
                            'id'         => 'tax_percent_name_1',
                            'class'      => 'form-control form-control-sm',
                            'aria-label' => lang('Items.tax_1') . ' %',
                            'value'      => isset($item_tax_info[0]['percent']) ? to_tax_decimals($item_tax_info[0]['percent']) : to_tax_decimals($default_tax_1_rate),
                        ]) ?>
                        <span class="input-group-text"><b>%</b></span>
                    </div>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Items.tax_2'), 'tax_name_2', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <div class="input-group">
                        <?= form_input([
                            'name'  => 'tax_names[]',
                            'id'    => 'tax_name_2',
                            'class' => 'form-control form-control-sm',
                            'value' => $item_tax_info[1]['name'] ?? $config['default_tax_2_name'],
                        ]) ?>
                        <?= form_input([
                            'name'       => 'tax_percents[]',
                            'class'      => 'form-control form-control-sm',
                            'aria-label' => lang('Items.tax_2') . ' %',
                            'id'         => 'tax_percent_name_2',
                            'value'      => isset($item_tax_info[1]['percent']) ? to_tax_decimals($item_tax_info[1]['percent']) : to_tax_decimals($default_tax_2_rate),
                        ]) ?>
                        <span class="input-group-text"><b>%</b></span>
                    </div>
                </div>
            </div>

            <?php foreach ([1, 2] as $purchaseSlot): ?>
                <?php $purchasePercent = $item_info->{"purchase_tax_{$purchaseSlot}_percent"} ?? null; ?>
                <div class="field">
                    <?= form_label(lang("Items.purchase_tax_{$purchaseSlot}"), "purchase_tax_name_{$purchaseSlot}", ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <div class="input-group">
                            <?= form_input([
                                'name'  => 'purchase_tax_names[]',
                                'id'    => "purchase_tax_name_{$purchaseSlot}",
                                'class' => 'form-control form-control-sm',
                                'value' => $item_info->{"purchase_tax_{$purchaseSlot}_name"} ?? '',
                            ]) ?>
                            <?= form_input([
                                'name'       => 'purchase_tax_percents[]',
                                'id'         => "purchase_tax_percent_{$purchaseSlot}",
                                'class'      => 'form-control form-control-sm',
                                'aria-label' => lang("Items.purchase_tax_{$purchaseSlot}") . ' %',
                                'inputmode'  => 'decimal',
                                'dir'        => 'ltr',
                                'value'      => $purchasePercent === null || $purchasePercent === '' ? '' : ((float) $purchasePercent === 0.0 ? '0' : to_tax_decimals($purchasePercent)),
                            ]) ?>
                            <span class="input-group-text"><b>%</b></span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="form-text mb-3" id="purchase_tax_hint"><?= lang('Items.purchase_tax_hint') ?></div>
        <?php } ?>

        <?php if ($use_destination_based_tax): ?>
            <div class="field">
                <?= form_label(lang('Taxes.tax_category'), 'tax_category', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <div class="input-group input-group-sm">
                        <?= form_input([
                            'name'  => 'tax_category',
                            'id'    => 'tax_category',
                            'class' => 'form-control form-control-sm',
                            'size'  => '50',
                            'value' => $tax_category,
                        ]) ?>
                        <?= form_hidden('tax_category_id', $tax_category_id) ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($include_hsn): ?>
            <div class="field">
                <?= form_label(lang('Items.hsn_code'), 'hsn_code', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <div class="input-group">
                        <?= form_input([
                            'name'  => 'hsn_code',
                            'id'    => 'hsn_code',
                            'class' => 'form-control form-control-sm',
                            'value' => $hsn_code,
                        ]) ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php foreach ($stock_locations as $key => $location_detail) { ?>
            <div class="field">
                <?= form_label(lang('Items.quantity') . ' ' . $location_detail['location_name'], "quantity_{$key}", ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <?= form_input([
                        'name'    => "quantity_{$key}",
                        'id'      => "quantity_{$key}",
                        'class'   => 'required quantity form-control',
                        'onClick' => 'this.select();',
                        'value'   => isset($item_info->item_id) ? to_quantity_decimals($location_detail['quantity']) : to_quantity_decimals(0),
                    ]) ?>
                </div>
            </div>
        <?php } ?>

        <div class="field">
            <?= form_label(lang('Items.receiving_quantity'), 'receiving_quantity', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'    => 'receiving_quantity',
                    'id'      => 'receiving_quantity',
                    'class'   => 'required form-control form-control-sm',
                    'onClick' => 'this.select();',
                    'value'   => isset($item_info->item_id) ? to_quantity_decimals($item_info->receiving_quantity) : to_quantity_decimals(0),
                ]) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Items.reorder_level'), 'reorder_level', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'    => 'reorder_level',
                    'id'      => 'reorder_level',
                    'class'   => 'form-control form-control-sm',
                    'onClick' => 'this.select();',
                    'value'   => isset($item_info->item_id) ? to_quantity_decimals($item_info->reorder_level) : to_quantity_decimals(0),
                ]) ?>
            </div>
        </div>

        <div class="field span-all">
            <?= form_label(lang('Items.description'), 'description', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_textarea([
                    'name'  => 'description',
                    'id'    => 'description',
                    'class' => 'form-control form-control-sm',
                    'value' => $item_info->description,
                ]) ?>
            </div>
        </div>

        <div class="field span-all">
            <?= form_label(lang('Items.image'), 'items_image', ['class' => 'form-label']) ?>
            <div class="field-control">
                <div class="image-upload">
                    <img class="image-upload-preview<?= $logo_exists ? '' : ' d-none' ?>" alt="<?= lang('Items.image') ?>" src="<?= $image_path ?>">
                    <input type="file" class="form-control form-control-sm" id="items_image" name="items_image" accept="image/*" data-preview=".image-upload-preview">
                    <button type="button" class="btn btn-outline-danger btn-sm image-upload-remove<?= $logo_exists ? '' : ' d-none' ?>"><?= lang('Items.remove_image') ?></button>
                </div>
            </div>
        </div>

        <div class="field field-check">
            <?= form_label(lang('Items.allow_alt_description'), 'allow_alt_description', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_checkbox([
                    'name'    => 'allow_alt_description',
                    'id'      => 'allow_alt_description',
                    'value'   => 1,
                    'checked' => $item_info->allow_alt_description === 1,
                ]) ?>
            </div>
        </div>

        <div class="field field-check">
            <?= form_label(lang('Items.is_serialized'), 'is_serialized', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_checkbox([
                    'name'    => 'is_serialized',
                    'id'      => 'is_serialized',
                    'value'   => 1,
                    'checked' => $item_info->is_serialized === 1,
                ]) ?>
            </div>
        </div>

        <?php if ($config['multi_pack_enabled'] === '1') { ?>
            <div class="field">
                <?= form_label(lang('Items.qty_per_pack'), 'qty_per_pack', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_input([
                        'name'  => 'qty_per_pack',
                        'id'    => 'qty_per_pack',
                        'class' => 'form-control form-control-sm',
                        'value' => isset($item_info->item_id) ? to_quantity_decimals($item_info->qty_per_pack) : to_quantity_decimals(0),
                    ]) ?>
                </div>
            </div>
            <div class="field">
                <?= form_label(lang('Items.pack_name'), 'pack_name', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_input([
                        'name'  => 'pack_name',
                        'id'    => 'pack_name',
                        'class' => 'form-control form-control-sm',
                        'value' => $item_info->pack_name,
                    ]) ?>
                </div>
            </div>
            <div class="field">
                <?= form_label(lang('Items.low_sell_item'), 'low_sell_item_name', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <div class="input-group input-group-sm">
                        <?= form_input([
                            'name'  => 'low_sell_item_name',
                            'id'    => 'low_sell_item_name',
                            'class' => 'form-control form-control-sm',
                            'value' => $selected_low_sell_item,
                        ]) ?>
                        <?= form_hidden('low_sell_item_id', $selected_low_sell_item_id) ?>
                    </div>
                </div>
            </div>
        <?php } ?>

        <div class="field field-check">
            <?= form_label(lang('Items.is_deleted'), 'is_deleted', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_checkbox([
                    'name'    => 'is_deleted',
                    'id'      => 'is_deleted',
                    'value'   => 1,
                    'checked' => $item_info->deleted === 1,
                ]) ?>
            </div>
        </div>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        const showSupplierCode = function() {
            $('#supplier_code_row').toggle($('#supplier').val() !== '');
        };

        $('#supplier').on('change', showSupplierCode);
        showSupplierCode();
        $('#new').click(function() {
            let stay_open = true;
            $('#item_form').submit();
        });

        $('#submit').click(function() {
            let stay_open = false;
        });

        $("input[name='tax_category']").change(function() {
            !$(this).val() && $(this).val('');
        });

        const fill_tax_category_value = function(event, ui) {
            event.preventDefault();
            $("input[name='tax_category_id']").val(ui.item.value);
            $("input[name='tax_category']").val(ui.item.label);
        };

        $('#tax_category').autocomplete({
            source: "<?= 'taxes/suggestTaxCategories' ?>",
            minChars: 0,
            delay: 15,
            cacheLength: 1,
            appendTo: '.modal-content',
            select: fill_tax_category_value,
            focus: fill_tax_category_value
        });

        const fill_low_sell_value = function(event, ui) {
            event.preventDefault();
            $("input[name='low_sell_item_id']").val(ui.item.value);
            $("input[name='low_sell_item_name']").val(ui.item.label);
        };

        $('#low_sell_item_name').autocomplete({
            source: "<?= 'items/suggestLowSell' ?>",
            minChars: 0,
            delay: 15,
            cacheLength: 1,
            appendTo: '.modal-content',
            select: fill_low_sell_value,
            focus: fill_low_sell_value
        });

        $('#category').autocomplete({
            source: "<?= 'items/suggestCategory' ?>",
            delay: 10,
            appendTo: '.modal-content'
        });

        $('.image-upload-remove').click(function() {
            $.ajax({
                type: 'GET',
                url: '<?= "{$controller_name}/removeLogo/{$item_info->item_id}" ?>',
                dataType: 'json'
            })
        });

        $.validator.addMethod('valid_chars', function(value, element) {
            return value.match(/(\||_)/g) == null;
        }, "<?= lang('Attributes.attribute_value_invalid_chars') ?>");

        const init_validation = function() {
            $('#item_form').validate($.extend({
                submitHandler: function(form, event) { // Event is not used as a parameter here
                    $(form).ajaxSubmit({
                        success: function(response) {
                            let stay_open = dialog_support.clicked_id() != 'submit';
                            if (stay_open) {
                                // Set action of item_form to url without item id, so a new one can be created
                                $('#item_form').attr('action', "<?= 'items/save/' ?>");
                                // Use a whitelist of fields to minimize unintended side effects
                                $(':text, :password, :file, #description, #item_form').not('.quantity, #reorder_level, #tax_name_1, #receiving_quantity, ' +
                                    '#tax_percent_name_1, #category, #reference_number, #name, #cost_price, #unit_price, #taxed_cost_price, #taxed_unit_price, #definition_name, [name^="attribute_links"]').val('');
                                // De-select any checkboxes, radios and drop-down menus
                                $(':input', '#item_form').removeAttr('checked').removeAttr('selected');
                            } else {
                                dialog_support.hide();
                            }
                            table_support.handle_submit('<?= 'items' ?>', response, stay_open);
                            init_validation();
                        },
                        dataType: 'json'
                    });
                },

                errorLabelContainer: '#error_message_box',

                rules: {
                    name: 'required',
                    category: 'required',
                    item_number: {
                        required: false,
                        remote: {
                            url: "<?= esc("{$controller_name}/checkItemNumber") ?>",
                            type: 'POST',
                            data: {
                                'item_id': "<?= $item_info->item_id ?>"
                                // item_number should be passed into the function by default
                            }
                        }
                    },
                    cost_price: {
                        required: true,
                        remote: "<?= esc("{$controller_name}/checkNumeric") ?>"
                    },
                    unit_price: {
                        required: true,
                        remote: "<?= esc("{$controller_name}/checkNumeric") ?>"
                    },
                    <?php foreach ($stock_locations as $key => $location_detail) { ?>
                        <?= 'quantity_' . $key ?>: {
                            required: true,
                            remote: "<?= esc("{$controller_name}/checkNumeric") ?>"
                        },
                    <?php } ?>
                    receiving_quantity: {
                        required: true,
                        remote: "<?= esc("{$controller_name}/checkNumeric") ?>"
                    },
                    reorder_level: {
                        required: true,
                        remote: "<?= esc("{$controller_name}/checkNumeric") ?>"
                    },
                    tax_percent: {
                        required: false,
                        remote: "<?= esc("{$controller_name}/checkNumeric") ?>"
                    }
                },

                messages: {
                    name: "<?= lang('Items.name_required') ?>",
                    item_number: "<?= lang('Items.item_number_duplicate') ?>",
                    category: "<?= lang('Items.category_required') ?>",
                    cost_price: {
                        required: "<?= lang('Items.cost_price_required') ?>",
                        number: "<?= lang('Items.cost_price_number') ?>"
                    },
                    unit_price: {
                        required: "<?= lang('Items.unit_price_required') ?>",
                        number: "<?= lang('Items.unit_price_number') ?>"
                    },
                    <?php foreach ($stock_locations as $key => $location_detail) { ?>
                        <?= esc("quantity_{$key}", 'js') ?>: {
                            required: "<?= lang('Items.quantity_required') ?>",
                            number: "<?= lang('Items.quantity_number') ?>"
                        },
                    <?php } ?>
                    receiving_quantity: {
                        required: "<?= lang('Items.quantity_required') ?>",
                        number: "<?= lang('Items.quantity_number') ?>"
                    },
                    reorder_level: {
                        required: "<?= lang('Items.reorder_level_required') ?>",
                        number: "<?= lang('Items.reorder_level_number') ?>"
                    },
                    tax_percent: {
                        number: "<?= lang('Items.tax_percent_number') ?>"
                    }
                }
            }, form_support.error))
        };

        init_validation();
    });
</script>

<?php if ($minimumPriceEnabled): ?>
<script type="text/javascript">
    $(document).ready(function() {
        const showMargin = function() {
            const price = parseFloat(String($('#unit_price').val()).replace(',', '.'));
            const cost = parseFloat(String($('#cost_price').val()).replace(',', '.'));

            $('#item_margin').text(price > 0 && !isNaN(cost) ? (((price - cost) / price) * 100).toFixed(2) + '%' : '');
        };

        $('#unit_price, #cost_price').on('keyup change', showMargin);
    });
</script>
<?php endif; ?>
