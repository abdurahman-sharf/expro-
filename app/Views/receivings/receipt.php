<?php
/**
 * @var bool $print_after_sale
 * @var string $transaction_time
 * @var int $receiving_id
 * @var string $employee
 * @var array $cart
 * @var bool $show_stock_locations
 * @var float $total
 * @var string $tax_total
 * @var string $total_with_tax
 * @var App\Libraries\Accounting\InvoiceDiscount $invoiceDiscount
 * @var string $invoiceDiscountAmount
 * @var string $mode
 * @var string $payment_type
 * @var float $amount_tendered
 * @var float $amount_change
 * @var string $barcode
 * @var array $config
 * @var string|null $documentLabel
 * @var string|null $documentNumber
 * @var string|null $invoice_date
 * @var string|null $due_date
 * @var string|null $supplier_tax_id
 * @var string|null $supplier_commercial_registry
 */
?>

<?= view('partial/header') ?>

<?php
if (isset($error_message)) {
    echo '<div class="alert alert-danger">' . esc($error_message) . '</div>';
    exit;
}

echo view('partial/print_receipt', ['print_after_sale', $print_after_sale, 'selected_printer' => 'receipt_printer']) ?>

<?php $receivingNumber = (int) trim(substr((string) $receiving_id, 4)); ?>
<?= view('partial/document_actions', ['actions' => array_values(array_filter([
        ['id' => 'show_print_button', 'label' => lang('Common.print'), 'icon' => 'bi-printer', 'onclick' => 'printdoc();'],
        $receivingNumber > 0 && $mode != 'requisition' ? ['id' => 'show_invoice_button', 'label' => lang($mode == 'return' ? 'Receivings.debit_note' : 'Documents.purchase_invoice'), 'icon' => 'bi-file-earmark-text', 'href' => 'receivings/print/' . $receivingNumber] : null,
        ['id' => 'show_sales_button', 'label' => lang('Receivings.register'), 'icon' => 'bi-box-arrow-in-down', 'href' => 'receivings', 'class' => 'btn-primary']
    ]))]) ?>

<div id="receipt_wrapper">
    <div id="receipt_header">
        <?php if ($config['company_logo'] != '') { ?>
            <div id="company_name">
                <img id="image" src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="company_logo">
            </div>
        <?php } ?>

        <?php if ($config['receipt_show_company_name']) { ?>
            <div id="company_name"><?= esc($config['company']) ?></div>
        <?php } ?>

        <div id="company_address"><?= nl2br(esc($config['address'])) ?></div>
        <div id="company_phone"><?= esc($config['phone']) ?></div>
        <div id="sale_receipt"><?= lang('Receivings.receipt') ?></div>
        <div id="sale_time"><?= esc($transaction_time) ?></div>
    </div>

    <div id="receipt_general_info">
        <?php if (isset($supplier)) { ?>
            <div id="customer"><?= lang('Suppliers.supplier') . esc(": $supplier") ?></div>
            <?php if (!empty($supplier_tax_id)) { ?>
                <div id="supplier_tax_id"><?= lang('Receivings.supplier_tax_id') . esc(": $supplier_tax_id") ?></div>
            <?php } ?>
            <?php if (!empty($supplier_commercial_registry)) { ?>
                <div id="supplier_commercial_registry"><?= lang('Receivings.supplier_commercial_registry') . esc(": $supplier_commercial_registry") ?></div>
            <?php } ?>
        <?php } ?>
        <div id="sale_id"><?= lang('Receivings.id') . ": $receiving_id" ?></div>
        <?php if (!empty($documentNumber)) { ?>
            <div id="document_number"><?= esc($documentLabel) . esc(": $documentNumber") ?></div>
        <?php } ?>
        <?php if (!empty($reference)) { ?>
            <div id="reference"><?= lang('Receivings.reference') . esc(": $reference") ?></div>
        <?php } ?>
        <?php if (!empty($invoice_date)) { ?>
            <div id="invoice_date"><?= lang('Receivings.invoice_date') . esc(": $invoice_date") ?></div>
        <?php } ?>
        <?php if (!empty($due_date)) { ?>
            <div id="due_date"><?= lang('Receivings.due_date') . esc(": $due_date") ?></div>
        <?php } ?>
        <div id="employee"><?= lang('Employees.employee') . esc(": $employee") ?></div>
    </div>

    <table id="receipt_items">
        <tr>
            <th style="width: 40%;"><?= lang('Items.item') ?></th>
            <th style="width: 20%;"><?= lang('Common.price') ?></th>
            <th style="width: 20%;"><?= lang('Sales.quantity') ?></th>
            <th style="width: 15%; text-align: right;"><?= lang('Sales.total') ?></th>
        </tr>

        <?php foreach (array_reverse($cart, true) as $line => $item) { ?>
            <tr>
                <td><?= esc($item['name'] . ' ' . $item['attribute_values']) ?></td>
                <td><?= to_currency($item['price']) ?></td>
                <td><?= to_quantity_decimals($item['quantity']) . ' ' . ($show_stock_locations ? ' [' . esc($item['stock_name']) . ']' : '') ?>&nbsp;&nbsp;&nbsp;x <?= $item['receiving_quantity'] != 0 ? to_quantity_decimals($item['receiving_quantity']) : 1 ?></td>
                <td><div class="total-value"><?= to_currency($item['total']) ?></div></td>
            </tr>
            <tr>
                <td><?= esc($item['serialnumber']) ?></td>
            </tr>
            <?php if ($item['discount'] > 0) { ?>
                <tr>
                    <?php if ($item['discount_type'] == FIXED || $item['discount_type'] == FIXED_LINE) { ?>
                        <td colspan="3" class="discount"><?= to_currency($item['discount']) . ' ' . lang('Sales.discount') ?></td>
                    <?php } elseif ($item['discount_type'] == PERCENT) { ?>
                        <td colspan="3" class="discount"><?= to_decimals($item['discount']) . ' ' . lang('Sales.discount_included') ?></td>
                    <?php } ?>
                </tr>
            <?php } ?>
        <?php } ?>
        <?php $hasInvoiceDiscount = bccomp((string) ($invoiceDiscountAmount ?? '0'), '0', 4) !== 0; ?>
        <?php $showsTax           = bccomp((string) ($tax_total ?? '0'), '0', 8) > 0 || ($hasInvoiceDiscount && bccomp((string) ($tax_total ?? '0'), '0', 8) !== 0); ?>
        <?php if (bccomp((string) ($tax_total ?? '0'), '0', 8) > 0 || $hasInvoiceDiscount) { ?>
            <tr>
                <td colspan="3" style="text-align: right; border-top: 2px solid #000000;"><?= lang('Receivings.sub_total') ?></td>
                <td style="border-top: 2px solid #000000;">
                    <div class="total-value"><?= to_currency($total) ?></div>
                </td>
            </tr>
        <?php } ?>
        <?php if ($hasInvoiceDiscount) { ?>
            <tr id="receipt_invoice_discount">
                <td colspan="3" style="text-align: right;"><?= lang(bccomp((string) $invoiceDiscountAmount, '0', 4) > 0 ? 'Receivings.invoice_discount_share' : 'Receivings.invoice_discount_amount') ?><?= isset($invoiceDiscount) && $invoiceDiscount->type === PERCENT && ! $invoiceDiscount->isNone() ? esc(' (' . to_decimals($invoiceDiscount->value) . '%)') : '' ?></td>
                <td>
                    <div class="total-value"><?= to_currency($invoiceDiscountAmount) ?></div>
                </td>
            </tr>
        <?php } ?>
        <?php if ($showsTax) { ?>
            <tr id="receipt_tax">
                <td colspan="3" style="text-align: right;"><?= lang('Receivings.tax_total') ?></td>
                <td>
                    <div class="total-value"><?= to_currency($tax_total) ?></div>
                </td>
            </tr>
        <?php } ?>
        <tr>
            <td colspan="3" style="text-align: right; border-top: 2px solid #000000;"><?= lang('Sales.total') ?></td>
            <td style="border-top: 2px solid #000000;">
                <div class="total-value"><?= to_currency($total_with_tax ?? $total) ?></div>
            </td>
        </tr>
        <?php if ($mode != 'requisition') { ?>
            <tr>
                <td colspan="3" style="text-align: right;"><?= lang('Sales.payment') ?></td>
                <td>
                    <div class="total-value"><?= esc($payment_type) ?></div>
                </td>
            </tr>

            <?php if (isset($amount_change)) { ?>
                <tr>
                    <td colspan="3" style="text-align: right;"><?= lang('Sales.amount_tendered') ?></td>
                    <td>
                        <div class="total-value"><?= to_currency($amount_tendered) ?></div>
                    </td>
                </tr>

                <tr>
                    <td colspan="3" style="text-align: right;"><?= lang('Sales.change_due') ?></td>
                    <td>
                        <div class="total-value"><?= $amount_change ?></div>
                    </td>
                </tr>
            <?php } ?>
        <?php } ?>
    </table>

    <div id="sale_return_policy">
        <?= nl2br(esc($config['return_policy'])) ?>
    </div>

    <div id="barcode">
        <?= $barcode ?><br>
        <?= $receiving_id ?>
    </div>
</div>

<?= view('partial/footer') ?>
