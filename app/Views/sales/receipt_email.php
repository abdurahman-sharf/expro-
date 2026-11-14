<?php
/**
 * @var string $transaction_time
 * @var int    $sale_id
 * @var string $employee
 * @var array  $cart
 * @var float  $discount
 * @var float  $subtotal
 * @var array  $taxes
 * @var float  $total
 * @var array  $payments
 * @var float  $amount_change
 * @var string $barcode
 * @var string $img_tag
 * @var bool   $pdf
 * @var array  $config
 */
$config ??= documentConfig();
$rtl           = isRtl();
$start         = $rtl ? 'right' : 'left';
$end           = $rtl ? 'left' : 'right';
$font          = "font-family: 'IBM Plex Sans Arabic', plexar, Tahoma, Arial, sans-serif;";
$cell          = "padding: 6px 8px; border-bottom: 1px solid #dce3ef; {$font} font-size: 13px;";
$barcodeSource = documentBarcodeSource((string) ($barcode ?? ''));
$logoMarkup    = '';

if ($pdf ?? false) {
    $logoPath   = documentLogoSource($config, 'pdf');
    $logoMarkup = $logoPath !== '' ? '<img src="' . esc($logoPath) . '" height="56">' : '';
} elseif (! empty($img_tag)) {
    $logoMarkup = str_replace('<img ', '<img style="max-height: 60px;" ', $img_tag);
}

$onlyCheck           = false;
$showGiftcardBalance = false;

foreach ($payments as $payment) {
    $onlyCheck           = $onlyCheck           || $payment['payment_type'] === lang('Sales.check');
    $showGiftcardBalance = $showGiftcardBalance || explode(':', $payment['payment_type'])[0] === lang('Sales.giftcard');
}

$summaryRow = static fn (string $label, string $value, string $style = ''): string => '<tr><td colspan="3" style="padding: 5px 8px; text-align: ' . $start . '; ' . $font . ' font-size: 13px; ' . $style . '">' . $label . '</td>'
        . '<td style="padding: 5px 8px; text-align: ' . $end . '; ' . $font . ' font-size: 13px; ' . $style . '">' . documentIsolate($value) . '</td></tr>';
?>
<div dir="<?= $rtl ? 'rtl' : 'ltr' ?>" style="max-width: 560px; margin: 0 auto; color: #0b1b3a; <?= $font ?>">
    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <td style="padding: 14px 8px; text-align: center; background-color: #042359; color: #ffffff; <?= $font ?>">
                <?php if ($logoMarkup !== ''): ?>
                    <div><?= $logoMarkup ?></div>
                <?php endif; ?>
                <?php if (! empty($config['receipt_show_company_name'])): ?>
                    <div style="font-size: 18px; font-weight: bold;"><?= esc($config['company']) ?></div>
                <?php endif; ?>
                <div style="font-size: 12px;"><?= nl2br(esc($config['address'])) ?></div>
                <div style="font-size: 12px;"><?= documentIsolate((string) $config['phone']) ?></div>
            </td>
        </tr>
        <tr>
            <td style="padding: 10px 8px; text-align: center; <?= $font ?>">
                <div style="font-size: 16px; font-weight: bold; color: #042359;"><?= lang('Sales.receipt') ?></div>
                <div style="font-size: 12px; color: #56657f;"><?= documentIsolate($transaction_time) ?></div>
            </td>
        </tr>
    </table>

    <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px;">
        <?php if (isset($customer)): ?>
            <tr><td style="padding: 2px 8px; text-align: <?= $start ?>; <?= $font ?> font-size: 13px;"><?= lang('Customers.customer') ?>: <?= esc($customer) ?></td></tr>
        <?php endif; ?>
        <tr><td style="padding: 2px 8px; text-align: <?= $start ?>; <?= $font ?> font-size: 13px;"><?= lang('Sales.id') ?>: <?= documentIsolate((string) $sale_id) ?></td></tr>
        <?php if (! empty($creditNoteNumber)): ?>
            <tr><td style="padding: 2px 8px; text-align: <?= $start ?>; <?= $font ?> font-size: 13px;"><?= lang('Numbering.type_credit_note') ?>: <?= documentIsolate((string) $creditNoteNumber) ?></td></tr>
        <?php endif; ?>
        <?php if (! empty($refundInvoiceNumber)): ?>
            <tr><td style="padding: 2px 8px; text-align: <?= $start ?>; <?= $font ?> font-size: 13px;"><?= lang('Numbering.type_refund_invoice') ?>: <?= documentIsolate((string) $refundInvoiceNumber) ?></td></tr>
        <?php endif; ?>
        <tr><td style="padding: 2px 8px; text-align: <?= $start ?>; <?= $font ?> font-size: 13px;"><?= lang('Employees.employee') ?>: <?= esc($employee) ?></td></tr>
    </table>

    <table style="width: 100%; border-collapse: collapse;">
        <tr>
            <th style="width: 40%; padding: 6px 8px; text-align: <?= $start ?>; background-color: #042359; color: #ffffff; <?= $font ?> font-size: 12px;"><?= lang('Sales.description_abbrv') ?></th>
            <th style="width: 20%; padding: 6px 8px; text-align: <?= $end ?>; background-color: #042359; color: #ffffff; <?= $font ?> font-size: 12px;"><?= lang('Sales.price') ?></th>
            <th style="width: 20%; padding: 6px 8px; text-align: center; background-color: #042359; color: #ffffff; <?= $font ?> font-size: 12px;"><?= lang('Sales.quantity') ?></th>
            <th style="width: 20%; padding: 6px 8px; text-align: <?= $end ?>; background-color: #042359; color: #ffffff; <?= $font ?> font-size: 12px;"><?= lang('Sales.total') ?></th>
        </tr>
        <?php foreach ($cart as $item): ?>
            <?php if ($item['print_option'] === PRINT_YES): ?>
                <tr>
                    <td style="<?= $cell ?> text-align: <?= $start ?>;">
                        <?= esc(ucfirst(trim($item['name'] . ' ' . ($item['attribute_values'] ?? '')))) ?>
                        <?php if ($config['receipt_show_description'] && ! empty($item['description'])): ?>
                            <div style="font-size: 11px; color: #56657f;"><?= esc($item['description']) ?></div>
                        <?php endif; ?>
                        <?php if ($config['receipt_show_serialnumber'] && ! empty($item['serialnumber'])): ?>
                            <div style="font-size: 11px; color: #56657f;"><?= lang('Sales.serial') ?>: <?= documentIsolate($item['serialnumber']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="<?= $cell ?> text-align: <?= $end ?>;"><?= documentIsolate(to_currency($item['price'])) ?></td>
                    <td style="<?= $cell ?> text-align: center;"><?= documentIsolate(to_quantity_decimals($item['quantity'])) ?></td>
                    <td style="<?= $cell ?> text-align: <?= $end ?>; font-weight: bold;"><?= documentIsolate(to_currency($item[$config['receipt_show_total_discount'] ? 'total' : 'discounted_total'])) ?></td>
                </tr>
                <?php if ($item['discount'] > 0): ?>
                    <tr>
                        <td colspan="3" style="<?= $cell ?> text-align: <?= $start ?>; font-size: 11px; color: #56657f;"><?= lang('Sales.discount') ?> <?= documentIsolate($item['discount_type'] === FIXED ? to_currency($item['discount']) : to_decimals($item['discount']) . '%') ?></td>
                        <td style="<?= $cell ?> text-align: <?= $end ?>; font-size: 11px;"><?= documentIsolate(to_currency($item['discounted_total'])) ?></td>
                    </tr>
                <?php endif; ?>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($config['receipt_show_total_discount'] && $discount > 0): ?>
            <?= $summaryRow(lang('Sales.sub_total'), to_currency($subtotal), 'border-top: 2px solid #042359;') ?>
            <?= $summaryRow(lang('Sales.discount'), to_currency($discount * -1)) ?>
        <?php endif; ?>

        <?php if (bccomp((string) ($invoice_discount ?? '0'), '0') !== 0): ?>
            <?= $summaryRow(esc(invoiceDiscountLabel($invoice_discount_entry ?? null)), to_currency($invoice_discount * -1)) ?>
        <?php endif; ?>

        <?php if ($config['receipt_show_taxes']): ?>
            <?= $summaryRow(lang('Sales.sub_total'), to_currency($subtotal), 'border-top: 2px solid #042359;') ?>
            <?php foreach ($taxes as $tax): ?>
                <?= $summaryRow((float) $tax['tax_rate'] . '% ' . esc($tax['tax_group']), to_currency_tax($tax['sale_tax_amount'], $tax['tax_type'] ?? null)) ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php foreach (headerChargeRows($header_charges ?? []) as [$chargeLabel, $chargeAmount]): ?>
            <?= $summaryRow(esc($chargeLabel), to_currency($chargeAmount)) ?>
        <?php endforeach; ?>

        <?= $summaryRow(lang('Sales.total'), to_currency($total), 'font-size: 15px; font-weight: bold; color: #042359; border-top: 2px solid #042359;') ?>

        <?php foreach ($payments as $payment): ?>
            <?= $summaryRow(esc(explode(':', $payment['payment_type'])[0]), to_currency($payment['payment_amount'] * -1)) ?>
        <?php endforeach; ?>

        <?php if (isset($cur_giftcard_value) && $showGiftcardBalance): ?>
            <?= $summaryRow(lang('Sales.giftcard_balance'), to_currency($cur_giftcard_value)) ?>
        <?php endif; ?>

        <?= $summaryRow(lang($amount_change >= 0 ? ($onlyCheck ? 'Sales.check_balance' : 'Sales.change_due') : 'Sales.amount_due'), to_currency($amount_change), 'font-weight: bold;') ?>
    </table>

    <?php if (! empty($config['return_policy'])): ?>
        <div style="margin-top: 14px; text-align: center; font-size: 12px; color: #56657f; <?= $font ?>"><?= nl2br(esc($config['return_policy'])) ?></div>
    <?php endif; ?>

    <?php if ($barcodeSource !== ''): ?>
        <div style="margin-top: 12px; text-align: center; <?= $font ?> font-size: 12px; color: #56657f;">
            <img alt="<?= esc((string) $sale_id) ?>" src="<?= esc($barcodeSource) ?>" height="36"><br>
            <?= documentIsolate((string) $sale_id) ?>
        </div>
    <?php endif; ?>
</div>
