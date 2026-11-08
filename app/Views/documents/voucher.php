<?php

use App\Libraries\Documents\VoucherDocument;

/**
 * @var VoucherDocument $voucher
 * @var string          $context
 */
$config     = documentConfig();
$rtl        = isRtl();
$alignEnd   = $rtl ? 'al-l' : 'al-r';
$alignStart = $rtl ? 'al-r' : 'al-l';
$logo       = documentLogoSource($config, $context);
?>
<?php if ($context === 'screen'): ?>
    <link rel="stylesheet" href="<?= assetUrl('css/document.css') ?>">
<?php endif; ?>
<div class="doc doc-voucher" id="document" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
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
                <div class="doc-title"><?= esc($voucher->title) ?></div>
                <table class="doc-meta">
                    <tr>
                        <td class="doc-meta-label <?= $alignStart ?>"><?= lang('Documents.number') ?></td>
                        <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($voucher->number) ?></td>
                    </tr>
                    <tr>
                        <td class="doc-meta-label <?= $alignStart ?>"><?= lang('Common.date') ?></td>
                        <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($voucher->date) ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="doc-voucher-body">
        <?php if ($voucher->partyName !== ''): ?>
            <tr class="doc-voucher-party">
                <td class="doc-voucher-label <?= $alignStart ?>"><?= esc($voucher->partyLabel) ?></td>
                <td class="<?= $alignStart ?>"><?= nl2br(esc($voucher->partyName)) ?></td>
            </tr>
        <?php endif; ?>
        <tr class="doc-voucher-amount">
            <td class="doc-voucher-label <?= $alignStart ?>"><?= lang('Documents.amount') ?></td>
            <td class="<?= $alignStart ?>"><?= documentIsolate($voucher->amountText) ?></td>
        </tr>
        <?php if ($voucher->amountWords !== null): ?>
            <tr class="doc-voucher-words">
                <td class="doc-voucher-label <?= $alignStart ?>"><?= lang('Documents.amount_in_words') ?></td>
                <td class="<?= $alignStart ?>"><?= esc($voucher->amountWords) ?></td>
            </tr>
        <?php endif; ?>
        <?php foreach ($voucher->rows as [$label, $value]): ?>
            <tr>
                <td class="doc-voucher-label <?= $alignStart ?>"><?= esc($label) ?></td>
                <td class="<?= $alignStart ?>"><?= nl2br(esc($value)) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <?php if ($voucher->signatures !== []): ?>
        <table class="doc-sign">
            <tr>
                <?php foreach ($voucher->signatures as $index => $signature): ?>
                    <?php if ($index > 0): ?><td class="doc-sign-gap"></td><?php endif; ?>
                    <td class="doc-sign-box"><?= esc($signature) ?></td>
                <?php endforeach; ?>
            </tr>
        </table>
    <?php endif; ?>
</div>
