<?php
/**
 * @var array<string, mixed> $einvoice What the issued document says (App\Libraries\Documents\EInvoicePrint).
 * @var string               $variant  'a4' or 'thermal'.
 * @var string               $alignStart
 * @var string               $alignEnd
 */
$totals   = $einvoice['totals'];
$currency = (string) $einvoice['currency'];
$amount   = static fn (string $value): string => documentIsolate($value === '' ? '0.00' : $value);
$shown    = static fn (string $value): bool => $value !== '' && bccomp($value, '0', 2) !== 0;
$thermal  = $variant === 'thermal';
?>
<div class="einvoice einvoice-<?= esc($variant, 'attr') ?>">
    <table class="einvoice-meta">
        <tr><td><?= esc(einvoiceWords('Documents.einvoice_number')) ?></td><td><?= documentIsolate((string) $einvoice['number']) ?></td></tr>
        <tr><td><?= esc(einvoiceWords('Documents.einvoice_issued')) ?></td><td><?= documentIsolate($einvoice['issue_date'] . ' ' . $einvoice['issue_time']) ?></td></tr>
        <?php if ($einvoice['supply_date'] !== '' && $einvoice['supply_date'] !== $einvoice['issue_date']): ?>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_supply_date')) ?></td><td><?= documentIsolate((string) $einvoice['supply_date']) ?></td></tr>
        <?php endif; ?>
        <tr><td><?= esc(einvoiceWords('Documents.einvoice_seller')) ?></td><td><?= esc($einvoice['seller']['name']) ?></td></tr>
        <tr><td><?= esc(einvoiceWords('Documents.einvoice_vat_number')) ?></td><td><?= documentIsolate($einvoice['seller']['vat']) ?></td></tr>
        <?php if ($einvoice['buyer']['vat'] !== '' || $einvoice['title'] === 'standard'): ?>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_buyer')) ?></td><td><?= esc($einvoice['buyer']['name']) ?></td></tr>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_buyer_vat_number')) ?></td><td><?= documentIsolate($einvoice['buyer']['vat']) ?></td></tr>
        <?php endif; ?>
        <?php if ($einvoice['original'] !== ''): ?>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_original')) ?></td><td><?= documentIsolate((string) $einvoice['original']) ?></td></tr>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_reason')) ?></td><td dir="auto"><?= esc($einvoice['reason']) ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if ($thermal): ?>
        <div class="rt-items">
            <?php foreach ($einvoice['lines'] as $line): ?>
                <div class="rt-item">
                    <?php if ($line['prepayment'] !== ''): ?>
                        <div class="rt-name"><?= esc(einvoiceWords('Documents.einvoice_prepayment_line', [$line['prepayment']])) ?></div>
                        <div class="rt-calc"><span><?= $amount((string) $line['prepaid']) ?> + <?= $amount((string) $line['prepaidVat']) ?></span></div>
                    <?php else: ?>
                        <div class="rt-name"><?= esc($line['name']) ?></div>
                        <div class="rt-calc"><span><?= documentIsolate($line['quantity'] . ' × ' . $line['price']) ?></span><strong><?= $amount((string) $line['net']) ?></strong></div>
                        <div class="rt-calc rt-note"><span><?= esc(einvoiceWords('Documents.einvoice_vat')) ?> <?= documentIsolate($line['percent'] . '%') ?>: <?= $amount((string) $line['vat']) ?></span><span><?= $amount((string) $line['gross']) ?></span></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <table class="doc-items einvoice-lines">
            <thead>
                <tr>
                    <th class="doc-col-no">#</th>
                    <th class="<?= $alignStart ?>"><?= esc(einvoiceWords('Documents.einvoice_item')) ?></th>
                    <th class="doc-col-num"><?= esc(einvoiceWords('Documents.einvoice_quantity')) ?></th>
                    <th class="doc-col-amount <?= $alignEnd ?>"><?= esc(einvoiceWords('Documents.einvoice_price')) ?></th>
                    <th class="doc-col-amount <?= $alignEnd ?>"><?= esc(einvoiceWords('Documents.einvoice_net')) ?></th>
                    <th class="doc-col-num"><?= esc(einvoiceWords('Documents.einvoice_rate')) ?></th>
                    <th class="doc-col-amount <?= $alignEnd ?>"><?= esc(einvoiceWords('Documents.einvoice_vat')) ?></th>
                    <th class="doc-col-amount <?= $alignEnd ?>"><?= esc(einvoiceWords('Documents.einvoice_gross')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($einvoice['lines'] as $line): ?>
                    <tr>
                        <td class="doc-col-no"><?= documentIsolate((string) $line['id']) ?></td>
                        <?php if ($line['prepayment'] !== ''): ?>
                            <td class="<?= $alignStart ?>" colspan="3"><?= esc(einvoiceWords('Documents.einvoice_prepayment_line', [$line['prepayment']])) ?></td>
                            <td class="doc-col-amount <?= $alignEnd ?>"><?= $amount((string) $line['prepaid']) ?></td>
                            <td class="doc-col-num"><?= documentIsolate($line['percent'] . '%') ?></td>
                            <td class="doc-col-amount <?= $alignEnd ?>"><?= $amount((string) $line['prepaidVat']) ?></td>
                            <td class="doc-col-amount <?= $alignEnd ?>"></td>
                        <?php else: ?>
                            <td class="<?= $alignStart ?>"><?= esc($line['name']) ?></td>
                            <td class="doc-col-num"><?= documentIsolate((string) $line['quantity']) ?></td>
                            <td class="doc-col-amount <?= $alignEnd ?>"><?= $amount((string) $line['price']) ?></td>
                            <td class="doc-col-amount <?= $alignEnd ?>"><?= $amount((string) $line['net']) ?></td>
                            <td class="doc-col-num"><?= documentIsolate($line['percent'] . '%') ?></td>
                            <td class="doc-col-amount <?= $alignEnd ?>"><?= $amount((string) $line['vat']) ?></td>
                            <td class="doc-col-amount doc-strong <?= $alignEnd ?>"><?= $amount((string) $line['gross']) ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <table class="einvoice-categories">
        <?php foreach ($einvoice['categories'] as $category): ?>
            <tr>
                <td><?= esc(einvoiceWords('Vat_classes.category_' . $category['category'])) ?> <?= documentIsolate($category['percent'] . '%') ?><?= $category['exemption'] !== '' ? ' — ' . esc($category['exemption']) : '' ?></td>
                <td><?= $amount((string) $category['taxable']) ?></td>
                <td><?= $amount((string) $category['vat']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <table class="einvoice-totals">
        <tr><td><?= esc(einvoiceWords('Documents.einvoice_total_exclusive')) ?></td><td><?= $amount((string) $totals['exclusive']) ?> <?= esc($currency) ?></td></tr>
        <?php if ($shown((string) $totals['charges'])): ?>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_charges')) ?></td><td><?= $amount((string) $totals['charges']) ?></td></tr>
        <?php endif; ?>
        <tr><td><?= esc(einvoiceWords('Documents.einvoice_total_vat')) ?></td><td><?= $amount((string) $totals['vat']) ?></td></tr>
        <?php if ($currency !== 'SAR' && $totals['vatInSar'] !== ''): ?>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_vat_in_sar')) ?></td><td><?= $amount((string) $totals['vatInSar']) ?> SAR</td></tr>
        <?php endif; ?>
        <tr class="einvoice-grand"><td><?= esc(einvoiceWords('Documents.einvoice_total_inclusive')) ?></td><td><?= $amount((string) $totals['inclusive']) ?></td></tr>
        <?php if ($shown((string) $totals['prepaid'])): ?>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_prepaid')) ?></td><td><?= $amount((string) $totals['prepaid']) ?></td></tr>
        <?php endif; ?>
        <?php if ($shown((string) $totals['rounding'])): ?>
            <tr><td><?= esc(einvoiceWords('Documents.einvoice_rounding')) ?></td><td><?= $amount((string) $totals['rounding']) ?></td></tr>
        <?php endif; ?>
        <?php if ($shown((string) $totals['prepaid']) || $shown((string) $totals['rounding'])): ?>
            <tr class="einvoice-grand"><td><?= esc(einvoiceWords('Documents.einvoice_payable')) ?></td><td><?= $amount((string) $totals['payable']) ?></td></tr>
        <?php endif; ?>
    </table>
</div>
