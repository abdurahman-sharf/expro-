<?php
/**
 * @var string $table_headers
 * @var bool $can_manage
 * @var array $config
 */

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'accounting_treasuries/transfers',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'transfer_id',
            cardTitle: ['transfer_id'],
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_treasuries.empty_transfers')) . '</p>', $jsonFlags) ?>;
            }
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries"><span class="bi bi-wallet2" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.treasuries') ?></a>
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_treasuries/transfer_form" title="<?= lang('Accounting_treasuries.new_transfer') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.new_transfer') ?>
        </button>
    <?php endif; ?>
</div>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
