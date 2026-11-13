<?php

use App\Libraries\Documents\VoucherDocument;

/**
 * @var VoucherDocument $voucher
 * @var string          $pdfPath
 * @var string          $backPath
 */
?>
<?= $this->extend('portal/layout') ?>

<?= $this->section('content') ?>
<div class="d-flex gap-2 mb-3 portal-chrome">
    <button type="button" class="btn btn-outline-secondary btn-sm" id="portal_print" onclick="window.print()"><?= esc(lang('Portal.print')) ?></button>
    <a class="btn btn-outline-secondary btn-sm" id="portal_pdf" href="<?= esc(site_url($pdfPath), 'attr') ?>" target="_blank" rel="noopener"><?= esc(lang('Portal.pdf')) ?></a>
    <a class="btn btn-primary btn-sm" id="portal_back" href="<?= esc(site_url($backPath), 'attr') ?>"><?= esc(lang('Portal.back')) ?></a>
</div>
<?= view('documents/voucher', ['voucher' => $voucher, 'context' => 'screen']) ?>
<?= $this->endSection() ?>
