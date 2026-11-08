<?php
/**
 * @var string $type
 * @var array  $sale
 * @var int    $sale_id_num
 * @var string $customer_email
 */

use App\Models\Employee;

$canPdf = model(Employee::class)->has_grant('reports_sales', session('person_id'));
?>

<?= view('partial/header') ?>

<?php if ($customer_email !== ''): ?>
    <script type="text/javascript">
        $(document).ready(function() {
            $("#show_email_button").click(function() {
                $.get('<?= site_url('sales/sendPdf/' . (int) $sale_id_num . '/' . rawurlencode($type)) ?>',
                    function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        });
                    }, 'json'
                );
            });
        });
    </script>
<?php endif; ?>

<?= view('partial/print_receipt', ['print_after_sale' => false, 'selected_printer' => 'invoice_printer']) ?>

<?= view('partial/document_actions', ['actions' => array_values(array_filter([
    ['id' => 'show_print_button', 'label' => lang('Common.print'), 'icon' => 'bi-printer', 'onclick' => 'printdoc();'],
    $canPdf ? ['id' => 'show_pdf_button', 'label' => lang('Sales.pdf'), 'icon' => 'bi-file-earmark-pdf', 'href' => 'sales/pdf/' . (int) $sale_id_num . '/' . rawurlencode($type), 'target' => '_blank'] : null,
    $customer_email !== '' ? ['id' => 'show_email_button', 'label' => lang('Documents.send'), 'icon' => 'bi-envelope'] : null,
    ['id' => 'show_sales_button', 'label' => lang('Sales.register'), 'icon' => 'bi-cart3', 'href' => 'sales', 'class' => 'btn-primary'],
    ['id' => 'show_takings_button', 'label' => lang('Sales.takings'), 'icon' => 'bi-card-list', 'href' => 'sales/manage'],
]))]) ?>

<?= view('documents/document', ['type' => $type, 'context' => 'screen', 'sale' => $sale]) ?>

<?= view('partial/footer') ?>
