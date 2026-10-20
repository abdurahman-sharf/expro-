<?php
/**
 * @var string $specific_input_name
 * @var array  $specific_input_data
 * @var array  $sale_type_options
 * @var array  $payment_type
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

            <div class="field" id="report_specific_input_data">
                <?= form_label($specific_input_name, 'specific_input_data', ['class' => 'form-label text-danger required']) ?>
                <?= form_dropdown('specific_input_data', $specific_input_data, '', 'id="specific_input_data" class="form-control selectpicker" data-live-search="true" data-width="100%"') ?>
            </div>

            <div class="field" id="report_sale_type">
                <?= form_label(lang('Reports.sale_type'), 'input_type', ['class' => 'form-label text-danger required']) ?>
                <?= form_dropdown('sale_type', $sale_type_options, 'complete', 'id="input_type" class="form-select"') ?>
            </div>

            <div class="field">
                <?= form_label(lang('Reports.payment_type'), 'input_payment_type', ['class' => 'form-label text-danger required']) ?>
                <?= form_dropdown('payment_type', $payment_type, '', 'id="input_payment_type" class="form-select"') ?>
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
        <?= view('partial/daterangepicker') ?>
        $('#daterangepicker').css('width', '100%');

        $("#generate_report").click(function() {
            window.location = [window.location, start_date, end_date, $('#specific_input_data').val(), $("#input_type").val(), $('#input_payment_type').val() || 0].join("/");
        });

    });
</script>
