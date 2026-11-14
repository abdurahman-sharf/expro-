<?php
/**
 * @var QrResult $qr
 * @var string   $context
 * @var float    $preferredMm
 */

use App\Libraries\Documents\QrResult;

$qrImage = documentQrImage((string) $qr->payload, $preferredMm);
?>
<div class="doc-qr">
    <div class="doc-qr-label"><?= lang('Documents.qr_label') ?></div>
    <?php if ($qrImage !== null): ?>
        <img class="doc-qr-image" src="<?= esc($qrImage['source']) ?>" alt="<?= esc(lang('Documents.qr_label')) ?>" style="width: <?= sprintf('%.1F', $qrImage['sizeMm']) ?>mm; height: <?= sprintf('%.1F', $qrImage['sizeMm']) ?>mm">
    <?php endif; ?>
    <?php if ($qrImage === null || $context === 'screen'): ?>
        <div class="doc-qr-text<?= $qrImage !== null ? ' print_hide' : '' ?>" dir="ltr"><?= esc((string) $qr->payload) ?></div>
    <?php endif; ?>
</div>
