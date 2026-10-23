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
            resource: 'accounting_receivables',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'customer_id',
            cardTitle: ['customer_name'],
            sortName: 'balance',
            sortOrder: 'desc',
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_receivables.empty')) . '</p>', $jsonFlags) ?>;
            }
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <p class="mb-0 text-body-secondary"><?= lang('Accounting_receivables.note') ?></p>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
