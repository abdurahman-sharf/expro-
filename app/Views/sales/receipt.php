<?php
/**
 * @var int         $sale_id_num
 * @var bool        $print_after_sale
 * @var string      $receipt_template_view
 * @var string|null $creditNoteNumber
 * @var string|null $refundInvoiceNumber
 * @var array       $config
 */

use App\Models\Employee;

$template = $receipt_template_view ?? 'receipt_default';

?>

<?= view('partial/header') ?>

<?php
if (isset($error_message)) {
    echo '<div class="alert alert-danger">' . $error_message . '</div>';

    exit;
}
?>

<?php if (! empty($customer_email)): ?>
    <script type="text/javascript">
        $(document).ready(function() {
            const send_email = function() {
                $.get('<?= site_url() . esc("/sales/sendPdf/{$sale_id_num}/receipt") ?>',
                    function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        })
                    }, 'json'
                );
            };

            $("#show_email_button").click(send_email);

            <?php if (! empty($email_receipt)): ?>
                send_email();
            <?php endif; ?>
        });
    </script>
<?php endif; ?>

<?= view('partial/print_receipt', ['print_after_sale' => $print_after_sale, 'selected_printer' => 'receipt_printer']) ?>

<?= view('partial/document_actions', ['actions' => array_values(array_filter([
    ['id' => 'show_print_button', 'label' => lang('Common.print'), 'icon' => 'bi-printer', 'onclick' => 'printdoc();'],
    model(Employee::class)->has_grant('reports_sales', session('person_id')) ? ['id' => 'show_pdf_button', 'label' => lang('Sales.pdf'), 'icon' => 'bi-file-earmark-pdf', 'href' => "sales/pdf/{$sale_id_num}/receipt", 'target' => '_blank'] : null,
    (! empty($creditNoteNumber) || ! empty($refundInvoiceNumber)) && model(Employee::class)->has_grant('reports_sales', session('person_id')) ? ['id' => 'show_credit_note_button', 'label' => lang(! empty($creditNoteNumber) ? 'Documents.credit_note_print' : 'Documents.refund_invoice_print'), 'icon' => 'bi-file-earmark-text', 'href' => "sales/print/{$sale_id_num}/credit_note"] : null,
    ! empty($customer_email) ? ['id' => 'show_email_button', 'label' => lang('Sales.send_receipt'), 'icon' => 'bi-envelope'] : null,
    ['id' => 'show_sales_button', 'label' => lang('Sales.register'), 'icon' => 'bi-cart3', 'href' => 'sales', 'class' => 'btn-primary'],
    model(Employee::class)->has_grant('reports_sales', session('person_id')) ? ['id' => 'show_takings_button', 'label' => lang('Sales.takings'), 'icon' => 'bi-card-list', 'href' => 'sales/manage'] : null,
]))]) ?>

<?= view('accounting_einvoicing/sale_panel', ['sale_id' => $sale_id_num]) ?>

<?= view('sales/' . $template) ?>

<?= view('partial/footer') ?>
