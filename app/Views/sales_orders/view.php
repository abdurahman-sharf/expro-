<?php

use App\Libraries\SalesOrders\SalesOrderStatus;

/**
 * @var object                 $order
 * @var SalesOrderStatus       $status
 * @var string                 $number
 * @var list<object>           $lines
 * @var list<object>           $invoices
 * @var bool                   $canEdit
 * @var bool                   $canStatus
 * @var bool                   $canCancel
 * @var bool                   $canConvert
 * @var list<SalesOrderStatus> $next
 */
$orderId = (int) $order->order_id;
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar gap-2">
    <h1 class="h5 me-auto mb-0"><?= lang('Sales_orders.order') ?> <bdi dir="ltr"><?= esc($number) ?></bdi></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('sales_orders'), 'attr') ?>"><?= lang('Sales_orders.back') ?></a>
    <?php if ($canEdit): ?>
        <a class="btn btn-outline-secondary btn-sm" id="order_edit" href="<?= esc(base_url('sales_orders/edit/' . $orderId), 'attr') ?>"><?= lang('Sales_orders.edit') ?></a>
    <?php endif; ?>
    <?php if ($canStatus): ?>
        <?php foreach ($next as $to): ?>
            <button type="button" class="btn btn-outline-primary btn-sm order-status" data-status="<?= esc($to->value, 'attr') ?>"><?= lang('Sales_orders.move_to_' . $to->value) ?></button>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if ($canConvert): ?>
        <button type="button" class="btn btn-primary btn-sm" id="order_convert"><?= lang('Sales_orders.convert') ?></button>
    <?php endif; ?>
    <?php if ($canCancel): ?>
        <button type="button" class="btn btn-outline-danger btn-sm" id="order_cancel"><?= lang('Sales_orders.cancel') ?></button>
    <?php endif; ?>
</div>

<dl class="row" id="order_header">
    <dt class="col-sm-3"><?= lang('Sales_orders.status') ?></dt>
    <dd class="col-sm-9"><span class="badge text-bg-primary" id="order_status"><?= lang($status->languageKey()) ?></span></dd>
    <dt class="col-sm-3"><?= lang('Sales_orders.customer') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $order->customer_name) ?></dd>
    <dt class="col-sm-3"><?= lang('Sales_orders.date') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $order->order_date) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Sales_orders.branch') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $order->branch_code) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Sales_orders.created_by') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $order->creator_name) ?></dd>
    <?php if ((string) $order->note !== ''): ?>
        <dt class="col-sm-3"><?= lang('Sales_orders.note') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $order->note) ?></dd>
    <?php endif; ?>
    <?php if ($order->status === 'cancelled'): ?>
        <dt class="col-sm-3"><?= lang('Sales_orders.cancel_reason') ?></dt>
        <dd class="col-sm-9" id="order_cancel_reason"><?= esc((string) $order->cancel_reason) ?></dd>
    <?php endif; ?>
</dl>

<div class="table-responsive">
    <table class="table table-sm align-middle" id="order_lines">
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col"><?= lang('Sales_orders.item') ?></th>
                <th scope="col" class="text-end"><?= lang('Sales_orders.quantity') ?></th>
                <th scope="col" class="text-end"><?= lang('Sales_orders.invoiced') ?></th>
                <th scope="col" class="text-end"><?= lang('Sales_orders.remaining') ?></th>
                <th scope="col" class="text-end"><?= lang('Sales_orders.unit_price') ?></th>
                <th scope="col" class="text-end"><?= lang('Sales_orders.discount') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr id="order_line_<?= (int) $line->line_id ?>">
                    <td><?= (int) $line->line_no ?></td>
                    <td><?= esc((string) $line->item_name) ?><?= (string) $line->description === '' ? '' : '<br><small class="text-body-secondary">' . esc((string) $line->description) . '</small>' ?></td>
                    <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->quantity) ?></bdi></td>
                    <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->invoiced) ?></bdi></td>
                    <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->remaining) ?></bdi></td>
                    <td class="text-end"><bdi dir="ltr"><?= to_currency($line->unit_price) ?></bdi></td>
                    <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->discount) ?><?= (int) $line->discount_type === 0 ? '%' : '' ?></bdi></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2 class="h6 mt-3"><?= lang('Sales_orders.invoices') ?></h2>
<ul id="order_invoices">
    <?php if ($invoices === []): ?>
        <li class="text-body-secondary"><?= lang('Sales_orders.no_invoices') ?></li>
    <?php endif; ?>
    <?php foreach ($invoices as $invoice): ?>
        <li id="order_invoice_<?= (int) $invoice->sale_id ?>">
            <a href="<?= esc(base_url(((int) $invoice->sale_status === COMPLETED ? 'sales/invoice/' : 'sales/document/') . (int) $invoice->sale_id), 'attr') ?>"><bdi dir="ltr">POS <?= (int) $invoice->sale_id ?></bdi></a>
            <span class="badge text-bg-<?= (int) $invoice->sale_status === COMPLETED ? 'success' : 'secondary' ?>"><?= lang((int) $invoice->sale_status === COMPLETED ? 'Sales_orders.invoice_completed' : 'Sales_orders.invoice_waiting') ?></span>
        </li>
    <?php endforeach; ?>
</ul>

<script type="text/javascript">
    $(document).ready(function() {
        const post = function(url, data) {
            $.post(url, data, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else {
                        window.location.reload();
                    }
                }
            }, 'json').fail(function() {
                toast_support.show("<?= esc(lang('Common.request_failed'), 'js') ?>", 'danger');
            });
        };

        $('.order-status').on('click', function() {
            post('sales_orders/status/<?= $orderId ?>', {status: $(this).data('status')});
        });

        $('#order_convert').on('click', function() {
            post('sales_orders/convert/<?= $orderId ?>', {});
        });

        $('#order_cancel').on('click', function() {
            const reason = window.prompt("<?= esc(lang('Sales_orders.cancel_prompt'), 'js') ?>");

            if (reason !== null) {
                post('sales_orders/cancel/<?= $orderId ?>', {reason: reason});
            }
        });
    });
</script>

<?= view('partial/footer') ?>
