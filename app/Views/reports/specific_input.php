<?php
/**
 * @var string $specific_input_name
 * @var array  $specific_input_data
 * @var array  $sale_type_options
 * @var array  $config
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

            <?php if (isset($discount_type_options)): ?>
                <div class="field" id="report_discount_type">
                    <?= form_label(lang('Reports.discount_type'), 'discount_type_id', ['class' => 'form-label text-danger required']) ?>
                    <?= form_dropdown('discount_type', $discount_type_options, $config['default_sales_discount_type'], ['id' => 'discount_type_id', 'class' => 'form-select']) ?>
                </div>
            <?php endif; ?>

            <div class="field" id="report_specific_input_data">
                <?= form_label($specific_input_name, 'specific_input_data', ['class' => 'form-label text-danger required']) ?>
                <div class="discount_percent">
                    <?= form_dropdown('specific_input_data', $specific_input_data, '', 'id="specific_input_data" class="form-select"') ?>
                </div>
                <?php if (isset($discount_type_options)): ?>
                    <div class="discount_fixed">
                        <?= form_input([
                            'name'       => 'discount_fixed',
                            'id'         => 'discount_fixed',
                            'class'      => 'form-control required',
                            'type'       => 'number',
                            'min'        => 0,
                            'value'      => $config['default_sales_discount'],
                            'aria-label' => $specific_input_name,
                        ]) ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="field" id="report_sale_type">
                <?= form_label(lang('Reports.sale_type'), 'input_type', ['class' => 'form-label text-danger required']) ?>
                <?= form_dropdown('sale_type', $sale_type_options, 'complete', 'id="input_type" class="form-select"') ?>
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
        <?php if (isset($discount_type_options)) { ?>
            $("#discount_type_id").change(check_discount_type).ready(check_discount_type);
        <?php } ?>

        <?= view('partial/daterangepicker') ?>
        $('#daterangepicker').css('width', '100%');

        $("#generate_report").click(function() {
            let specific_input_data = $('#specific_input_data').val();
            if (!$(".discount_percent").is(":visible")) {
                specific_input_data = $('#discount_fixed').val();
            }

            window.location = [window.location, start_date, end_date, specific_input_data, $("#input_type").val() || 0, $("#discount_type_id").val() || 0].join("/");
        });
    });

    function check_discount_type() {
        const discount_type = $("#discount_type_id").val();

        if (discount_type == 1) {
            $(".discount_percent").hide();
            $(".discount_fixed").show();
        } else {
            $(".discount_percent").show();
            $(".discount_fixed").hide();
        }
    }
</script>
