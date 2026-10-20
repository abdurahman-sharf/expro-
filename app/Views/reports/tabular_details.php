<?php
/**
 * @var string $title
 * @var string $subtitle
 * @var array  $overall_summary_data
 * @var array  $details_data
 * @var array  $headers
 * @var array  $summary_data
 * @var array  $config
 */
?>

<?= view('partial/header') ?>

<?= view('reports/_head', ['title' => $title, 'subtitle' => $subtitle, 'sensitiveToggle' => array_key_exists('cost', $overall_summary_data) || array_key_exists('profit', $overall_summary_data)]) ?>

<?= view('reports/_summary', ['summary_data' => $overall_summary_data]) ?>

<div id="table_holder">
    <table id="table"></table>
</div>

<script type="text/javascript">
    $(document).ready(function () {
        <?= view('partial/bootstrap_tables_locale') ?>

        const details_data = <?= json_encode(esc($details_data)) ?>;
        <?php if ($config['customer_reward_enable'] && ! empty($details_data_rewards)) { ?>
            const details_data_rewards = <?= json_encode(esc($details_data_rewards)) ?>;
        <?php } ?>
        <?= view('partial/visibility_js') ?>

        $('#table')
            .addClass("table-striped")
            .addClass("table-bordered")
            .bootstrapTable({
                columns: applyColumnVisibility(<?= transform_headers(esc($headers['summary']), true) ?>),
                stickyHeader: true,
                stickyHeaderOffsetLeft: $('#table').offset().left + 'px',
                stickyHeaderOffsetRight: $('#table').offset().right + 'px',
                pageSize: <?= $config['lines_per_page'] ?>,
                pagination: true,
                sortable: true,
                showColumns: true,
                uniqueId: 'id',
                showExport: true,
                exportDataType: 'all',
                exportTypes: ['json', 'xml', 'csv', 'txt', 'sql', 'excel'],
                buttons: pdf_export.buttons(true),
                buttonsClass: 'outline-secondary',
                data: <?= json_encode($summary_data) ?>,
                iconSize: 'sm',
                loadingTemplate: function (loadingMessage) {
                    return '<div class="loading-message text-center"><span class="spinner-border spinner-border-sm"></span><span role="status">' + loadingMessage + '</span></div>';
                },
                loadingFontSize: '1em',
                paginationVAlign: 'bottom',
                detailView: true,
                escape: true,
                search: true,
                onPageChange: init_dialog,
                onPostBody: function () {
                    dialog_support.init("a.modal-dlg");
                },
                onExpandRow: function (index, row, $detail) {
                    $detail.html('<table></table>').find("table").bootstrapTable({
                        columns: <?= transform_headers_readonly(esc($headers['details'])) ?>,
                        data: details_data[(!isNaN(row.id) && row.id) || $(row[0] || row.id).text().replace(
                            /(POS|RECV)\s*/g, '')]
                    });

                    <?php if ($config['customer_reward_enable'] && ! empty($details_data_rewards)) { ?>
                        $detail.append('<table></table>').find("table").bootstrapTable({
                            columns: <?= transform_headers_readonly(esc($headers['details_rewards'])) ?>,
                            data: details_data_rewards[(!isNaN(row.id) && row.id) || $(row[0] || row.id).text().replace(
                                /(POS|RECV)\s*/g, '')]
                        });
                    <?php } ?>
                }
            });

        init_dialog();
    });
</script>

<?= view('partial/footer') ?>
