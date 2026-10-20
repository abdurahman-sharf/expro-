<?php
/**
 * @var int   $person_id
 * @var array $permission_ids
 * @var array $grants
 */

$detailed_reports = [
    'reports_sales'      => 'detailed',
    'reports_receivings' => 'detailed',
    'reports_customers'  => 'specific',
    'reports_discounts'  => 'specific',
    'reports_employees'  => 'specific',
    'reports_suppliers'  => 'specific',
];
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<?php
if (isset($error)) {
    echo '<div class="alert alert-danger">' . esc($error) . '</div>';
}
?>

<div class="row">
    <div class="col-lg-4">
        <div class="card border-primary">
            <div class="card-header">
                <h3 class="card-title h6 mb-0"><span class="bi bi-bar-chart" aria-hidden="true">&nbsp;</span><?= lang('Reports.graphical_reports') ?></h3>
            </div>
            <div class="list-group">
                <?php foreach ($permission_ids as $permission_id) {
                    if (can_show_report($permission_id, ['inventory', 'receiving'])) {
                        $link = get_report_link($permission_id, 'graphical_summary');
                ?>
                        <a class="list-group-item" href="<?= $link['path'] ?>"><?= esc($link['label']) ?></a>
                <?php
                    }
                }
                ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-primary">
            <div class="card-header">
                <h3 class="card-title h6 mb-0"><span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Reports.summary_reports') ?></h3>
            </div>
            <div class="list-group">
                <?php foreach ($permission_ids as $permission_id) {
                    if (can_show_report($permission_id, ['inventory', 'receiving'])) {
                        $link = get_report_link($permission_id, 'summary');
                ?>
                        <a class="list-group-item" href="<?= $link['path'] ?>"><?= esc($link['label']) ?></a>
                <?php
                    }
                }
                ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-primary">
            <div class="card-header">
                <h3 class="card-title h6 mb-0"><span class="bi bi-card-list" aria-hidden="true">&nbsp;</span><?= lang('Reports.detailed_reports') ?></h3>
            </div>
            <div class="list-group">
                <?php foreach ($detailed_reports as $report_name => $prefix) {
                    if (in_array($report_name, $permission_ids, true)) {
                        $link = get_report_link($report_name, $prefix);
                ?>
                        <a class="list-group-item" href="<?= $link['path'] ?>"><?= esc($link['label']) ?></a>
                        <?php if ($report_name === 'reports_sales') {
                            $item_sales_link = get_report_link('reports_item_sales', 'detailed', 'Reports.detailed_item_sales_report');
                        ?>
                            <a class="list-group-item" href="<?= $item_sales_link['path'] ?>"><?= esc($item_sales_link['label']) ?></a>
                        <?php } ?>
                <?php
                    }
                }
                ?>
            </div>
        </div>

        <?php if (in_array('reports_inventory', $permission_ids, true)) { ?>
            <div class="card border-primary">
                <div class="card-header">
                    <h3 class="card-title h6 mb-0"><span class="bi bi-book" aria-hidden="true">&nbsp;</span><?= lang('Reports.inventory_reports') ?></h3>
                </div>
                <div class="list-group">
                    <?php
                    $inventory_low_report = get_report_link('reports_inventory_low');
                    $inventory_summary_report = get_report_link('reports_inventory_summary');
                    ?>
                    <a class="list-group-item" href="<?= $inventory_low_report['path'] ?>"><?= esc($inventory_low_report['label']) ?></a>
                    <a class="list-group-item" href="<?= $inventory_summary_report['path'] ?>"><?= esc($inventory_summary_report['label']) ?></a>
                </div>
            </div>
        <?php } ?>
    </div>
</div>

<?= view('partial/footer') ?>
