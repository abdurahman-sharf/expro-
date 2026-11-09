<?php
/**
 * @var array<string, mixed> $doc
 * @var string               $context
 * @var string               $customerName
 */
$config     = documentConfig();
$rtl        = isRtl();
$alignEnd   = $rtl ? 'al-l' : 'al-r';
$alignStart = $rtl ? 'al-r' : 'al-l';
$logo       = documentLogoSource($config, $context);
?>
<?php if ($context === 'screen') : ?>
    <link rel="stylesheet" href="<?= esc(assetUrl('css/document.css'), 'attr') ?>">
<?php endif; ?>
<div class="doc" id="document" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
    <table class="doc-head">
        <tr>
            <td class="doc-company <?= $alignStart ?>">
                <?php if ($logo !== '') : ?>
                    <img class="doc-logo" src="<?= esc($logo, 'attr') ?>" alt="">
                <?php endif; ?>
                <?php if (! empty($config['receipt_show_company_name'])) : ?>
                    <div class="doc-company-name"><?= esc((string) ($config['company'] ?? '')) ?></div>
                <?php endif; ?>
                <div class="doc-company-info"><?= nl2br(esc(documentCompanyInfo($config))) ?></div>
            </td>
            <td class="doc-titlebox <?= $alignEnd ?>">
                <div class="doc-title"><?= esc($doc['title']) ?></div>
                <table class="doc-meta">
                    <tr>
                        <td class="doc-meta-label <?= $alignStart ?>"><?= esc(lang('Portal.number')) ?></td>
                        <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($doc['number']) ?></td>
                    </tr>
                    <tr>
                        <td class="doc-meta-label <?= $alignStart ?>"><?= esc(lang('Portal.date')) ?></td>
                        <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($doc['date']) ?></td>
                    </tr>
                    <?php if ($doc['due_date'] !== null) : ?>
                        <tr>
                            <td class="doc-meta-label <?= $alignStart ?>"><?= esc(lang('Portal.due_date')) ?></td>
                            <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($doc['due_date']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($doc['origin'] !== null) : ?>
                        <tr>
                            <td class="doc-meta-label <?= $alignStart ?>"><?= esc(lang('Portal.invoice_number')) ?></td>
                            <td class="doc-meta-value <?= $alignEnd ?>"><?= documentIsolate($doc['origin']) ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>

    <div class="doc-party">
        <div class="doc-party-label"><?= esc(lang('Portal.customer')) ?></div>
        <div class="doc-party-body"><?= nl2br(esc($customerName)) ?></div>
    </div>

    <table class="doc-items">
        <thead>
            <tr>
                <th class="doc-col-no">#</th>
                <th class="<?= $alignStart ?>"><?= esc(lang('Portal.item')) ?></th>
                <th class="doc-col-num"><?= esc(lang('Portal.quantity')) ?></th>
                <th class="doc-col-amount <?= $alignEnd ?>"><?= esc(lang('Portal.price')) ?></th>
                <th class="doc-col-amount <?= $alignEnd ?>"><?= esc(lang('Portal.discount')) ?></th>
                <th class="doc-col-amount <?= $alignEnd ?>"><?= esc(lang('Portal.total')) ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($doc['lines'] as $position => $line) : ?>
                <tr class="<?= $position % 2 === 1 ? 'alt' : '' ?>">
                    <td class="doc-col-no"><?= $position + 1 ?></td>
                    <td class="<?= $alignStart ?>"><div class="doc-item-name"><?= esc($line['name']) ?></div></td>
                    <td class="doc-col-num"><?= documentIsolate($line['quantity']) ?></td>
                    <td class="doc-col-amount <?= $alignEnd ?>"><?= documentIsolate($line['price']) ?></td>
                    <td class="doc-col-amount <?= $alignEnd ?>"><?= $line['discount'] === '' ? '' : documentIsolate($line['discount']) ?></td>
                    <td class="doc-col-amount doc-strong <?= $alignEnd ?>"><?= documentIsolate($line['total']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="doc-lower">
        <tr>
            <td class="doc-notes <?= $alignStart ?>"></td>
            <td class="doc-gap"></td>
            <td class="doc-totals-cell">
                <table class="doc-totals">
                    <tr>
                        <td class="<?= $alignStart ?>"><?= esc(lang('Portal.goods')) ?></td>
                        <td class="<?= $alignEnd ?>"><?= documentIsolate($doc['goods']) ?></td>
                    </tr>
                    <?php if ($doc['shipping'] !== null) : ?>
                        <tr>
                            <td class="<?= $alignStart ?>"><?= esc(lang('Portal.shipping')) ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate($doc['shipping']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($doc['adjustment'] !== null) : ?>
                        <tr>
                            <td class="<?= $alignStart ?>"><?= esc(lang('Portal.adjustment')) ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate($doc['adjustment']) ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="<?= $alignStart ?>"><?= esc(lang('Portal.tax')) ?></td>
                        <td class="<?= $alignEnd ?>"><?= documentIsolate($doc['tax']) ?></td>
                    </tr>
                    <tr class="doc-grand">
                        <td class="<?= $alignStart ?>"><?= esc(lang('Portal.total')) ?></td>
                        <td class="<?= $alignEnd ?>"><?= documentIsolate($doc['total']) ?></td>
                    </tr>
                    <?php foreach ($doc['payments'] as $payment) : ?>
                        <tr>
                            <td class="<?= $alignStart ?>"><?= esc($payment['method']) ?> <?= documentIsolate($payment['date']) ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate($payment['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($doc['open'] !== null) : ?>
                        <tr class="doc-balance">
                            <td class="<?= $alignStart ?>"><?= esc(lang('Portal.remaining')) ?></td>
                            <td class="<?= $alignEnd ?>"><?= documentIsolate($doc['open']) ?></td>
                        </tr>
                    <?php endif; ?>
                </table>
            </td>
        </tr>
    </table>
</div>
