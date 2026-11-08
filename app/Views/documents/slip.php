<?php
/**
 * @var string               $type
 * @var string               $context
 * @var array<string, mixed> $sale
 */
$config     = documentConfig();
$profile    = slipProfile($type, $sale, $config);
$rtl        = isRtl();
$alignEnd   = $rtl ? 'al-l' : 'al-r';
$alignStart = $rtl ? 'al-r' : 'al-l';
$logo       = documentLogoSource($config, $context);
$barcode    = documentBarcodeSource((string) ($sale['barcode'] ?? ''));
$cart       = array_filter($sale['cart'] ?? [], static fn (array $item): bool => (int) $item['print_option'] === PRINT_YES);
?>
<?php if ($context === 'screen'): ?>
    <link rel="stylesheet" href="<?= assetUrl('css/document.css') ?>">
<?php endif; ?>
<div class="doc doc-slip" id="document" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
    <table class="doc-head">
        <tr>
            <td class="doc-company <?= $alignStart ?>">
                <?php if ($logo !== ''): ?>
                    <img class="doc-logo" src="<?= esc($logo) ?>" alt="">
                <?php endif; ?>
                <?php if (! empty($config['receipt_show_company_name'])): ?>
                    <div class="doc-company-name"><?= esc((string) ($config['company'] ?? '')) ?></div>
                <?php endif; ?>
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

    <?php foreach ($profile['blocks'] as [$label, $text]): ?>
        <div class="doc-party doc-slip-block">
            <div class="doc-party-label"><?= esc($label) ?></div>
            <div class="doc-party-body"><?= nl2br(esc($text)) ?></div>
        </div>
    <?php endforeach; ?>

    <table class="doc-items">
        <thead>
            <tr>
                <th class="doc-col-no">#</th>
                <th class="<?= $alignStart ?>"><?= lang('Sales.item_name') ?></th>
                <th class="<?= $alignStart ?>"><?= lang('Documents.item_description') ?></th>
                <th class="doc-col-num"><?= lang('Sales.quantity') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php $lineNumber = 0; ?>
            <?php foreach ($cart as $item): ?>
                <?php $lineNumber++; ?>
                <tr class="<?= $lineNumber % 2 === 0 ? 'alt' : '' ?>">
                    <td class="doc-col-no"><?= $lineNumber ?></td>
                    <td class="<?= $alignStart ?>"><div class="doc-item-name"><?= esc(trim($item['name'] . ' ' . ($item['attribute_values'] ?? ''))) ?></div></td>
                    <td class="<?= $alignStart ?>"><?= esc(! empty($item['description']) && $item['description'] !== $item['name'] ? (string) $item['description'] : '') ?></td>
                    <td class="doc-col-num"><?= documentIsolate(to_quantity_decimals((string) $item['quantity'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($barcode !== ''): ?>
        <div class="doc-barcode">
            <img src="<?= esc($barcode) ?>" alt="<?= esc((string) ($sale['sale_id'] ?? '')) ?>"><br>
            <?= documentIsolate((string) ($sale['sale_id'] ?? '')) ?>
        </div>
    <?php endif; ?>
</div>
