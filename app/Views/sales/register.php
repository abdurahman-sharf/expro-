<?php
/**
 * @var string    $controller_name
 * @var array     $modes
 * @var array     $mode
 * @var array     $empty_tables
 * @var array     $selected_table
 * @var array     $stock_locations
 * @var array     $stock_location
 * @var array     $cart
 * @var bool      $items_module_allowed
 * @var bool      $change_price
 * @var int       $customer_id
 * @var int       $customer_discount_type
 * @var float     $customer_discount
 * @var float     $customer_total
 * @var string    $customer_required
 * @var float|int $item_count
 * @var float|int $total_units
 * @var float     $subtotal
 * @var array     $taxes
 * @var float     $total
 * @var float     $payments_total
 * @var float     $amount_due
 * @var bool      $payments_cover_total
 * @var array     $payment_options
 * @var array     $selected_payment_type
 * @var bool      $pos_mode
 * @var array     $payments
 * @var string    $mode_label
 * @var string    $comment
 * @var bool      $print_after_sale
 * @var bool      $email_receipt
 * @var bool      $price_work_orders
 * @var string    $invoice_number
 * @var int       $cash_mode
 * @var float     $non_cash_total
 * @var float     $cash_amount_due
 * @var array     $config
 */

use App\Models\Employee;

$keyboardShortcuts ??= [];
$sectionName   = ucfirst($controller_name);
$shortcutLabel = static fn (string $name): string => $keyboardShortcuts[$name]['label'] ?? '';
$hasCart       = count($cart) > 0;
$hasDuePayment = false;

foreach ($payments as $payment) {
    if ($payment['payment_type'] == lang("{$sectionName}.due")) {
        $hasDuePayment = true;
    }
}
$canFinishSale   = $pos_mode && $payments_cover_total && (! $hasDuePayment || isset($customer));
$numberFormatter = new NumberFormatter(numberFormatterLocale($config['number_locale']), NumberFormatter::DECIMAL);
$employee        = model(Employee::class);
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

                <?php if ($config['dinner_table_enable']): ?>
                    <div class="pos-field">
                        <label for="register_table" class="pos-label"><?= lang("{$sectionName}.table") ?></label>
                        <?= form_dropdown('dinner_table', $empty_tables, $selected_table, ['id' => 'register_table', 'onchange' => "$('#mode_form').submit();", 'class' => 'form-select form-select-sm']) ?>
                    </div>
                <?php endif; ?>

                <?php if (count($stock_locations) > 1): ?>
                    <div class="pos-field">
                        <label for="register_stock_location" class="pos-label"><?= lang("{$sectionName}.stock_location") ?></label>
                        <?= form_dropdown('stock_location', $stock_locations, $stock_location, ['id' => 'register_stock_location', 'onchange' => "$('#mode_form').submit();", 'class' => 'form-select form-select-sm']) ?>
                    </div>
                <?php endif; ?>

                <span class="pos-spacer"></span>

                <button type="button" class="btn btn-outline-secondary btn-sm modal-dlg" id="show_suspended_sales_button" data-href="<?= esc("{$controller_name}/suspended") ?>" title="<?= lang("{$sectionName}.suspended_sales") ?>">
                    <span class="bi bi-pause-circle" aria-hidden="true"></span>
                    <?= lang("{$sectionName}.suspended_sales") ?>
                    <span class="kbd"><?= esc($shortcutLabel('suspended')) ?></span>
                </button>

                <?php if ($employee->has_grant('reports_sales', session('person_id'))): ?>
                    <?= anchor(
                        "{$controller_name}/manage",
                        '<span class="bi bi-card-list" aria-hidden="true"></span> ' . lang("{$sectionName}.takings"),
                        ['class' => 'btn btn-outline-secondary btn-sm', 'id' => 'sales_takings_button', 'title' => lang("{$sectionName}.takings")],
                    ) ?>
                <?php endif; ?>
            </div>
        <?= form_close() ?>

        <?= form_open("{$controller_name}/add", ['id' => 'add_item_form', 'class' => 'app-form card']) ?>
            <div class="card-body">
                <label for="item" class="pos-label">
                    <?= lang("{$sectionName}.find_or_scan_item_or_receipt") ?>
                    <span class="kbd"><?= esc($shortcutLabel('items')) ?></span>
                </label>
                <div class="pos-scan">
                    <span class="bi bi-upc-scan pos-scan-icon" aria-hidden="true"></span>
                    <?= form_input([
                        'name'         => 'item',
                        'id'           => 'item',
                        'class'        => 'form-control pos-scan-input',
                        'placeholder'  => lang("{$sectionName}.start_typing_item_name"),
                        'autocomplete' => 'off',
                    ]) ?>
                    <span class="ui-helper-hidden-accessible" role="status"></span>
                    <button type="submit" class="btn btn-primary pos-scan-add"><?= lang('Sales.scan_add') ?></button>
                    <button type="button" id="new_item_button" class="btn btn-outline-secondary modal-dlg" data-btn-new="<?= lang('Common.new') ?>" data-btn-submit="<?= lang('Common.submit') ?>" data-href="items/view" title="<?= lang("{$sectionName}.new_item") ?>">
                        <span class="bi bi-plus-lg" aria-hidden="true"></span>
                        <span class="pos-scan-new-text"><?= lang("{$sectionName}.new_item") ?></span>
                    </button>
                </div>
            </div>
        <?= form_close() ?>

        <div class="card pos-cart" id="register">
            <div class="card-header pos-cart-title">
                <h2 class="h6 mb-0"><?= lang('Sales.cart') ?></h2>
                <?php if ($hasCart): ?>
                    <span class="badge text-bg-primary"><?= esc($item_count) ?></span>
                <?php endif; ?>
            </div>

            <?php if (! $hasCart): ?>
                <div class="pos-empty">
                    <span class="pos-empty-icon"><span class="bi bi-cart3" aria-hidden="true"></span></span>
                    <p class="mb-0"><?= lang("{$sectionName}.no_items_in_cart") ?></p>
                </div>
            <?php else: ?>
                <div class="cart-row cart-head" aria-hidden="true">
                    <span><?= lang("{$sectionName}.item_name") ?></span>
                    <span><?= lang("{$sectionName}.price") ?></span>
                    <span><?= lang("{$sectionName}.quantity") ?></span>
                    <span><?= lang("{$sectionName}.discount") ?></span>
                    <span class="num"><?= lang("{$sectionName}.total") ?></span>
                    <span></span>
                </div>

                <div id="cart_contents">
                    <?php foreach (array_reverse($cart, true) as $line => $item): ?>
                        <?= form_open("{$controller_name}/editItem/{$line}", ['class' => 'app-form cart-row cart-line', 'id' => "cart_{$line}"]) ?>
                            <?= form_hidden('location', (string) $item['item_location']) ?>
                            <?= form_input(['type' => 'hidden', 'name' => 'item_id', 'value' => $item['item_id']]) ?>

                            <div class="cart-name">
                                <?php if ($item['item_type'] == ITEM_TEMP): ?>
                                    <?= form_input(['name' => 'item_number', 'class' => 'form-control form-control-sm', 'value' => $item['item_number'], 'aria-label' => lang("{$sectionName}.item_number"), 'placeholder' => lang("{$sectionName}.item_number")]) ?>
                                    <?= form_input(['name' => 'name', 'class' => 'form-control form-control-sm', 'value' => $item['name'], 'aria-label' => lang("{$sectionName}.item_name"), 'placeholder' => lang("{$sectionName}.item_name")]) ?>
                                <?php else: ?>
                                    <span class="cart-title"><?= esc($item['name']) . ' ' . esc(implode(' ', [$item['attribute_values'], $item['attribute_dtvalues']])) ?></span>
                                    <span class="cart-sub">
                                        <span class="ltr"><?= esc($item['item_number']) ?></span>
                                        <?php if ($item['stock_type'] == '0'): ?>
                                            <span class="cart-stock"><?= esc($item['stock_name']) ?>: <?= to_quantity_decimals($item['in_stock']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div class="cart-field" data-label="<?= lang("{$sectionName}.price") ?>">
                                <?php if ($items_module_allowed && $change_price): ?>
                                    <?= form_input(['name' => 'price', 'class' => 'form-control form-control-sm', 'value' => to_currency_no_money($item['price']), 'inputmode' => 'decimal', 'aria-label' => lang("{$sectionName}.price"), 'onClick' => 'this.select();']) ?>
                                <?php else: ?>
                                    <span class="num"><?= to_currency($item['price']) ?></span>
                                    <?= form_hidden('price', to_currency_no_money($item['price'])) ?>
                                <?php endif; ?>
                            </div>

                            <div class="cart-field" data-label="<?= lang("{$sectionName}.quantity") ?>">
                                <?php if ($item['is_serialized']): ?>
                                    <span class="num"><?= to_quantity_decimals($item['quantity']) ?></span>
                                    <?= form_hidden('quantity', $item['quantity']) ?>
                                <?php else: ?>
                                    <div class="qty">
                                        <button type="button" class="qty-b" data-step="-1" aria-label="<?= lang('Sales.decrease_quantity') ?>"><span class="bi bi-dash-lg" aria-hidden="true"></span></button>
                                        <?= form_input(['name' => 'quantity', 'class' => 'qty-v', 'value' => to_quantity_decimals($item['quantity']), 'inputmode' => 'decimal', 'aria-label' => lang("{$sectionName}.quantity"), 'onClick' => 'this.select();']) ?>
                                        <button type="button" class="qty-b" data-step="1" aria-label="<?= lang('Sales.increase_quantity') ?>"><span class="bi bi-plus-lg" aria-hidden="true"></span></button>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="cart-field" data-label="<?= lang("{$sectionName}.discount") ?>">
                                <div class="input-group input-group-sm">
                                    <?= form_input(['name' => 'discount', 'class' => 'form-control form-control-sm', 'value' => $item['discount_type'] ? to_currency_no_money($item['discount']) : to_decimals($item['discount']), 'inputmode' => 'decimal', 'aria-label' => lang("{$sectionName}.discount"), 'onClick' => 'this.select();']) ?>
                                    <?= toggleButton(['id' => "discount_toggle_{$line}", 'name' => 'discount_toggle', 'value' => 1, 'data-line' => $line, 'checked' => $item['discount_type'] == 1], $config['currency_symbol'], '%') ?>
                                </div>
                            </div>

                            <div class="cart-field cart-total" data-label="<?= lang("{$sectionName}.total") ?>">
                                <?php if ($item['item_type'] == ITEM_AMOUNT_ENTRY): ?>
                                    <?= form_input(['name' => 'discounted_total', 'class' => 'form-control form-control-sm', 'value' => to_currency_no_money($item['discounted_total']), 'inputmode' => 'decimal', 'aria-label' => lang("{$sectionName}.total"), 'onClick' => 'this.select();']) ?>
                                <?php else: ?>
                                    <span class="num"><?= to_currency($item['discounted_total']) ?></span>
                                <?php endif; ?>
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
                                <?php if ($item['item_type'] == ITEM_TEMP): ?>
                                    <?= form_input(['name' => 'item_description', 'class' => 'form-control form-control-sm', 'value' => $item['description'], 'aria-label' => lang("{$sectionName}.description_abbrv"), 'placeholder' => lang("{$sectionName}.description_abbrv")]) ?>
                                <?php else: ?>
                                    <?php if ($item['allow_alt_description']): ?>
                                        <?= form_input(['name' => 'description', 'class' => 'form-control form-control-sm', 'value' => $item['description'], 'aria-label' => lang("{$sectionName}.description_abbrv"), 'placeholder' => lang("{$sectionName}.description_abbrv"), 'onClick' => 'this.select();']) ?>
                                    <?php elseif ($item['description'] != ''): ?>
                                        <span class="text-secondary"><?= esc($item['description']) ?></span>
                                        <?= form_hidden('description', $item['description']) ?>
                                    <?php else: ?>
                                        <?= form_hidden('description', '') ?>
                                    <?php endif; ?>

                                    <?php if ($item['is_serialized']): ?>
                                        <?= form_input(['name' => 'serialnumber', 'class' => 'form-control form-control-sm', 'value' => $item['serialnumber'], 'aria-label' => lang("{$sectionName}.serial"), 'placeholder' => lang("{$sectionName}.serial"), 'onClick' => 'this.select();']) ?>
                                    <?php else: ?>
                                        <?= form_hidden('serialnumber', '') ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?= form_close() ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="pos-keys">
            <span class="pos-key-list" aria-label="<?= lang('Sales.key_help') ?>">
                <?php foreach (['items' => 'key_item_search', 'customers' => 'key_customer_search', 'suspend' => 'key_suspend', 'payment' => 'key_payment', 'complete' => 'key_finish_sale', 'cancel' => 'key_cancel'] as $name => $langKey): ?>
                    <span class="pos-key"><span class="kbd"><?= esc($shortcutLabel($name)) ?></span> <?= lang("Sales.{$langKey}") ?></span>
                <?php endforeach; ?>
            </span>
            <button type="button" class="btn btn-link btn-sm p-0 modal-dlg" id="show_keyboard_help" data-href="<?= esc("{$controller_name}/salesKeyboardHelp") ?>" title="<?= lang("{$sectionName}.key_title") ?>">
                <?= lang("{$sectionName}.key_help") ?> <span class="kbd"><?= esc($shortcutLabel('help')) ?></span>
            </button>
        </div>
    </div>

    <aside class="pos-side" id="overall_sale">
        <div class="card">
            <div class="card-body">
                <?= form_open("{$controller_name}/selectCustomer", ['id' => 'select_customer_form', 'class' => 'app-form']) ?>
                    <?php if (isset($customer)): ?>
                        <div class="pos-customer-head">
                            <span class="pos-label mb-0"><?= lang("{$sectionName}.customer") ?></span>
                            <?= anchor("customers/view/{$customer_id}", esc($customer), ['class' => 'modal-dlg fw-bold', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Customers.update')]) ?>
                        </div>
                        <dl class="pos-dl">
                            <div><dt><?= lang("{$sectionName}.customer_discount") ?></dt><dd><?= ($customer_discount_type == FIXED) ? to_currency($customer_discount) : $customer_discount . '%' ?></dd></div>
                            <?php if ($config['customer_reward_enable'] && ! empty($customer_rewards)): ?>
                                <div><dt><?= lang("{$sectionName}.rewards_package") ?></dt><dd><?= esc($customer_rewards['package_name']) ?></dd></div>
                                <div><dt><?= lang('Customers.available_points') ?></dt><dd><?= esc($customer_rewards['points']) ?></dd></div>
                            <?php endif; ?>
                            <div><dt><?= lang("{$sectionName}.customer_total") ?></dt><dd><?= to_currency($customer_total) ?></dd></div>
                        </dl>
                        <?php if (! empty($customer_email) || ! empty($customer_address) || ! empty($customer_location) || ! empty($mailchimp_info)): ?>
                            <details class="pos-details">
                                <summary><?= lang('Customers.basic_information') ?></summary>
                                <dl class="pos-dl">
                                    <?php if (! empty($customer_email)): ?>
                                        <div><dt><?= lang("{$sectionName}.customer_email") ?></dt><dd class="ltr"><?= esc($customer_email) ?></dd></div>
                                    <?php endif; ?>
                                    <?php if (! empty($customer_address)): ?>
                                        <div><dt><?= lang("{$sectionName}.customer_address") ?></dt><dd><?= esc($customer_address) ?></dd></div>
                                    <?php endif; ?>
                                    <?php if (! empty($customer_location)): ?>
                                        <div><dt><?= lang("{$sectionName}.customer_location") ?></dt><dd><?= esc($customer_location) ?></dd></div>
                                    <?php endif; ?>
                                    <?php if (! empty($mailchimp_info)): ?>
                                        <div><dt><?= lang("{$sectionName}.customer_mailchimp_status") ?></dt><dd><?= esc($mailchimp_info['status']) ?></dd></div>
                                    <?php endif; ?>
                                </dl>
                            </details>
                        <?php endif; ?>
                        <?= anchor(
                            "{$controller_name}/removeCustomer",
                            '<span class="bi bi-x-lg" aria-hidden="true"></span> ' . lang('Common.remove') . ' ' . lang('Customers.customer'),
                            ['class' => 'btn btn-outline-danger btn-sm', 'id' => 'remove_customer_button', 'title' => lang('Common.remove') . ' ' . lang('Customers.customer')],
                        ) ?>
                    <?php else: ?>
                        <div id="select_customer">
                            <label id="customer_label" for="customer" class="pos-label">
                                <?= lang("{$sectionName}.select_customer") . esc(" {$customer_required}") ?>
                                <span class="kbd"><?= esc($shortcutLabel('customers')) ?></span>
                            </label>
                            <div class="pos-customer-pick">
                                <?= form_input(['name' => 'customer', 'id' => 'customer', 'class' => 'form-control', 'placeholder' => lang("{$sectionName}.start_typing_customer_name"), 'autocomplete' => 'off']) ?>
                                <button type="button" class="btn btn-outline-primary modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="customers/view" title="<?= lang("{$sectionName}.new_customer") ?>" aria-label="<?= lang("{$sectionName}.new_customer") ?>">
                                    <span class="bi bi-person-plus" aria-hidden="true"></span>
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                <?= form_close() ?>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <dl class="pos-sum" id="sale_totals">
                    <div><dt><?= lang("{$sectionName}.quantity_of_items", [$item_count]) ?></dt><dd><?= $total_units ?></dd></div>
                    <div><dt><?= lang("{$sectionName}.sub_total") ?></dt><dd><?= to_currency($subtotal) ?></dd></div>
                    <?php foreach ($taxes as $tax): ?>
                        <div><dt><?= (float) $tax['tax_rate'] . '% ' . esc($tax['tax_group']) ?></dt><dd><?= to_currency_tax($tax['sale_tax_amount']) ?></dd></div>
                    <?php endforeach; ?>
                    <div class="pos-sum-total"><dt><?= lang("{$sectionName}.total") ?></dt><dd id="sale_total"><?= to_currency($total) ?></dd></div>
                </dl>

                <?php if ($hasCart): ?>
                    <dl class="pos-sum pos-sum-payments" id="payment_totals">
                        <div><dt><?= lang("{$sectionName}.payments_total") ?></dt><dd><?= to_currency($payments_total) ?></dd></div>
                        <div class="pos-sum-due"><dt><?= lang("{$sectionName}.amount_due") ?></dt><dd id="sale_amount_due"><?= to_currency($amount_due) ?></dd></div>
                    </dl>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($hasCart): ?>
            <div class="card" id="payment_card">
                <div class="card-body" id="payment_details">
                    <?php if (! $payments_cover_total): ?>
                        <?= form_open("{$controller_name}/addPayment", ['id' => 'add_payment_form', 'class' => 'app-form']) ?>
                            <input type="hidden" name="complete_after_payment" value="0">

                            <label for="payment_types" class="pos-label"><?= lang("{$sectionName}.payment") ?></label>
                            <?= form_dropdown('payment_type', $payment_options, $selected_payment_type, ['id' => 'payment_types', 'class' => 'form-select', 'data-option-buttons' => 'true']) ?>

                            <label for="amount_tendered" class="pos-label mt-3"><span id="amount_tendered_label"><?= lang("{$sectionName}.amount_tendered") ?></span></label>
                            <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered', 'class' => 'form-control form-control-lg non-giftcard-input', 'value' => to_currency_no_money($amount_due), 'inputmode' => 'decimal', 'onClick' => 'this.select();']) ?>
                            <?= form_input(['name' => 'amount_tendered', 'id' => 'amount_tendered_giftcard', 'class' => 'form-control form-control-lg giftcard-input', 'disabled' => true, 'value' => to_currency_no_money($amount_due), 'autocomplete' => 'off']) ?>
                            <span class="kbd pos-kbd-inline"><?= esc($shortcutLabel('amount')) ?></span>

                            <div class="pos-quick" id="quick_amounts" data-exact-label="<?= lang('Sales.exact_amount') ?>"></div>

                            <div class="pos-change d-none" id="change_box">
                                <span><?= lang("{$sectionName}.change_due") ?></span>
                                <strong id="change_value"></strong>
                            </div>

                            <div class="reference-code-input reference-code-input-hidden">
                                <label for="reference_code" class="pos-label mt-3"><span id="reference_code_label"><?= lang('Sales.reference_code') ?></span></label>
                                <?= form_input(['name' => 'reference_code', 'id' => 'reference_code', 'class' => 'form-control non-giftcard-input', 'disabled' => true, 'value' => '', 'onClick' => 'this.select();']) ?>
                            </div>
                        <?= form_close() ?>

                        <button type="button" class="btn btn-primary btn-lg w-100 mt-3" id="add_payment_button">
                            <span class="bi bi-credit-card" aria-hidden="true"></span>
                            <?= lang("{$sectionName}.add_payment") ?>
                            <span class="kbd kbd-inverse"><?= esc($shortcutLabel('payment')) ?></span>
                        </button>
                    <?php endif; ?>

                    <?php if (count($payments) > 0): ?>
                        <ul class="pos-payments" id="payment_contents">
                            <?php foreach ($payments as $paymentId => $payment): ?>
                                <li>
                                    <span><?= $payment['payment_type'] ?></span>
                                    <strong><?= to_currency($payment['payment_amount']) ?></strong>
                                    <?= anchor(
                                        "{$controller_name}/deletePayment/" . esc(base64url_encode($paymentId), 'url'),
                                        '<span class="bi bi-trash" aria-hidden="true"></span>',
                                        ['class' => 'btn btn-icon btn-sm btn-outline-danger', 'title' => lang('Common.delete'), 'aria-label' => lang('Common.delete')],
                                    ) ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>

            <?= form_open("{$controller_name}/cancel", ['id' => 'buttons_form', 'class' => 'pos-actions']) ?>
                <?php if ($canFinishSale): ?>
                    <button type="button" class="btn btn-success btn-lg" id="finish_sale_button">
                        <span class="bi bi-check-lg" aria-hidden="true"></span>
                        <?= lang("{$sectionName}.complete_sale") ?>
                        <span class="kbd kbd-inverse"><?= esc($shortcutLabel('complete')) ?></span>
                    </button>
                <?php endif; ?>

                <?php if (! $pos_mode && isset($customer)): ?>
                    <button type="button" class="btn btn-success btn-lg" id="finish_invoice_quote_button">
                        <span class="bi bi-check-lg" aria-hidden="true"></span>
                        <?= esc($mode_label) ?>
                        <span class="kbd kbd-inverse"><?= esc($shortcutLabel('finish')) ?></span>
                    </button>
                <?php endif; ?>

                <div class="pos-actions-row">
                    <button type="button" class="btn btn-outline-secondary" id="suspend_sale_button" title="<?= esc($shortcutLabel('suspend')) ?>">
                        <span class="bi bi-pause-circle" aria-hidden="true"></span>
                        <?= lang("{$sectionName}.suspend_sale") ?>
                    </button>
                    <button type="button" class="btn btn-outline-danger" id="cancel_sale_button" title="<?= esc($shortcutLabel('cancel')) ?>">
                        <span class="bi bi-x-lg" aria-hidden="true"></span>
                        <?= lang("{$sectionName}.cancel_sale") ?>
                    </button>
                </div>
            <?= form_close() ?>

            <?php if ($payments_cover_total || ! $pos_mode): ?>
                <div class="card">
                    <div class="card-body pos-options">
                        <div>
                            <label for="comment" class="pos-label" id="comment_label"><?= lang('Common.comments') ?></label>
                            <?= form_textarea(['name' => 'comment', 'id' => 'comment', 'class' => 'form-control', 'value' => $comment, 'rows' => '2']) ?>
                        </div>

                        <label for="sales_print_after_sale" class="choice">
                            <?= form_checkbox(['name' => 'sales_print_after_sale', 'id' => 'sales_print_after_sale', 'value' => 1, 'checked' => $print_after_sale]) ?>
                            <?= lang("{$sectionName}.print_after_sale") ?>
                        </label>

                        <?php if (! empty($customer_email)): ?>
                            <label for="email_receipt" class="choice">
                                <?= form_checkbox(['name' => 'email_receipt', 'id' => 'email_receipt', 'value' => 1, 'checked' => $email_receipt]) ?>
                                <?= lang("{$sectionName}.email_receipt") ?>
                            </label>
                        <?php endif; ?>

                        <?php if ($mode == 'sale_work_order'): ?>
                            <label for="price_work_orders" class="choice">
                                <?= form_checkbox(['name' => 'price_work_orders', 'id' => 'price_work_orders', 'value' => 1, 'checked' => $price_work_orders]) ?>
                                <?= lang("{$sectionName}.include_prices") ?>
                            </label>
                        <?php endif; ?>

                        <?php if (($mode == 'sale_invoice') && $config['invoice_enable']): ?>
                            <div>
                                <label for="sales_invoice_number" class="pos-label"><?= lang("{$sectionName}.invoice_enable") ?></label>
                                <div class="input-group">
                                    <span class="input-group-text">#</span>
                                    <?= form_input(['name' => 'sales_invoice_number', 'id' => 'sales_invoice_number', 'class' => 'form-control', 'value' => $invoice_number]) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </aside>

    <?php if ($hasCart): ?>
        <div class="pos-total-bar" id="pos_total_bar">
            <div class="pos-total-text">
                <span><?= lang("{$sectionName}.total") ?></span>
                <strong id="pos_total_value"><?= to_currency($total) ?></strong>
            </div>
            <button type="button" class="btn btn-primary btn-lg" id="pos_total_action">
                <?= ($canFinishSale || (! $pos_mode && isset($customer))) ? lang("{$sectionName}.complete_sale") : lang('Sales.go_to_payment') ?>
            </button>
        </div>
    <?php endif; ?>
</div>

<script type="text/javascript">
    const keyboardShortcuts = <?= json_encode($keyboardShortcuts) ?>;
    const paymentsCoverTotal = <?= json_encode((bool) $payments_cover_total) ?>;
    const referenceCodePaymentTypes = <?= json_encode($reference_code_payment_types) ?>;
    const shortcutCodes = {
        items: keyboardShortcuts?.items?.code ?? null,
        customers: keyboardShortcuts?.customers?.code ?? null,
        suspend: keyboardShortcuts?.suspend?.code ?? null,
        suspended: keyboardShortcuts?.suspended?.code ?? null,
        amount: keyboardShortcuts?.amount?.code ?? null,
        payment: keyboardShortcuts?.payment?.code ?? null,
        complete: keyboardShortcuts?.complete?.code ?? null,
        finish: keyboardShortcuts?.finish?.code ?? null,
        help: keyboardShortcuts?.help?.code ?? null,
        cancel: keyboardShortcuts?.cancel?.code ?? null
    };

    window.posConfig = {
        decimals: <?= (int) ($config['currency_decimals'] ?? 2) ?>,
        decimalSeparator: <?= json_encode($numberFormatter->getSymbol(NumberFormatter::DECIMAL_SEPARATOR_SYMBOL)) ?>,
        groupSeparator: <?= json_encode(! empty($config['thousands_separator']) ? $numberFormatter->getSymbol(NumberFormatter::GROUPING_SEPARATOR_SYMBOL) : '') ?>
    };

    $(document).ready(function() {
        const redirect = function() {
            window.location.href = "<?= site_url('sales'); ?>";
        };

        const postLineChange = function(field, url) {
            return function() {
                const data = {item_id: $(this).closest('form').find("input[name='item_id']").val()};
                data[field] = $(this).val();
                $.ajax({url: url, method: 'post', data: data, dataType: 'json'});
            };
        };

        $("#remove_customer_button").click(function() {
            $.post("<?= site_url('sales/removeCustomer'); ?>", redirect);
        });

        $("input[name='item_number']").change(postLineChange('item_number', "<?= site_url('sales/changeItemNumber') ?>"));
        $("input[name='name']").change(postLineChange('item_name', "<?= site_url('sales/changeItemName') ?>"));
        $("input[name='item_description']").change(postLineChange('item_description', "<?= site_url('sales/changeItemDescription') ?>"));

        $('#item').focus();

        $('#item').autocomplete({
            source: "<?= esc("{$controller_name}/itemSearch") ?>",
            minChars: 0,
            autoFocus: false,
            delay: 500,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#add_item_form').submit();
                return false;
            }
        });

        $('#item').keypress(function(e) {
            if (e.which == 13) {
                $('#add_item_form').submit();
                return false;
            }
        });

        $('#item, #customer').dblclick(function() {
            $(this).autocomplete('search');
        });

        $('#customer').autocomplete({
            source: "<?= site_url('customers/suggest') ?>",
            minChars: 0,
            delay: 10,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#select_customer_form').submit();
                return false;
            }
        });

        $('#customer').keypress(function(e) {
            if (e.which == 13) {
                $('#select_customer_form').submit();
                return false;
            }
        });

        $('.giftcard-input').autocomplete({
            source: "<?= site_url('giftcards/suggest') ?>",
            minChars: 0,
            delay: 10,
            select: function(a, ui) {
                $(this).val(ui.item.value);
                $('#add_payment_form').submit();
                return false;
            }
        });

        $('#comment').keyup(function() {
            $.post("<?= esc(site_url("{$controller_name}/setComment")) ?>", {
                comment: $('#comment').val()
            });
        });

        <?php if ($config['invoice_enable']) { ?>
            $('#sales_invoice_number').keyup(function() {
                $.post("<?= esc(site_url("{$controller_name}/setInvoiceNumber")) ?>", {
                    sales_invoice_number: $('#sales_invoice_number').val()
                });
            });

        <?php } ?>

        $('#sales_print_after_sale').change(function() {
            $.post("<?= esc(site_url("{$controller_name}/setPrintAfterSale")) ?>", {
                sales_print_after_sale: $(this).is(':checked')
            });
        });

        $('#price_work_orders').change(function() {
            $.post("<?= esc(site_url("{$controller_name}/setPriceWorkOrders")) ?>", {
                price_work_orders: $(this).is(':checked')
            });
        });

        $('#email_receipt').change(function() {
            $.post("<?= esc(site_url("{$controller_name}/setEmailReceipt")) ?>", {
                email_receipt: $(this).is(':checked')
            });
        });

        $('#finish_sale_button, #finish_invoice_quote_button').click(function() {
            $('#buttons_form').attr('action', "<?= "{$controller_name}/complete" ?>");
            $('#buttons_form').submit();
        });

        $('#suspend_sale_button').click(function() {
            $('#buttons_form').attr('action', "<?= site_url("{$controller_name}/suspend") ?>");
            $('#buttons_form').submit();
        });

        $('#cancel_sale_button').click(function() {
            if (confirm("<?= lang(ucfirst($controller_name) . '.confirm_cancel_sale') ?>")) {
                $('#buttons_form').attr('action', "<?= site_url("{$controller_name}/cancel") ?>");
                $('#buttons_form').submit();
            }
        });

        $('#add_payment_button').click(function() {
            $('#add_payment_form').find('input[name="complete_after_payment"]').val('0');
            $('#add_payment_form').submit();
        });

        $('#payment_types').change(check_payment_type);

        $('#amount_tendered, #amount_tendered_giftcard').keypress(function(event) {
            if (event.which == 13) {
                $('#add_payment_form').submit();
                return false;
            }
        });

        table_support.handle_submit = function(resource, response, stay_open) {
            $.notify({
                message: response.message
            }, {
                type: response.success ? 'success' : 'danger'
            })

            if (response.success) {
                if (resource.match(/customers$/)) {
                    $('#customer').val(response.id);
                    $('#select_customer_form').submit();
                } else {
                    const $stock_location = $("select[name='stock_location']").val();
                    $('#item_location').val($stock_location);
                    $('#item').val(response.id);
                    if (stay_open) {
                        $('#add_item_form').ajaxSubmit();
                    } else {
                        $('#add_item_form').submit();
                    }
                }
            }
        }

        $('[name="price"],[name="quantity"],[name="discount"],[name="description"],[name="serialnumber"],[name="discounted_total"]').change(function() {
            $(this).closest('form').submit();
        });

        $('[name="discount_toggle"]').change(function() {
            const input = $('<input>').attr('type', 'hidden').attr('name', 'discount_type').val(($(this).prop('checked')) ? 1 : 0);
            $('#cart_' + $(this).attr('data-line')).append($(input));
            $('#cart_' + $(this).attr('data-line')).submit();
        });

        check_payment_type();
    });

    function check_payment_type() {
        const cash_mode = <?= json_encode($cash_mode) ?>;
        const paymentType = $("#payment_types").val();
        const isGiftCard = paymentType == "<?= lang(ucfirst($controller_name) . '.giftcard') ?>";
        const isCash = paymentType == "<?= lang(ucfirst($controller_name) . '.cash') ?>";
        const needsReferenceCode = referenceCodePaymentTypes.indexOf(paymentType) !== -1;

        if (isGiftCard) {
            $("#sale_total").html("<?= to_currency($total) ?>");
            $("#sale_amount_due").html("<?= to_currency($amount_due) ?>");
            $("#amount_tendered_label").html("<?= lang(ucfirst($controller_name) . '.giftcard_number') ?>");
            $("#amount_tendered:enabled").val('').focus();
            $(".giftcard-input").attr('disabled', false);
            $(".non-giftcard-input").attr('disabled', true);
            $(".giftcard-input:enabled").val('').focus();
            $(".reference-code-input").hide();
        } else if (isCash && cash_mode == '1') {
            $("#sale_total").html("<?= to_currency($non_cash_total) ?>");
            $("#sale_amount_due").html("<?= to_currency($cash_amount_due) ?>");
            $("#amount_tendered_label").html("<?= lang(ucfirst($controller_name) . '.amount_tendered') ?>");
            $("#amount_tendered:enabled").val("<?= to_currency_no_money($cash_amount_due) ?>");
            $(".giftcard-input").attr('disabled', true);
            $(".non-giftcard-input").attr('disabled', false);
            $(".reference-code-input").hide();
        } else {
            $("#sale_total").html("<?= to_currency($non_cash_total) ?>");
            $("#sale_amount_due").html("<?= to_currency($amount_due) ?>");
            $("#amount_tendered_label").html("<?= lang(ucfirst($controller_name) . '.amount_tendered') ?>");
            $("#amount_tendered:enabled").val("<?= to_currency_no_money($amount_due) ?>");
            $(".giftcard-input").attr('disabled', true);
            $(".non-giftcard-input").attr('disabled', false);
            if (needsReferenceCode) {
                $("#reference_code_label").html("<?= lang(ucfirst($controller_name) . '.reference_code') ?>");
                $(".reference-code-input").show();
                $(".reference-code-input input").attr('disabled', false);
            } else {
                $(".reference-code-input").hide();
                $(".reference-code-input input").attr('disabled', true);
            }
        }

        $(document).trigger('pos:payment-changed');
    }

    // Add Keyboard Shortcuts/Hotkeys to Sale Register
    document.body.onkeyup = function(event) {
        if ($(event.target).closest('.modal').length || $('.modal.show').length) {
            return;
        }
        if (event.altKey) {
            switch (event.keyCode) {
                case shortcutCodes.items:
                    $("#item").focus();
                    $("#item").select();
                    break;
                case shortcutCodes.customers:
                    $("#customer").focus();
                    $("#customer").select();
                    break;
                case shortcutCodes.suspend:
                    $("#suspend_sale_button").click();
                    break;
                case shortcutCodes.suspended:
                    $("#show_suspended_sales_button").click();
                    break;
                case shortcutCodes.amount:
                    $("#amount_tendered").focus();
                    $("#amount_tendered").select();
                    break;
                case shortcutCodes.payment:
                    $("#add_payment_button").click();
                    break;
                case shortcutCodes.complete:
                    if (paymentsCoverTotal && $("#finish_sale_button").length) {
                        $("#finish_sale_button").click();
                    } else {
                        $("#add_payment_button").click();
                    }
                    break;
                case shortcutCodes.finish:
                    $("#finish_invoice_quote_button").click();
                    break;
                case shortcutCodes.help:
                    $("#show_keyboard_help").click();
                    break;
            }
        }

        switch (event.keyCode) {
            case shortcutCodes.cancel:
                $("#cancel_sale_button").click();
                break;
        }
    }
</script>

<?= view('partial/footer') ?>
