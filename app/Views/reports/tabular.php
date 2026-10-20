<?php
/**
 * @var string $title
 * @var string $subtitle
 * @var array  $summary_data
 * @var array  $headers
 * @var array  $data
 * @var array  $config
 */
?>

<?= view('partial/header') ?>

<?= view('reports/_head', ['title' => $title, 'subtitle' => $subtitle, 'sensitiveToggle' => ! empty($summary_data) && (array_key_exists('cost', $summary_data) || array_key_exists('profit', $summary_data))]) ?>

<?= view('reports/_summary', ['summary_data' => $summary_data]) ?>

<div id="table_holder">
    <table id="table"></table>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        <?= view('partial/bootstrap_tables_locale') ?>
        <?= view('partial/visibility_js') ?>

        const columns = applyColumnVisibility(<?= transform_headers(esc($headers), true, false) ?>);

        $('#table')
            .addClass("table-striped")
            .addClass("table-bordered")
            .bootstrapTable($.extend(table_support.report_options(columns), {
                stickyHeader: true,
                stickyHeaderOffsetLeft: $('#table').offset().left + 'px',
                stickyHeaderOffsetRight: $('#table').offset().right + 'px',
                pageSize: <?= $config['lines_per_page'] ?>,
                sortable: true,
                showExport: true,
                exportDataType: 'all',
                exportTypes: ['json', 'xml', 'csv', 'txt', 'sql', 'excel'],
                buttons: pdf_export.buttons(true),
                buttonsClass: 'outline-secondary',
                pagination: true,
                showColumns: true,
                data: <?= json_encode($data) ?>,
                iconSize: 'sm',
                paginationVAlign: 'bottom',
                escape: true,
                search: true,
                loadingTemplate: function (loadingMessage) {
                    return '<div class="loading-message text-center"><span class="spinner-border spinner-border-sm"></span><span role="status">' + loadingMessage + '</span></div>';
                },
                loadingFontSize: '1em'
            }));
    });
</script>

<?= view('partial/footer') ?>
