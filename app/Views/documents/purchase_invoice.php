<?php
/**
 * @var array<string, mixed> $receiving
 * @var string               $context
 */
$config          = documentConfig();
$profile         = purchaseDocumentProfile($receiving);
$rtl             = isRtl();
$alignEnd        = $rtl ? 'al-l' : 'al-r';
$alignStart      = $rtl ? 'al-r' : 'al-l';
$logo            = documentLogoSource($config, $context);
$absolute        = $profile['absolute'];
$figure          = static fn (mixed $amount): string => documentFigure($absolute, $amount);
$terms           = documentTerms('purchase_invoice', $config);
$itemExtraFields = $receiving['item_printed_fields'] ?? [];
$discount        = (string) ($receiving['invoiceDiscountAmount'] ?? '0');
$tax             = (string) ($receiving['tax_total'] ?? '0');
$grand           = (string) ($receiving['total_with_tax'] ?? $receiving['total'] ?? '0');
$paid            = (string) ($receiving['paid'] ?? '0');
$due             = bcsub(documentFigure(false, $grand), $paid, 4);
$hasDiscount     = bccomp($discount, '0', 4) !== 0;
$hasTax          = bccomp($tax, '0', 8) !== 0;
?>
<?php if ($context === 'screen'): ?>
    <link rel="stylesheet" href="<?= assetUrl('css/document.css') ?>">
<?php endif; ?>
<div class="doc doc-purchase" id="document" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
    <table class="doc-head">
        <tr>
            <td class="doc-company <?= $alignStart ?>">
                <?php if ($logo !== ''): ?>
                    <img class="doc-logo" src="<?= esc($logo) ?>" alt="">
                <?php endif; ?>
                <?php if (! empty($config['receipt_show_company_name'])): ?>
                    <div class="doc-company-name"><?= esc((string) ($config['company'] ?? '')) ?></div>
                <?php endif; ?>
                <div class="doc-company-info"><?= nl2br(esc(documentCompanyInfo($config))) ?></div>
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
            </td>
        </tr>
    </table>

    <?php if (isset($receiving['supplier'])): ?>
        <div class="doc-party">
            <div class="doc-party-label"><?= esc($profile['partyLabel']) ?></div>
            <div class="doc-party-body">
                <?= nl2br(esc((string) $receiving['supplier'])) ?>
                <?php if (! empty($receiving['supplier_tax_id'])): ?>
                    <br><?= lang('Receivings.supplier_tax_id') ?>: <?= documentIsolate((string) $receiving['supplier_tax_id']) ?>
                <?php endif; ?>
                <?php if (! empty($receiving['supplier_commercial_registry'])): ?>
                    <br><?= lang('Receivings.supplier_commercial_registry') ?>: <?= documentIsolate((string) $receiving['supplier_commercial_registry']) ?>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <table class="doc-items">
        <thead>
            <tr>
                <th class="doc-col-no">#</th>
                <th class="<?= $alignStart ?>"><?= lang('Items.item') ?></th>
                <th class="doc-col-num"><?= lang('Sales.quantity') ?></th>
                <th class="doc-col-amount <?= $alignEnd ?>"><?= lang('Common.price') ?></th>
                <th class="doc-col-amount <?= $alignEnd ?>"><?= lang('Sales.total') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php $lineNumber = 0; ?>
            <?php foreach (array_reverse($receiving['cart'], true) as $item): ?>
                <?php $lineNumber++; ?>
                <tr class="<?= $lineNumber % 2 === 0 ? 'alt' : '' ?>">
                    <td class="doc-col-no"><?= $lineNumber ?></td>
                    <td class="<?= $alignStart ?>">
                        <div class="doc-item-name"><?= esc(trim($item['name'] . ' ' . ($item['attribute_values'] ?? ''))) ?></div>
                        <?php if (! empty($item['serialnumber'])): ?>
                            <div class="doc-item-note"><?= lang('Sales.serial') ?>: <?= documentIsolate((string) $item['serialnumber']) ?></div>
                        <?php endif; ?>
                        <?php foreach ($itemExtraFields[(int) ($item['item_id'] ?? 0)] ?? [] as [$label, $value]): ?>
                            <div class="doc-item-note doc-item-extra"><?= esc($label) ?>: <?= esc($value) ?></div>
                        <?php endforeach; ?>
                    </td>
                    <td class="doc-col-num"><?= documentIsolate(to_quantity_decimals($figure($item['quantity']))) ?><?= ! empty($item['receiving_quantity']) && (float) $item['receiving_quantity'] !== 1.0 ? documentIsolate(' x ' . to_quantity_decimals((string) $item['receiving_quantity'])) : '' ?></td>
                    <td class="doc-col-amount <?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($item['price']))) ?></td>
                    <td class="doc-col-amount doc-strong <?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($item['total']))) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="doc-lower">
        <tr>
            <td class="doc-notes <?= $alignStart ?>">
                <?php if ((string) ($config['return_policy'] ?? '') !== ''): ?>
                    <p><?= nl2br(esc((string) $config['return_policy'])) ?></p>
                <?php endif; ?>
            </td>
            <td class="doc-gap"></td>
            <td class="doc-totals-cell">
                <table class="doc-totals">
                    <?php if ($hasDiscount || $hasTax): ?>
                        <tr>
                            <td class="<?= $alignStart ?>"><?= lang('Receivings.sub_total') ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($receiving['total']))) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($hasDiscount): ?>
                        <tr class="doc-invoice-discount">
                            <td class="<?= $alignStart ?>"><?= lang('Receivings.invoice_discount_amount') ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($discount))) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($hasTax): ?>
                        <tr>
                            <td class="<?= $alignStart ?>"><?= lang('Receivings.tax_total') ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($tax))) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="doc-grand">
                        <td class="<?= $alignStart ?>"><?= lang('Sales.total') ?></td>
                        <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($figure($grand))) ?></td>
                    </tr>
                    <?php if ($profile['showPayments']): ?>
                        <tr>
                            <td class="<?= $alignStart ?>"><?= lang('Documents.paid') ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($paid)) ?></td>
                        </tr>
                        <tr class="doc-balance">
                            <td class="<?= $alignStart ?>"><?= lang('Sales.amount_due') ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate(to_currency($due)) ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <?php if ($terms !== ''): ?>
        <div class="doc-terms">
            <div class="doc-terms-label"><?= lang('Documents.terms') ?></div>
            <div class="doc-terms-body"><?= nl2br(esc($terms)) ?></div>
        </div>
    <?php endif; ?>
</div>
