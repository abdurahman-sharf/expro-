<?php
/**
 * @var object       $stockRequest
 * @var list<object> $lines
 * @var string       $number
 * @var string|null  $permitNumber
 * @var bool         $enabled
 * @var bool         $canManage
 * @var bool         $canApprove
 * @var string       $active
 * @var bool         $requestsOn
 */
$status = $stockRequest->status;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const send = function(path) {
            $.ajax({
                type: 'POST',
                url: 'stock_documents/requests/' + path + '/<?= (int) $stockRequest->request_id ?>',
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
    <?php if ($enabled && $canApprove && $status === 'requested'): ?>
        <button type="button" class="btn btn-primary btn-sm" id="request_approve"><?= lang('Stock_documents.approve') ?></button>
    <?php endif; ?>
    <?php if ($enabled && $canApprove && $status === 'approved'): ?>
        <button type="button" class="btn btn-success btn-sm" id="request_fulfil"><?= lang('Stock_documents.fulfil') ?></button>
    <?php endif; ?>
    <?php if (in_array($status, ['requested', 'approved'], true) && ($canManage || $canApprove)): ?>
        <button class="btn btn-outline-danger btn-sm modal-dlg request-dialog" id="request_cancel" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/requests/cancel_form/' . (int) $stockRequest->request_id), 'attr') ?>"><?= lang('Stock_documents.cancel') ?></button>
    <?php endif; ?>
</div>

<dl class="row" id="request_details">
    <dt class="col-sm-3"><?= lang('Stock_documents.status') ?></dt>
    <dd class="col-sm-9"><span class="badge text-bg-primary"><?= lang('Stock_documents.status_' . $status) ?></span><?= $status === 'cancelled' ? ' ' . esc((string) $stockRequest->cancel_reason) : '' ?></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.date') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $stockRequest->request_date) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.requested_from') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $stockRequest->from_location_name) ?> <bdi dir="ltr">(<?= esc((string) $stockRequest->from_branch_code) ?>)</bdi></dd>
    <dt class="col-sm-3"><?= lang('Stock_documents.requested_for') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $stockRequest->to_location_name) ?> <bdi dir="ltr">(<?= esc((string) $stockRequest->branch_code) ?>)</bdi></dd>
    <?php if ((string) $stockRequest->note !== ''): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.note') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $stockRequest->note) ?></dd>
    <?php endif; ?>
    <?php if ($stockRequest->permit_id !== null): ?>
        <dt class="col-sm-3"><?= lang('Stock_documents.transfer_permit') ?></dt>
        <dd class="col-sm-9"><a id="request_permit_link" href="<?= esc(base_url('stock_documents/permits/view/' . (int) $stockRequest->permit_id), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $permitNumber) ?></bdi></a></dd>
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
