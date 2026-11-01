<?php
/**
 * @var list<object>                                                                $rows
 * @var int                                                                         $total
 * @var int                                                                         $offset
 * @var int                                                                         $page_size
 * @var array{status: string, type: string, search: string, from: string, to: string} $filters
 * @var bool                                                                        $can_manage
 * @var bool                                                                        $can_settings
 * @var array{transfer_permit: bool, requests: bool}                                $settings
 * @var bool                                                                        $scopeNotice
 * @var string                                                                      $active
 * @var bool                                                                        $requestsOn
 */
$query = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $(document).on('click', '.permit-dialog', function() {
            dialog_support.init(this);
        });

        $('#stock_settings_form').on('submit', function(event) {
            event.preventDefault();
            $.ajax({
                type: 'POST',
                url: $(this).attr('action'),
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.reload();
                    }
                }
            });
        });
    });
</script>

<?= view('stock_documents/tabs', ['active' => $active, 'requestsOn' => $requestsOn]) ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Stock_documents.permits_title') ?></h1>
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm modal-dlg permit-dialog" id="permit_new_add" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/permits/form/add'), 'attr') ?>" title="<?= lang('Stock_documents.new_add') ?>">
            <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.new_add') ?>
        </button>
        <button class="btn btn-outline-primary btn-sm modal-dlg permit-dialog" id="permit_new_issue" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/permits/form/issue'), 'attr') ?>" title="<?= lang('Stock_documents.new_issue') ?>">
            <span class="bi bi-dash-circle" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.new_issue') ?>
        </button>
        <?php if ($settings['transfer_permit']): ?>
            <button class="btn btn-outline-primary btn-sm modal-dlg permit-dialog" id="permit_new_transfer" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/permits/form/transfer'), 'attr') ?>" title="<?= lang('Stock_documents.new_transfer') ?>">
                <span class="bi bi-arrow-left-right" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.new_transfer') ?>
            </button>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($scopeNotice): ?>
    <div class="alert alert-secondary" id="permits_scope"><?= lang('Stock_documents.scope_notice') ?></div>
<?php endif; ?>

<form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="permit_filters">
    <div>
        <label class="form-label" for="f_search"><?= lang('Stock_documents.search') ?></label>
        <input type="text" class="form-control form-control-sm" id="f_search" name="search" value="<?= esc($filters['search'], 'attr') ?>">
    </div>
    <div>
        <label class="form-label" for="f_type"><?= lang('Stock_documents.type') ?></label>
        <select class="form-select form-select-sm" id="f_type" name="type">
            <option value=""><?= lang('Stock_documents.all') ?></option>
            <?php foreach (['add', 'issue', 'transfer'] as $type): ?>
                <option value="<?= $type ?>"<?= $filters['type'] === $type ? ' selected' : '' ?>><?= lang('Stock_documents.type_' . $type) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="f_status"><?= lang('Stock_documents.status') ?></label>
        <select class="form-select form-select-sm" id="f_status" name="status">
            <option value=""><?= lang('Stock_documents.all') ?></option>
            <option value="posted"<?= $filters['status'] === 'posted' ? ' selected' : '' ?>><?= lang('Stock_documents.status_posted') ?></option>
            <option value="cancelled"<?= $filters['status'] === 'cancelled' ? ' selected' : '' ?>><?= lang('Stock_documents.status_cancelled') ?></option>
        </select>
    </div>
    <div>
        <label class="form-label" for="f_from"><?= lang('Stock_documents.date_from') ?></label>
        <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_from" name="from" value="<?= esc($filters['from'], 'attr') ?>">
    </div>
    <div>
        <label class="form-label" for="f_to"><?= lang('Stock_documents.date_to') ?></label>
        <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_to" name="to" value="<?= esc($filters['to'], 'attr') ?>">
    </div>
    <button type="submit" class="btn btn-outline-secondary btn-sm"><?= lang('Stock_documents.filter') ?></button>
</form>

<div class="table-responsive">
    <table class="table table-sm align-middle" id="permits_table">
        <thead>
            <tr>
                <th scope="col"><?= lang('Stock_documents.number') ?></th>
                <th scope="col"><?= lang('Stock_documents.type') ?></th>
                <th scope="col"><?= lang('Stock_documents.date') ?></th>
                <th scope="col"><?= lang('Stock_documents.location') ?></th>
                <th scope="col"><?= lang('Stock_documents.branch') ?></th>
                <th scope="col"><?= lang('Stock_documents.status') ?></th>
                <th scope="col"><?= lang('Stock_documents.note') ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-body-secondary" id="permits_empty"><?= lang('Stock_documents.permits_empty') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr id="permit_<?= (int) $row->permit_id ?>">
                    <td><bdi dir="ltr"><?= esc($row->document_number ?? '#' . $row->permit_id) ?></bdi></td>
                    <td><?= lang('Stock_documents.type_' . $row->permit_type) ?></td>
                    <td><bdi dir="ltr"><?= esc((string) $row->permit_date) ?></bdi></td>
                    <td><?= esc((string) $row->location_name) ?><?= $row->to_location_name !== null ? ' &rarr; ' . esc((string) $row->to_location_name) : '' ?></td>
                    <td><bdi dir="ltr"><?= esc((string) $row->branch_code) ?><?= $row->to_branch_code !== null ? ' &rarr; ' . esc((string) $row->to_branch_code) : '' ?></bdi></td>
                    <td><?= $row->status === 'posted' ? '<span class="badge text-bg-success">' . lang('Stock_documents.status_posted') . '</span>' : '<span class="badge text-bg-secondary" title="' . esc((string) $row->cancel_reason, 'attr') . '">' . lang('Stock_documents.status_cancelled') . '</span>' ?></td>
                    <td><?= esc((string) $row->note) ?></td>
                    <td class="text-nowrap">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/permits/view/' . (int) $row->permit_id), 'attr') ?>"><?= lang('Stock_documents.view') ?></a>
                        <?php if ($can_manage && $row->status === 'posted'): ?>
                            <button class="btn btn-outline-primary btn-sm modal-dlg permit-dialog" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/permits/edit/' . (int) $row->permit_id), 'attr') ?>" title="<?= lang('Stock_documents.edit') ?>"><?= lang('Stock_documents.edit') ?></button>
                            <button class="btn btn-outline-danger btn-sm modal-dlg permit-dialog" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/permits/cancel_form/' . (int) $row->permit_id), 'attr') ?>" title="<?= lang('Stock_documents.cancel') ?>"><?= lang('Stock_documents.cancel') ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<nav class="d-flex gap-2 align-items-center" aria-label="<?= lang('Stock_documents.permits_title') ?>">
    <span class="text-body-secondary small"><?= lang('Stock_documents.count', [$total]) ?></span>
    <?php if ($offset > 0): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/permits?' . $query(['offset' => max(0, $offset - $page_size)])), 'attr') ?>"><?= lang('Stock_documents.previous') ?></a>
    <?php endif; ?>
    <?php if ($offset + $page_size < $total): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/permits?' . $query(['offset' => $offset + $page_size])), 'attr') ?>"><?= lang('Stock_documents.next') ?></a>
    <?php endif; ?>
</nav>

<?php if ($can_settings): ?>
    <section class="mt-4" aria-labelledby="stock_settings_title">
        <h2 id="stock_settings_title" class="h6"><?= lang('Stock_documents.settings_title') ?></h2>
        <p class="text-body-secondary small"><?= lang('Stock_documents.settings_note') ?></p>
        <?= form_open('stock_documents/permits/settings', ['id' => 'stock_settings_form', 'class' => 'app-form']) ?>
            <div class="form-check form-switch">
                <input type="hidden" name="transfer_permit" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="setting_transfer_permit" name="transfer_permit" value="1"<?= $settings['transfer_permit'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="setting_transfer_permit"><?= lang('Stock_documents.setting_transfer_permit') ?></label>
            </div>
            <div class="form-check form-switch mb-2">
                <input type="hidden" name="requests" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="setting_requests" name="requests" value="1"<?= $settings['requests'] ? ' checked' : '' ?>>
                <label class="form-check-label" for="setting_requests"><?= lang('Stock_documents.setting_requests') ?></label>
            </div>
            <button type="submit" class="btn btn-outline-primary btn-sm" id="stock_settings_save"><?= lang('Common.submit') ?></button>
        <?= form_close() ?>
    </section>
<?php endif; ?>

<?= view('partial/footer') ?>
