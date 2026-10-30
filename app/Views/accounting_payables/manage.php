<?php
/**
 * @var string $table_headers
 * @var array  $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'accounting_payables',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'supplier_id',
            cardTitle: ['supplier_name'],
            sortName: 'balance',
            sortOrder: 'desc',
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_payables.empty')) . '</p>', $jsonFlags) ?>;
            }
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_payables/balances'), 'attr') ?>">
        <span class="bi bi-table" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.balances_title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_payables/aged_ledger'), 'attr') ?>">
        <span class="bi bi-hourglass-split" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.aged_title') ?>
    </a>
    <p class="mb-0 text-body-secondary"><?= lang('Accounting_payables.note') ?></p>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
