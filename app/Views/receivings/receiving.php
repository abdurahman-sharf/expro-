<?php
/**
 * @var string $controller_name
 * @var array  $modes
 * @var string $mode
 * @var bool   $show_stock_locations
 * @var array  $stock_locations
 * @var int    $stock_source
 * @var string $stock_destination
 * @var array  $cart
 * @var bool   $items_module_allowed
 * @var float  $total
 * @var string $comment
 * @var bool   $print_after_sale
 * @var string $reference
 * @var array  $payment_options
 * @var array  $config
 * @var string $tax_total
 * @var string $total_with_tax
 * @var App\Libraries\Accounting\InvoiceDiscount $invoice_discount
 * @var string $invoice_discount_amount
 * @var int|null $return_origin
 * @var array{from: string, to: string}|null $between_branches
 */
$sectionName = ucfirst($controller_name);
$hasCart     = count($cart) > 0;
?>

<?= view('partial/header') ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<?php if (! empty($warning)): ?>
    <div class="alert alert-warning"><?= esc($warning) ?></div>
<?php endif; ?>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?= esc($success) ?></div>
<?php endif; ?>

<div class="pos" id="pos">
    <div class="pos-main" id="register_wrapper">

        <?= form_open("{$controller_name}/changeMode", ['id' => 'mode_form', 'class' => 'app-form card']) ?>
            <div class="card-body pos-bar">
                <div class="pos-field">
                    <label for="register_mode" class="pos-label"><?= lang("{$sectionName}.mode") ?></label>
                    <?= form_dropdown('mode', $modes, $mode, ['id' => 'register_mode', 'onchange' => "$('#mode_form').submit();", 'class' => 'form-select form-select-sm']) ?>
                </div>

                <?php if ($show_stock_locations): ?>
                    <div class="pos-field">
                        <label for="register_stock_source" class="pos-label"><?= lang("{$sectionName}.stock_source") ?></label>
                        <?= form_dropdown('stock_source', $stock_locations, $stock_source, ['id' => 'register_stock_source', 'onchange' => "$('#mode_form').submit();", 'class' => 'form-select form-select-sm']) ?>
                    </div>

                    <?php if ($mode == 'requisition'): ?>
                        <div class="pos-field">
                            <label for="register_stock_destination" class="pos-label"><?= lang("{$sectionName}.stock_destination") ?></label>
                            <?= form_dropdown('stock_destination', $stock_locations, $stock_destination, ['id' => 'register_stock_destination', 'onchange' => "$('#mode_form').submit();", 'class' => 'form-select form-select-sm']) ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php if (! empty($between_branches)): ?>
                <div class="card-footer form-text" id="between_branches_note"><?= esc(lang("{$sectionName}.between_branches", [$between_branches['from'], $between_branches['to']])) ?></div>
            <?php endif; ?>
        <?= form_close() ?>

        <?= form_open("{$controller_name}/add", ['id' => 'add_item_form', 'class' => 'app-form card']) ?>
            <div class="card-body">
                <label for="item" class="pos-label">
                    <?= lang($sectionName . (($mode == 'receive' || $mode == 'requisition') ? '.find_or_scan_item' : '.find_or_scan_item_or_receipt')) ?>
                </label>
                <div class="pos-scan">
                    <span class="bi bi-upc-scan pos-scan-icon" aria-hidden="true"></span>
                    <?= form_input([
                        'name'         => 'item',
                        'id'           => 'item',
                        'class'        => 'form-control pos-scan-input',
                        'placeholder'  => lang('Sales.start_typing_item_name'),
                        'autocomplete' => 'off',
                    ]) ?>
                    <button type="submit" class="btn btn-primary pos-scan-add"><?= lang('Sales.scan_add') ?></button>
                    <button type="button" id="new_item_button" class="btn btn-outline-secondary modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-btn-new="<?= lang('Common.new') ?>" data-href="items/view" title="<?= lang('Sales.new_item') ?>">
                        <span class="bi bi-plus-lg" aria-hidden="true"></span>
                        <span class="pos-scan-new-text"><?= lang('Sales.new_item') ?></span>
                    </button>
                </div>
            </div>
        <?= form_close() ?>

        <div class="card pos-cart" id="register">
            <div class="card-header pos-cart-title">
                <h2 class="h6 mb-0"><?= lang('Sales.cart') ?></h2>
                <?php if ($hasCart): ?>
                    <span class="badge text-bg-primary"><?= count($cart) ?></span>
                <?php endif; ?>
            </div>

            <?php if (! $hasCart): ?>
                <div class="pos-empty">
                    <span class="pos-empty-icon"><span class="bi bi-box-arrow-in-down" aria-hidden="true"></span></span>
                    <p class="mb-0"><?= lang('Sales.no_items_in_cart') ?></p>
                </div>
            <?php else: ?>
                <div class="cart-row cart-row-receiving cart-head" aria-hidden="true">
                    <span><?= lang("{$sectionName}.item_name") ?></span>
                    <span><?= lang("{$sectionName}.cost") ?></span>
                    <span><?= lang("{$sectionName}.quantity") ?></span>
                    <span><?= lang("{$sectionName}.ship_pack") ?></span>
                    <span><?= lang("{$sectionName}.discount") ?></span>
                    <span><?= lang("{$sectionName}.tax") ?></span>
                    <span class="num"><?= lang("{$sectionName}.total") ?></span>
                    <span></span>
                </div>

                <div id="cart_contents">
                    <?php foreach (array_reverse($cart, true) as $line => $item): ?>
                        <?php $canEditCost = $items_module_allowed && $mode != 'requisition'; ?>
                        <?= form_open("{$controller_name}/editItem/{$line}", ['class' => 'app-form cart-row cart-row-receiving cart-line', 'id' => "cart_{$line}"]) ?>
                            <?= form_hidden('location', (string) $item['item_location']) ?>

                            <div class="cart-name">
                                <span class="cart-title"><?= esc($item['name'] . ' ' . implode(' ', [$item['attribute_values'], $item['attribute_dtvalues']])) ?></span>
                                <span class="cart-sub">
                                    <span class="ltr"><?= esc($item['item_number']) ?></span>
                                    <span class="cart-stock"><?= esc($item['stock_name']) ?>: <?= to_quantity_decimals($item['in_stock']) ?></span>
                                </span>
                            </div>

                            <div class="cart-field" data-label="<?= lang("{$sectionName}.cost") ?>">
                                <?php if ($canEditCost): ?>
                                    <?= form_input(['name' => 'price', 'class' => 'form-control form-control-sm', 'value' => to_currency_no_money($item['price']), 'inputmode' => 'decimal', 'aria-label' => lang("{$sectionName}.cost"), 'onClick' => 'this.select();']) ?>
                                <?php else: ?>
                                    <span class="num"><?= to_currency($item['price']) ?></span>
                                    <?= form_hidden('price', to_currency_no_money($item['price'])) ?>
                                <?php endif; ?>
                            </div>

                            <div class="cart-field" data-label="<?= lang("{$sectionName}.quantity") ?>">
                                <div class="qty">
                                    <button type="button" class="qty-b" data-step="-1" aria-label="<?= lang('Sales.decrease_quantity') ?>"><span class="bi bi-dash-lg" aria-hidden="true"></span></button>
                                    <?= form_input(['name' => 'quantity', 'class' => 'qty-v', 'value' => to_quantity_decimals($item['quantity']), 'inputmode' => 'decimal', 'aria-label' => lang("{$sectionName}.quantity"), 'onClick' => 'this.select();']) ?>
                                    <button type="button" class="qty-b" data-step="1" aria-label="<?= lang('Sales.increase_quantity') ?>"><span class="bi bi-plus-lg" aria-hidden="true"></span></button>
                                </div>
                            </div>

                            <div class="cart-field" data-label="<?= lang("{$sectionName}.ship_pack") ?>">
                                <?= form_dropdown(
                                    'receiving_quantity',
                                    $item['receiving_quantity_choices'],
                                    $item['receiving_quantity'],
                                    ['class' => 'form-select form-select-sm', 'aria-label' => lang("{$sectionName}.ship_pack")],
                                ) ?>
                            </div>

                            <div class="cart-field" data-label="<?= lang("{$sectionName}.discount") ?>">
                                <?php if ($canEditCost): ?>
                                    <div class="input-group input-group-sm">
                                        <?= form_input(['name' => 'discount', 'class' => 'form-control form-control-sm', 'value' => $item['discount_type'] ? to_currency_no_money($item['discount']) : to_decimals($item['discount']), 'inputmode' => 'decimal', 'aria-label' => lang("{$sectionName}.discount"), 'onClick' => 'this.select();']) ?>
                                        <?= toggleButton(['id' => "discount_toggle_{$line}", 'name' => 'discount_toggle', 'value' => 1, 'data-line' => $line, 'checked' => $item['discount_type'] == 1], $config['currency_symbol'], '%') ?>
                                    </div>
                                <?php else: ?>
                                    <span class="num"><?= esc($item['discount']) ?></span>
                                    <?= form_hidden('discount', (string) $item['discount']) ?>
                                <?php endif; ?>
                            </div>

                            <div class="cart-field" data-label="<?= lang("{$sectionName}.tax") ?>">
                                <?php if ($canEditCost): ?>
                                    <?= form_input(['name' => 'tax_percent', 'class' => 'form-control form-control-sm', 'value' => to_decimals($item['tax_percent'] ?? 0), 'inputmode' => 'decimal', 'aria-label' => lang("{$sectionName}.tax"), 'onClick' => 'this.select();']) ?>
                                <?php else: ?>
                                    <span class="num"><?= $mode == 'requisition' ? '' : esc(to_decimals($item['tax_percent'] ?? 0)) ?></span>
                                    <?= form_hidden('tax_percent', (string) ($item['tax_percent'] ?? 0)) ?>
                                <?php endif; ?>
                            </div>

                            <div class="cart-field cart-total" data-label="<?= lang("{$sectionName}.total") ?>">
                                <span class="num"><?= to_currency($item['total']) ?></span>
                            </div>

                            <div class="cart-actions">
                                <button type="submit" class="btn btn-icon btn-sm btn-outline-secondary" title="<?= lang("{$sectionName}.update") ?>" aria-label="<?= lang("{$sectionName}.update") ?>">
                                    <span class="bi bi-arrow-repeat" aria-hidden="true"></span>
                                </button>
                                <?= anchor(
                                    "{$controller_name}/deleteItem/{$line}",
                                    '<span class="bi bi-trash" aria-hidden="true"></span>',
                                    ['class' => 'btn btn-icon btn-sm btn-outline-danger', 'title' => lang('Common.delete'), 'aria-label' => lang('Common.delete')],
                                ) ?>
                            </div>

                            <div class="cart-extra">
                                <?php if ($item['allow_alt_description'] == 1): ?>
                                    <?= form_input(['name' => 'description', 'class' => 'form-control form-control-sm', 'value' => $item['description'], 'aria-label' => lang('Sales.description_abbrv'), 'placeholder' => lang('Sales.description_abbrv')]) ?>
                                <?php elseif ($item['description'] != ''): ?>
                                    <span class="text-secondary"><?= esc($item['description']) ?></span>
                                    <?= form_hidden('description', $item['description']) ?>
                                <?php else: ?>
                                    <?= form_hidden('description', '') ?>
                                <?php endif; ?>
                            </div>
                        <?= form_close() ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <aside class="pos-side" id="overall_sale">
        <div class="card">
            <div class="card-body">
                <?php if (isset($supplier)): ?>
                    <div class="pos-customer-head">
                        <span class="pos-label mb-0"><?= lang("{$sectionName}.supplier") ?></span>
                        <strong><?= esc($supplier) ?></strong>
                    </div>
                    <dl class="pos-dl">
                        <?php if (! empty($supplier_email)): ?>
                            <div><dt><?= lang("{$sectionName}.supplier_email") ?></dt><dd class="ltr"><?= esc($supplier_email) ?></dd></div>
                        <?php endif; ?>
                        <?php if (! empty($supplier_address)): ?>
                            <div><dt><?= lang("{$sectionName}.supplier_address") ?></dt><dd><?= esc($supplier_address) ?></dd></div>
                        <?php endif; ?>
                        <?php if (! empty($supplier_location)): ?>
                            <div><dt><?= lang("{$sectionName}.supplier_location") ?></dt><dd><?= esc($supplier_location) ?></dd></div>
                        <?php endif; ?>
                    </dl>
                    <?= anchor(
                        "{$controller_name}/removeSupplier",
                        '<span class="bi bi-x-lg" aria-hidden="true"></span> ' . lang('Common.remove') . ' ' . lang('Suppliers.supplier'),
                        ['class' => 'btn btn-outline-danger btn-sm', 'id' => 'remove_supplier_button', 'title' => lang('Common.remove') . ' ' . lang('Suppliers.supplier')],
                    ) ?>
                <?php else: ?>
                    <?= form_open("{$controller_name}/selectSupplier", ['id' => 'select_supplier_form', 'class' => 'app-form']) ?>
                        <div id="select_customer">
                            <label id="supplier_label" for="supplier" class="pos-label"><?= lang("{$sectionName}.select_supplier") ?></label>
                            <div class="pos-customer-pick">
                                <?= form_input(['name' => 'supplier', 'id' => 'supplier', 'class' => 'form-control', 'placeholder' => lang("{$sectionName}.start_typing_supplier_name"), 'autocomplete' => 'off']) ?>
                                <button type="button" id="new_supplier_button" class="btn btn-outline-primary modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="suppliers/view" title="<?= lang("{$sectionName}.new_supplier") ?>" aria-label="<?= lang("{$sectionName}.new_supplier") ?>">
                                    <span class="bi bi-person-plus" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                    <?= form_close() ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($mode == 'return'): ?>
            <div class="card">
                <div class="card-body">
                    <label for="return_origin" class="pos-label"><?= lang("{$sectionName}.return_origin") ?></label>
                    <?= form_input(['name' => 'return_origin', 'id' => 'return_origin', 'class' => 'form-control', 'value' => $return_origin === null ? '' : (string) $return_origin, 'inputmode' => 'numeric', 'aria-describedby' => 'return_origin_help']) ?>
                    <div class="form-text" id="return_origin_help"><?= lang("{$sectionName}.return_origin_help") ?></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($mode == 'receive' && $hasCart): ?>
            <div class="card">
                <div class="card-body">
                    <?= form_open("{$controller_name}/setInvoiceDiscount", ['id' => 'invoice_discount_form', 'class' => 'app-form']) ?>
                        <label for="invoice_discount" class="pos-label"><?= lang("{$sectionName}.invoice_discount") ?></label>
                        <div class="input-group">
                            <?= form_input(['name' => 'invoice_discount', 'id' => 'invoice_discount', 'class' => 'form-control', 'value' => $invoice_discount->isNone() ? '' : ($invoice_discount->type === FIXED ? to_currency_no_money($invoice_discount->value) : to_decimals($invoice_discount->value)), 'inputmode' => 'decimal', 'dir' => 'ltr', 'aria-describedby' => 'invoice_discount_help']) ?>
                            <?= form_dropdown('invoice_discount_type', [PERCENT => '%', FIXED => $config['currency_symbol']], (string) $invoice_discount->type, ['id' => 'invoice_discount_type', 'class' => 'form-select', 'aria-label' => lang("{$sectionName}.invoice_discount_type")]) ?>
                            <button type="submit" class="btn btn-outline-primary" id="invoice_discount_apply"><?= lang("{$sectionName}.invoice_discount_apply") ?></button>
                        </div>
                        <?php if (! $invoice_discount->isNone()): ?>
                            <button type="button" class="btn btn-link btn-sm px-0" id="invoice_discount_remove"><?= lang("{$sectionName}.invoice_discount_remove") ?></button>
                        <?php endif; ?>
                        <div class="form-text" id="invoice_discount_help"><?= lang("{$sectionName}.invoice_discount_help") ?></div>
                    <?= form_close() ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($mode != 'requisition'): ?>
            <?php $hasInvoiceDiscount = bccomp((string) $invoice_discount_amount, '0', 4) !== 0; ?>
            <div class="card">
                <div class="card-body">
                    <dl class="pos-sum" id="sale_totals">
                        <?php if (bccomp((string) $tax_total, '0', 8) > 0 || $hasInvoiceDiscount): ?>
                            <div><dt><?= lang("{$sectionName}.sub_total") ?></dt><dd><?= to_currency($total) ?></dd></div>
                        <?php endif; ?>
                        <?php if ($hasInvoiceDiscount): ?>
                            <div id="receiving_invoice_discount"><dt><?= lang($mode == 'receive' ? "{$sectionName}.invoice_discount_amount" : "{$sectionName}.invoice_discount_share") ?><?= $invoice_discount->type === PERCENT && ! $invoice_discount->isNone() ? ' (' . to_decimals($invoice_discount->value) . '%)' : '' ?></dt><dd><?= to_currency($invoice_discount_amount) ?></dd></div>
                        <?php endif; ?>
                        <?php if (bccomp((string) $tax_total, '0', 8) > 0): ?>
                            <div id="receiving_tax"><dt><?= lang("{$sectionName}.tax_total") ?></dt><dd><?= to_currency($tax_total) ?></dd></div>
                        <?php endif; ?>
                        <div class="pos-sum-total"><dt><?= lang("{$sectionName}.total_with_tax") ?></dt><dd><?= to_currency($total_with_tax) ?></dd></div>
                    </dl>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($hasCart): ?>
            <div id="finish_sale">
                <?= form_open($mode == 'requisition' ? "{$controller_name}/requisitionComplete" : "{$controller_name}/complete", ['id' => 'finish_receiving_form', 'class' => 'app-form pos-actions']) ?>
                    <div class="card">
                        <div class="card-body pos-options" id="payment_details">
                            <div>
                                <label for="comment" class="pos-label" id="comment_label"><?= lang('Common.comments') ?></label>
                                <?= form_textarea(['name' => 'comment', 'id' => 'comment', 'class' => 'form-control', 'value' => $comment, 'rows' => '3']) ?>
                            </div>

                            <?php if ($mode != 'requisition'): ?>
                                <?php if ($mode == 'receive'): ?>
                                    <div>
                                        <label for="recv_reference" class="pos-label"><?= lang("{$sectionName}.reference") ?></label>
                                        <?= form_input(['name' => 'recv_reference', 'id' => 'recv_reference', 'class' => 'form-control', 'value' => $reference]) ?>
                                    </div>
                                <?php endif; ?>

                                <div>
                                    <label for="payment_types" class="pos-label"><?= lang('Sales.payment') ?></label>
                                    <?= form_dropdown('payment_type', $payment_options, [], ['id' => 'payment_types', 'class' => 'form-select']) ?>
                                </div>

                                <div>
                                    <label for="amount_tendered" class="pos-label"><?= lang('Sales.amount_tendered') ?></label>
                                    <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'value' => '', 'class' => 'form-control', 'inputmode' => 'decimal']) ?>
                                </div>

                                <label for="recv_print_after_sale" class="choice">
                                    <?= form_checkbox(['name' => 'recv_print_after_sale', 'id' => 'recv_print_after_sale', 'value' => 1, 'checked' => $print_after_sale == 1]) ?>
                                    <?= lang("{$sectionName}.print_after_sale") ?>
                                </label>
                            <?php endif; ?>
                        </div>
                    </div>

                    <button type="button" class="btn btn-success btn-lg" id="finish_receiving_button">
                        <span class="bi bi-check-lg" aria-hidden="true"></span>
                        <?= lang("{$sectionName}.complete_receiving") ?>
                    </button>
                    <button type="button" class="btn btn-outline-danger" id="cancel_receiving_button">
                        <span class="bi bi-x-lg" aria-hidden="true"></span>
                        <?= lang("{$sectionName}.cancel_receiving") ?>
                    </button>
                <?= form_close() ?>
            </div>
        <?php endif; ?>
    </aside>

    <?php if ($hasCart): ?>
        <div class="pos-total-bar" id="pos_total_bar">
            <div class="pos-total-text">
                <?php if ($mode != 'requisition'): ?>
                    <span><?= lang('Sales.total') ?></span>
                    <strong id="pos_total_value"><?= to_currency($total_with_tax) ?></strong>
                <?php endif; ?>
            </div>
            <button type="button" class="btn btn-primary btn-lg" id="pos_total_action"><?= lang("{$sectionName}.complete_receiving") ?></button>
        </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $("#item").autocomplete({
            source: '<?= esc("{$controller_name}/stockItemSearch") ?>',
            minChars: 0,
            delay: 10,
            autoFocus: false,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $("#add_item_form").submit();
                return false;
            }
        });

        $('#item').focus();

        $('#item').keypress(function(e) {
            if (e.which == 13) {
                $('#add_item_form').submit();
                return false;
            }
        });

        $('#item, #supplier').dblclick(function() {
            $(this).autocomplete('search');
        });

        $('#comment').keyup(function() {
            $.post('<?= esc("{$controller_name}/setComment") ?>', {
                comment: $('#comment').val()
            });
        });

        $('#recv_reference').keyup(function() {
            $.post('<?= esc("{$controller_name}/setReference") ?>', {
                recv_reference: $('#recv_reference').val()
            });
        });

        $('#return_origin').change(function() {
            $.post('<?= esc("{$controller_name}/setReturnOrigin") ?>', {
                recv_return_origin: $('#return_origin').val()
            }, function(response) {
                if (!response.success) {
                    $.notify({
                        message: response.message
                    }, {
                        type: 'danger'
                    });
                    $('#return_origin').val('');
                }
            }, 'json');
        });

        $('#invoice_discount_remove').click(function() {
            $('#invoice_discount').val('');
            $('#invoice_discount_form').submit();
        });

        $("#recv_print_after_sale").change(function() {
            $.post('<?= esc("{$controller_name}/setPrintAfterSale") ?>', {
                recv_print_after_sale: $(this).is(":checked")
            });
        });

        $("#supplier").autocomplete({
            source: '<?= 'suppliers/suggest' ?>',
            minChars: 0,
            delay: 10,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $("#select_supplier_form").submit();
            }
        });

        $('#supplier').keypress(function(e) {
            if (e.which == 13) {
                $('#select_supplier_form').submit();
                return false;
            }
        });

        $("#finish_receiving_button").click(function() {
            $('#finish_receiving_form').submit();
        });

        $("#cancel_receiving_button").click(function() {
            if (confirm('<?= lang(ucfirst($controller_name) . '.confirm_cancel_receiving') ?>')) {
                $('#finish_receiving_form').attr('action', '<?= esc("{$controller_name}/cancelReceiving") ?>');
                $('#finish_receiving_form').submit();
            }
        });

        table_support.handle_submit = function(resource, response, stay_open) {
            if (response.success) {
                if (resource.match(/suppliers$/)) {
                    $("#supplier").val(response.id);
                    $("#select_supplier_form").submit();
                } else {
                    $("#item").val(response.id);
                    if (stay_open) {
                        $("#add_item_form").ajaxSubmit();
                    } else {
                        $("#add_item_form").submit();
                    }
                }
            }
        }

        $('[name="price"],[name="quantity"],[name="receiving_quantity"],[name="discount"],[name="tax_percent"],[name="description"],[name="serialnumber"]').change(function() {
            $(this).closest('form').submit();
        });

        $('[name="discount_toggle"]').change(function() {
            const input = $("<input>").attr("type", "hidden").attr("name", "discount_type").val(($(this).prop('checked')) ? 1 : 0);
            $('#cart_' + $(this).attr('data-line')).append($(input));
            $('#cart_' + $(this).attr('data-line')).submit();
        });

    });
</script>

<?= view('partial/footer') ?>
