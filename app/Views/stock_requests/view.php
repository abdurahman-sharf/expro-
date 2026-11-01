<?php
/**
 * @var object       $stock_request
 * @var list<object> $lines
 * @var string       $number
 * @var bool         $enabled
 * @var bool         $can_manage
 * @var bool         $can_approve
 * @var string       $active
 * @var bool         $requestsOn
 */
$status = $stock_request->status;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const send = function(path) {
            $.ajax({
                type: 'POST',
                url: 'stock_documents/requests/' + path + '/<?= (int) $stock_request->request_id ?>',
                data: {},
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.reload();
                    }
                }
            });
        };

        $(document).on('click', '.request-dialog', function() {
            dialog_support.init(this);
        });

        $('#request_approve').on('click', function() {
            send('approve');
        });

        $('#request_fulfil').on('click', function() {
            if (window.confirm("<?= esc(lang('Stock_documents.fulfil_confirm'), 'js') ?>")) {
                send('fulfil');
            }
        });
    });
</script>

<?= view('stock_documents/tabs', ['active' => $active, 'requestsOn' => $requestsOn]) ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Stock_documents.request') ?> <bdi dir="ltr"><?= esc($number) ?></bdi></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/requests'), 'attr') ?>"><?= lang('Stock_documents.back') ?></a>
    <?php if ($enabled && $can_approve && $status === 'requested'): ?>
        <button type="button" class="btn btn-primary btn-sm" id="request_approve"><?= lang('Stock_documents.approve') ?></button>
    <?php endif; ?>
    <?php if ($enabled && $can_approve && $status === 'approved'): ?>
        <button type="button" class="btn btn-success btn-sm" id="request_fulfil"><?= lang('Stock_documents.fulfil') ?></button>
    <?php endif; ?>
    <?php if (in_array($status, ['requested', 'approved'], true) && ($can_manage || $can_approve)): ?>
        <button class="btn btn-outline-danger btn-sm modal-dlg request-dialog" id="request_cancel" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/requests/cancel_form/' . (int) $stock_request->request_id), 'attr') ?>"><?= lang('Stock_documents.cancel') ?></button>
    <?php endif; ?>
</div>

<dl class="row" id="request_details">
    <dt class="col-sm-3"><?= lang('Stock_documents.status') ?></dt>
    <dd class="col-sm-9"><span class="badge text-bg-primary"><?= lang('Stock_documents.status_' . $status) ?></span><?= $status === 'cancelled' ? ' ' . esc((string) $stock_request->cancel_reason) : '' ?></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.date') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $stock_request->request_date) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.requested_from') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $stock_request->from_location_name) ?> <bdi dir="ltr">(<?= esc((string) $stock_request->from_branch_code) ?>)</bdi></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.requested_for') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $stock_request->to_location_name) ?> <bdi dir="ltr">(<?= esc((string) $stock_request->branch_code) ?>)</bdi></dd>
    <?php if ((string) $stock_request->note !== ''): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.note') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $stock_request->note) ?></dd>
    <?php endif; ?>
    <?php if ($stock_request->permit_id !== null): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.transfer_permit') ?></dt>
        <dd class="col-sm-9"><a id="request_permit_link" href="<?= esc(base_url('stock_documents/permits/view/' . (int) $stock_request->permit_id), 'attr') ?>">#<?= (int) $stock_request->permit_id ?></a></dd>
    <?php endif; ?>
</dl>

<div class="table-responsive">
    <table class="table table-sm" id="request_lines_table">
        <thead>
            <tr>
                <th scope="col">#</th>
                <th scope="col"><?= lang('Stock_documents.item') ?></th>
                <th scope="col" class="text-end"><?= lang('Stock_documents.quantity') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $line): ?>
                <tr>
                    <td><?= (int) $line->line_no ?></td>
                    <td><?= esc((string) $line->item_name) ?></td>
                    <td class="text-end"><bdi dir="ltr"><?= esc(rtrim(rtrim(bcadd((string) $line->quantity, '0', 3), '0'), '.')) ?></bdi></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= view('partial/footer') ?>
