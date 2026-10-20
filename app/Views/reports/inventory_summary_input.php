<?php
/**
 * @var array $stock_locations
 * @var array $item_count
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    dialog_support.init("a.modal-dlg");
</script>

<?= view('reports/_head', ['title' => lang('Reports.report_input'), 'subtitle' => '']) ?>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= esc($error) ?></div>
<?php endif; ?>

<?= form_open('#', ['id' => 'item_form', 'enctype' => 'multipart/form-data', 'class' => 'app-form report-form card']) ?>
    <div class="card-body">
        <div class="form-grid">
            <div class="field" id="report_stock_location">
                <?= form_label(lang('Reports.stock_location'), 'location_id', ['class' => 'form-label text-danger required']) ?>
                <?= form_dropdown('stock_location', $stock_locations, 'all', 'id="location_id" class="form-select"') ?>
            </div>

            <div class="field" id="report_item_count">
                <?= form_label(lang('Reports.item_count'), 'item_count', ['class' => 'form-label text-danger required']) ?>
                <?= form_dropdown('item_count', $item_count, 'all', 'id="item_count" class="form-select"') ?>
            </div>
        </div>

        <div class="report-form-actions">
            <?= form_button([
                'name'    => 'generate_report',
                'id'      => 'generate_report',
                'content' => lang('Reports.show_report'),
                'class'   => 'btn btn-primary',
            ]) ?>
        </div>
    </div>
<?= form_close() ?>

<?= view('partial/footer') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $("#generate_report").click(function() {
            window.location = [window.location, $("#location_id").val(), $("#item_count").val()].join("/");
        });
    });
</script>
