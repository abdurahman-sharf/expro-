<?php
/**
 * @var int    $paper
 * @var string $transaction_time
 * @var int    $sale_id
 * @var string $employee
 * @var float  $discount
 * @var array  $cart
 * @var float  $subtotal
 * @var array  $taxes
 * @var float  $total
 * @var array  $payments
 * @var float  $amount_change
 * @var string $barcode
 * @var array  $config
 */
$paper ??= 80;
$logo                = documentLogoSource($config, 'screen');
$showDiscountTotals  = $config['receipt_show_total_discount'] && $discount > 0;
$onlyCheck           = false;
$showGiftcardBalance = false;

foreach ($payments as $payment) {
    $onlyCheck           = $onlyCheck           || $payment['payment_type'] === lang('Sales.check');
    $showGiftcardBalance = $showGiftcardBalance || explode(':', $payment['payment_type'])[0] === lang('Sales.giftcard');
}
?>
<div id="receipt_wrapper" class="receipt-thermal receipt-<?= (int) $paper ?>" dir="<?= textDirection() ?>" style="font-size: <?= esc($config['receipt_font_size']) ?>px;">
    <div class="rt-head">
        <?php if ($logo !== ''): ?>
            <img class="rt-logo" src="<?= esc($logo) ?>" alt="">
        <?php endif; ?>
        <?php if ($config['receipt_show_company_name']): ?>
            <div class="rt-company"><?= esc($config['company']) ?></div>
        <?php endif; ?>
        <div><?= nl2br(esc($config['address'])) ?></div>
        <div><?= documentIsolate((string) $config['phone']) ?></div>
        <div class="rt-title"><?= lang('Sales.receipt') ?></div>
    </div>

    <dl class="rt-info">
        <div><dt><?= lang('Common.date') ?></dt><dd><?= documentIsolate($transaction_time) ?></dd></div>
        <div><dt><?= lang('Sales.id') ?></dt><dd><?= documentIsolate((string) $sale_id) ?></dd></div>
        <?php if (! empty($creditNoteNumber)): ?>
            <div><dt><?= lang('Numbering.type_credit_note') ?></dt><dd><?= documentIsolate((string) $creditNoteNumber) ?></dd></div>
        <?php endif; ?>
        <?php if (! empty($invoice_number)): ?>
            <div><dt><?= lang('Sales.invoice_number') ?></dt><dd><?= documentIsolate($invoice_number) ?></dd></div>
        <?php endif; ?>
        <?php if (isset($customer)): ?>
            <div><dt><?= lang('Customers.customer') ?></dt><dd><?= esc($customer) ?></dd></div>
        <?php endif; ?>
        <div><dt><?= lang('Employees.employee') ?></dt><dd><?= esc($employee) ?></dd></div>
    </dl>

    <div class="rt-items">
        <?php foreach ($cart as $item): ?>
            <div class="rt-item">
                <div class="rt-name"><?= esc(ucfirst(trim($item['name'] . ' ' . ($item['attribute_values'] ?? '')))) ?></div>
                <div class="rt-calc">
                    <span><?= documentIsolate(to_quantity_decimals($item['quantity']) . ' × ' . to_currency($item['price'])) ?></span>
                    <strong><?= documentIsolate(to_currency($item[$config['receipt_show_total_discount'] ? 'total' : 'discounted_total'])) ?></strong>
                </div>
                <?php if ($config['receipt_show_description'] && ! empty($item['description'])): ?>
                    <div class="rt-note"><?= esc($item['description']) ?></div>
                <?php endif; ?>
                <?php if ($config['receipt_show_serialnumber'] && ! empty($item['serialnumber'])): ?>
                    <div class="rt-note"><?= lang('Sales.serial') ?>: <?= documentIsolate($item['serialnumber']) ?></div>
                <?php endif; ?>
                <?php if ($item['discount'] > 0): ?>
                    <div class="rt-calc rt-discount">
                        <span><?= lang('Sales.discount') ?> <?= documentIsolate($item['discount_type'] == FIXED ? to_currency($item['discount']) : to_decimals($item['discount']) . '%') ?></span>
                        <span><?= documentIsolate(to_currency($item['discounted_total'])) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <dl class="rt-totals">
        <?php if ($showDiscountTotals || $config['receipt_show_taxes']): ?>
            <div><dt><?= lang('Sales.sub_total') ?></dt><dd><?= documentIsolate(to_currency($subtotal)) ?></dd></div>
        <?php endif; ?>
        <?php if ($showDiscountTotals): ?>
            <div><dt><?= lang('Sales.discount') ?></dt><dd><?= documentIsolate(to_currency($discount * -1)) ?></dd></div>
        <?php endif; ?>
        <?php if (bccomp((string) ($invoice_discount ?? '0'), '0') !== 0): ?>
            <div><dt><?= esc(invoiceDiscountLabel($invoice_discount_entry ?? null)) ?></dt><dd><?= documentIsolate(to_currency($invoice_discount * -1)) ?></dd></div>
        <?php endif; ?>
        <?php if ($config['receipt_show_taxes']): ?>
            <?php foreach ($taxes as $tax): ?>
                <div><dt><?= documentIsolate((float) $tax['tax_rate'] . '%') ?> <?= esc($tax['tax_group']) ?></dt><dd><?= documentIsolate(to_currency_tax($tax['sale_tax_amount'])) ?></dd></div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="rt-grand"><dt><?= lang('Sales.total') ?></dt><dd><?= documentIsolate(to_currency($total)) ?></dd></div>
        <?php foreach ($payments as $payment): ?>
            <div><dt><?= esc(explode(':', $payment['payment_type'])[0]) ?></dt><dd><?= documentIsolate(to_currency($payment['payment_amount'] * -1)) ?></dd></div>
        <?php endforeach; ?>
        <?php if (isset($cur_giftcard_value) && $showGiftcardBalance): ?>
            <div><dt><?= lang('Sales.giftcard_balance') ?></dt><dd><?= documentIsolate(to_currency($cur_giftcard_value)) ?></dd></div>
        <?php endif; ?>
        <div class="rt-balance"><dt><?= lang($amount_change >= 0 ? ($onlyCheck ? 'Sales.check_balance' : 'Sales.change_due') : 'Sales.amount_due') ?></dt><dd><?= documentIsolate(to_currency($amount_change)) ?></dd></div>
    </dl>

    <?php if (! empty($config['return_policy'])): ?>
        <div class="rt-policy"><?= nl2br(esc($config['return_policy'])) ?></div>
    <?php endif; ?>

    <div class="rt-barcode">
        <?= $barcode ?>
        <div><?= documentIsolate((string) $sale_id) ?></div>
    </div>
</div>
