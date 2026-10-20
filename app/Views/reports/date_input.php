<?php
/**
 * @var array $sale_type_options
 * @var array $config
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
            <div class="field">
                <?= form_label(lang('Reports.date_range'), 'daterangepicker', ['class' => 'form-label text-danger required']) ?>
                <?= form_input(['name' => 'daterangepicker', 'class' => 'form-control ltr-input', 'id' => 'daterangepicker']) ?>
            </div>

            <?php if (! empty($mode)): ?>
                <?php if ($mode === 'sale'): ?>
                    <div class="field" id="report_sale_type">
                        <?= form_label(lang('Reports.sale_type'), 'input_type', ['class' => 'form-label text-danger required']) ?>
                        <?= form_dropdown('sale_type', $sale_type_options, $selected_sale_type ?? 'complete', ['id' => 'input_type', 'class' => 'form-select']) ?>
                    </div>
                <?php elseif ($mode === 'receiving'): ?>
                    <div class="field" id="report_receiving_type">
                        <?= form_label(lang('Reports.receiving_type'), 'input_type', ['class' => 'form-label text-danger required']) ?>
                        <?= form_dropdown(
                            'receiving_type',
                            [
                                'all'          => lang('Reports.all'),
                                'receiving'    => lang('Reports.receivings'),
                                'returns'      => lang('Reports.returns'),
                                'requisitions' => lang('Reports.requisitions'),
                            ],
                            'all',
                            ['id' => 'input_type', 'class' => 'form-select'],
                        ) ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (isset($discount_type_options)): ?>
                <div class="field" id="report_discount_type">
                    <?= form_label(lang('Reports.discount_type'), 'discount_type_id', ['class' => 'form-label text-danger required']) ?>
                    <?= form_dropdown('discount_type', $discount_type_options, $selected_discount_type ?? $config['default_sales_discount_type'], ['id' => 'discount_type_id', 'class' => 'form-select']) ?>
                </div>
            <?php endif; ?>

            <?php if (! empty($stock_locations) && count($stock_locations) > 2): ?>
                <div class="field" id="report_stock_location">
                    <?= form_label(lang('Reports.stock_location'), 'location_id', ['class' => 'form-label text-danger required']) ?>
                    <?= form_dropdown('stock_location', $stock_locations, 'all', ['id' => 'location_id', 'class' => 'form-select']) ?>
                </div>
            <?php endif; ?>
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
        <?= view('partial/daterangepicker') ?>
        $('#daterangepicker').css('width', '100%');

        $("#generate_report").click(function() {
            window.location = [window.location, start_date, end_date, $("#input_type").val() || 0, $("#location_id").val() || 'all', $("#discount_type_id").val() || 0].join("/");
        });
    });
</script>
