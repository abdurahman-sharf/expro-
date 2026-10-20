<?php
/**
 * @var string $sale_id_num
 * @var bool $print_after_sale
 * @var string $customer_info
 * @var string $company_info
 * @var string $invoice_number
 * @var string $transaction_date
 * @var float $total
 * @var bool $include_hsn
 * @var string $discount
 * @var array $cart
 * @var float $subtotal
 * @var array $taxes
 * @var array $payments
 * @var float $amount_change
 * @var string $barcode
 * @var int $sale_id
 * @var array $config
 */

use App\Models\Employee;
?>

<?= view('partial/header') ?>

<?php
if (isset($error_message)) {
    echo '<div class="alert alert-danger">' . $error_message . '</div>';
    exit;
}
?>

<?php if (!empty($customer_email)): ?>
    <script type="text/javascript">
        $(document).ready(function() {
            const send_email = function() {
                $.get('<?= site_url() . "sales/sendPdf/$sale_id_num" ?>',
                    function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        });
                    }, 'json'
                );
            };

            $("#show_email_button").click(send_email);

            <?php if (!empty($email_receipt)): ?>
                send_email();
            <?php endif; ?>
        });
    </script>
<?php endif; ?>

<?= view('partial/print_receipt', ['print_after_sale' => $print_after_sale, 'selected_printer' => 'invoice_printer']) ?>

<?= view('partial/document_actions', ['actions' => array_values(array_filter([
        ['id' => 'show_print_button', 'label' => lang('Common.print'), 'icon' => 'bi-printer', 'onclick' => 'printdoc();'],
        model(Employee::class)->has_grant('reports_sales', session('person_id')) ? ['id' => 'show_pdf_button', 'label' => lang('Sales.pdf'), 'icon' => 'bi-file-earmark-pdf', 'href' => "sales/pdf/$sale_id_num/invoice", 'target' => '_blank'] : null,
        !empty($customer_email) ? ['id' => 'show_email_button', 'label' => lang('Sales.send_invoice'), 'icon' => 'bi-envelope'] : null,
        ['id' => 'show_sales_button', 'label' => lang('Sales.register'), 'icon' => 'bi-cart3', 'href' => 'sales', 'class' => 'btn-primary'],
        ['id' => 'show_takings_button', 'label' => lang('Sales.takings'), 'icon' => 'bi-card-list', 'href' => 'sales/manage']
    ]))]) ?>

<?= view('documents/document', ['type' => 'invoice', 'context' => 'screen', 'sale' => get_defined_vars()]) ?>

<?= view('partial/footer') ?>
