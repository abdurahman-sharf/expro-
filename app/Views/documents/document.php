<?php
/**
 * @var string $type
 * @var string $context
 * @var array  $sale
 */
$config              = $sale['config'] ?? documentConfig();
$profile             = documentProfile($type, $sale);
$rtl                 = isRtl();
$alignEnd            = $rtl ? 'al-l' : 'al-r';
$alignStart          = $rtl ? 'al-r' : 'al-l';
$logo                = documentLogoSource($config, $context);
$barcode             = documentBarcodeSource((string) ($sale['barcode'] ?? ''));
$cart                = array_filter($sale['cart'] ?? [], static fn (array $item): bool => $item['print_option'] === PRINT_YES);
$payments            = $profile['showPayments'] ? ($sale['payments'] ?? []) : [];
$onlyCheck           = false;
$showGiftcardBalance = false;
$absolute            = $profile['absolute'];
$figure              = static fn (mixed $amount): string => documentFigure($absolute, $amount);
$qr                  = $profile['showQr'] ? documentQr($type, $sale, $config) : null;
$terms               = documentTerms($type, $config);
$extraFields         = $sale['printed_fields'] ?? [];
$itemExtraFields     = $sale['item_printed_fields'] ?? [];
$secondLanguage      = documentTranslations('customer', $sale['customer_id'] ?? null, array_map(static fn (array $item): int => (int) ($item['item_id'] ?? 0), $cart));

foreach ($payments as $payment) {
    $onlyCheck           = $onlyCheck           || $payment['payment_type'] === lang('Sales.check');
    $showGiftcardBalance = $showGiftcardBalance || explode(':', $payment['payment_type'])[0] === lang('Sales.giftcard');
}

$notes = array_filter([
    (string) ($config['payment_message'] ?? ''),
    empty($sale['comments']) ? (string) ($config['invoice_default_comments'] ?? '') : lang('Sales.comments') . ': ' . $sale['comments'],
    (string) ($config['return_policy'] ?? ''),
], static fn (string $note): bool => $note !== '');
?>
<?php if ($context === 'screen'): ?>
    <link rel="stylesheet" href="<?= assetUrl('css/document.css') ?>">
<?php endif; ?>
<div class="doc" id="document" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
    <table class="doc-head">
        <tr>
            <td class="doc-company <?= $alignStart ?>">
                <?php if ($logo !== ''): ?>
                    <img class="doc-logo" src="<?= esc($logo) ?>" alt="">
                <?php endif; ?>
                <?php if (! empty($config['receipt_show_company_name'])): ?>
                    <div class="doc-company-name"><?= esc($config['company']) ?></div>
                <?php endif; ?>
                <div class="doc-company-info"><?= nl2br(esc($sale['company_info'] ?? '')) ?></div>
            </td>
            <td class="doc-titlebox <?= $alignEnd ?>">
                <div class="doc-title"><?= esc($profile['title']) ?></div>
                <table class="doc-meta">
                    <?php foreach ($profile['meta'] as [$label, $value]): ?>
                        <tr>
                            <td class="doc-meta-label <?= $alignStart ?>"><?= esc($label) ?></td>
                            <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($value) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <?php if ($extraFields !== []): ?>
                    <table class="doc-meta doc-extra-fields">
                        <?php foreach ($extraFields as [$label, $value]): ?>
                            <tr>
                                <td class="doc-meta-label <?= $alignStart ?>"><?= esc($label) ?></td>
                                <td class="doc-meta-value <?= $alignEnd ?>"><?= nl2br(esc($value)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php if (! empty($sale['customer_info'])): ?>
        <div class="doc-party">
            <div class="doc-party-label"><?= esc($profile['partyLabel']) ?></div>
            <div class="doc-party-body"><?= nl2br(esc($sale['customer_info'])) ?></div>
            <?php if ($secondLanguage['party'] !== null): ?>
                <div class="doc-party-body doc-second-language" dir="auto"><?= esc($secondLanguage['party']['name']) ?><?= $secondLanguage['party']['note'] !== '' ? '<br>' . esc($secondLanguage['party']['note']) : '' ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (! empty($sale['ship_to'])): ?>
        <div class="doc-party doc-ship-to">
            <div class="doc-party-label"><?= lang('Sales.ship_to') ?></div>
            <div class="doc-party-body"><?= nl2br(esc($sale['ship_to'])) ?></div>
        </div>
    <?php endif; ?>

    <table class="doc-items">
        <thead>
            <tr>
                <th class="doc-col-no">#</th>
                <?php if ($profile['showHsn']): ?><th><?= lang('Sales.hsn') ?></th><?php endif; ?>
                <th class="<?= $alignStart ?>"><?= lang('Sales.item_name') ?></th>
                <th class="doc-col-num"><?= lang('Sales.quantity') ?></th>
                <?php if ($profile['showPrices']): ?>
                    <th class="doc-col-amount <?= $alignEnd ?>"><?= lang('Sales.price') ?></th>
                    <th class="doc-col-num"><?= lang('Sales.discount') ?></th>
                    <?php if ($profile['showUnitDiscount']): ?><th class="doc-col-amount <?= $alignEnd ?>"><?= lang('Sales.customer_discount') ?></th><?php endif; ?>
                    <th class="doc-col-amount <?= $alignEnd ?>"><?= lang('Sales.total') ?></th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php $lineNumber = 0; ?>
            <?php foreach ($cart as $item): ?>
                <?php $lineNumber++; ?>
                <tr class="<?= $lineNumber % 2 === 0 ? 'alt' : '' ?>">
                    <td class="doc-col-no"><?= $lineNumber ?></td>
                    <?php if ($profile['showHsn']): ?><td class="doc-col-num"><?= esc($item['hsn_code'] ?? '') ?></td><?php endif; ?>
                    <td class="<?= $alignStart ?>">
                        <div class="doc-item-name"><?= esc(trim($item['name'] . ' ' . ($item['attribute_values'] ?? ''))) ?></div>
                        <?php if (isset($secondLanguage['items'][(int) ($item['item_id'] ?? 0)])): ?>
                            <div class="doc-item-name doc-second-language" dir="auto"><?= esc($secondLanguage['items'][(int) $item['item_id']]) ?></div>
                        <?php endif; ?>
                        <?php if (($item['is_serialized'] || $item['allow_alt_description']) && ! empty($item['description'])): ?>
                            <div class="doc-item-note"><?= esc($item['description']) ?></div>
                        <?php endif; ?>
                        <?php if ($item['is_serialized'] && ! empty($item['serialnumber'])): ?>
                            <div class="doc-item-note"><?= lang('Sales.serial') ?>: <?= documentIsolate($item['serialnumber']) ?></div>
                        <?php endif; ?>
                        <?php foreach ($itemExtraFields[(int) ($item['item_id'] ?? 0)] ?? [] as [$label, $value]): ?>
                            <div class="doc-item-note doc-item-extra"><?= esc($label) ?>: <?= esc($value) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="doc-col-num"><?= documentIsolate(to_quantity_decimals($figure($item['quantity']))) ?></td>
                    <?php if ($profile['showPrices']): ?>
                        <td class="doc-col-amount <?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($item['price']))) ?></td>
                        <td class="doc-col-num"><?= documentIsolate($item['discount_type'] === FIXED ? to_currency($figure($item['discount'])) : to_decimals($item['discount']) . '%') ?></td>
                        <?php if ($profile['showUnitDiscount']): ?><td class="doc-col-amount <?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($item['discounted_total'] / $item['quantity']))) ?></td><?php endif; ?>
                        <td class="doc-col-amount doc-strong <?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($item['discounted_total']))) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="doc-lower">
        <tr>
            <td class="doc-notes <?= $alignStart ?>">
                <?php foreach ($notes as $note): ?>
                    <p><?= nl2br(esc($note)) ?></p>
                <?php endforeach; ?>
            </td>
            <td class="doc-gap"></td>
            <td class="doc-totals-cell">
                <?php if ($profile['showPrices']): ?>
                    <table class="doc-totals">
                        <?php $invoiceDiscount = (string) ($sale['invoice_discount'] ?? '0'); ?>
                        <?php if (bccomp($invoiceDiscount, '0') !== 0): ?>
                            <tr>
                                <td class="<?= $alignStart ?>"><?= lang('Sales.sub_total') ?></td>
                                <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($figure(bcadd((string) $sale['subtotal'], $invoiceDiscount)))) ?></td>
                            </tr>
                            <tr class="doc-invoice-discount">
                                <td class="<?= $alignStart ?>"><?= esc(invoiceDiscountLabel($sale['invoice_discount_entry'] ?? null)) ?></td>
                                <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($absolute ? $figure($invoiceDiscount) : $invoiceDiscount * -1)) ?></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td class="<?= $alignStart ?>"><?= lang('Sales.sub_total') ?></td>
                                <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($sale['subtotal']))) ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($sale['taxes'] ?? [] as $tax): ?>
                            <tr>
                                <td class="<?= $alignStart ?>"><?= documentIsolate((float) $tax['tax_rate'] . '%') ?> <?= esc($tax['tax_group']) ?></td>
                                <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency_tax($figure($tax['sale_tax_amount']), $tax['tax_type'] ?? null)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach (headerChargeRows($sale['header_charges'] ?? []) as [$chargeLabel, $chargeAmount]): ?>
                            <tr class="doc-header-charge">
                                <td class="<?= $alignStart ?>"><?= esc($chargeLabel) ?></td>
                                <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($chargeAmount))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="doc-grand">
                            <td class="<?= $alignStart ?>"><?= lang('Sales.total') ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($sale['total']))) ?></td>
                        </tr>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td class="<?= $alignStart ?>"><?= esc(explode(':', $payment['payment_type'])[0]) ?></td>
                                <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency(-$payment['payment_amount'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (isset($sale['cur_giftcard_value']) && $showGiftcardBalance): ?>
                            <tr>
                                <td class="<?= $alignStart ?>"><?= lang('Sales.giftcard_balance') ?></td>
                                <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($sale['cur_giftcard_value'])) ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (! empty($payments)): ?>
                            <tr class="doc-balance">
                                <td class="<?= $alignStart ?>"><?= lang($sale['amount_change'] >= 0 ? ($onlyCheck ? 'Sales.check_balance' : 'Sales.change_due') : 'Sales.amount_due') ?></td>
                                <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($sale['amount_change'])) ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <?php if ($qr !== null && $qr->payload !== null): ?>
        <div class="doc-qr">
            <div class="doc-qr-label"><?= lang('Documents.qr_label') ?></div>
            <div class="doc-qr-text" dir="ltr"><?= esc($qr->payload) ?></div>
        </div>
    <?php elseif ($qr !== null && $qr->refusal !== null && $context === 'screen'): ?>
        <div class="alert alert-warning print_hide doc-qr-notice" role="alert"><?= esc(lang('Documents.error_' . $qr->refusal->value)) ?></div>
    <?php endif; ?>

    <?php if ($terms !== ''): ?>
        <div class="doc-terms">
            <div class="doc-terms-label"><?= lang('Documents.terms') ?></div>
            <div class="doc-terms-body"><?= nl2br(esc($terms)) ?></div>
        </div>
    <?php endif; ?>

    <?php if ($profile['showSignature']): ?>
        <table class="doc-sign">
            <tr>
                <td class="doc-sign-box"><?= lang('Sales.authorized_signature') ?></td>
                <td class="doc-sign-gap"></td>
                <td class="doc-sign-box"><?= lang('Sales.customer') ?></td>
            </tr>
        </table>
    <?php endif; ?>

    <?php if ($barcode !== ''): ?>
        <div class="doc-barcode">
            <img src="<?= esc($barcode) ?>" alt="<?= esc($sale['sale_id'] ?? '') ?>"><br>
            <?= documentIsolate((string) ($sale['sale_id'] ?? '')) ?>
        </div>
    <?php endif; ?>
</div>
