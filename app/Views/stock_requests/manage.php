<?php
/**
 * @var list<object>                                                      $rows
 * @var int                                                               $total
 * @var int                                                               $offset
 * @var int                                                               $page_size
 * @var array{status: string, search: string, from: string, to: string}   $filters
 * @var bool                                                              $enabled
 * @var bool                                                              $can_manage
 * @var bool                                                              $scopeNotice
 * @var string                                                            $active
 * @var bool                                                              $requestsOn
 */
$query = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $(document).on('click', '.request-dialog', function() {
            dialog_support.init(this);
        });
    });
</script>

<?= view('stock_documents/tabs', ['active' => $active, 'requestsOn' => $requestsOn]) ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Stock_documents.requests_title') ?></h1>
    <?php if ($can_manage && $enabled): ?>
        <button class="btn btn-primary btn-sm modal-dlg request-dialog" id="request_new" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/requests/form'), 'attr') ?>" title="<?= lang('Stock_documents.new_request') ?>">
            <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.new_request') ?>
        </button>
    <?php endif; ?>
</div>

<?php if (! $enabled): ?>
    <div class="alert alert-info" id="requests_off"><?= lang('Stock_documents.requests_off') ?></div>
<?php endif; ?>

<?php if ($scopeNotice): ?>
    <div class="alert alert-secondary" id="requests_scope"><?= lang('Stock_documents.scope_notice') ?></div>
<?php endif; ?>

<form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="request_filters">
    <div>
        <label class="form-label" for="f_search"><?= lang('Stock_documents.search') ?></label>
        <input type="text" class="form-control form-control-sm" id="f_search" name="search" value="<?= esc($filters['search'], 'attr') ?>">
    </div>
    <div>
        <label class="form-label" for="f_status"><?= lang('Stock_documents.status') ?></label>
        <select class="form-select form-select-sm" id="f_status" name="status">
            <option value=""><?= lang('Stock_documents.all') ?></option>
            <?php foreach (['requested', 'approved', 'fulfilled', 'cancelled'] as $status): ?>
                <option value="<?= $status ?>"<?= $filters['status'] === $status ? ' selected' : '' ?>><?= lang('Stock_documents.status_' . $status) ?></option>
            <?php endforeach; ?>
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
    <table class="table table-sm align-middle" id="requests_table">
        <thead>
            <tr>
                <th scope="col"><?= lang('Stock_documents.number') ?></th>
                <th scope="col"><?= lang('Stock_documents.date') ?></th>
                <th scope="col"><?= lang('Stock_documents.requested_from') ?></th>
                <th scope="col"><?= lang('Stock_documents.requested_for') ?></th>
                <th scope="col"><?= lang('Stock_documents.status') ?></th>
                <th scope="col"><?= lang('Stock_documents.note') ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-body-secondary" id="requests_empty"><?= lang('Stock_documents.requests_empty') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr id="request_<?= (int) $row->request_id ?>">
                    <td><bdi dir="ltr"><?= esc($row->document_number ?? '#' . $row->request_id) ?></bdi></td>
                    <td><bdi dir="ltr"><?= esc((string) $row->request_date) ?></bdi></td>
                    <td><?= esc((string) $row->from_location_name) ?> <bdi dir="ltr">(<?= esc((string) $row->from_branch_code) ?>)</bdi></td>
                    <td><?= esc((string) $row->to_location_name) ?> <bdi dir="ltr">(<?= esc((string) $row->branch_code) ?>)</bdi></td>
                    <td><span class="badge <?= $row->status === 'fulfilled' ? 'text-bg-success' : ($row->status === 'cancelled' ? 'text-bg-secondary' : ($row->status === 'approved' ? 'text-bg-primary' : 'text-bg-warning')) ?>"><?= lang('Stock_documents.status_' . $row->status) ?></span></td>
                    <td><?= esc((string) $row->note) ?></td>
                    <td><a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/requests/view/' . (int) $row->request_id), 'attr') ?>"><?= lang('Stock_documents.view') ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<nav class="d-flex gap-2 align-items-center" aria-label="<?= lang('Stock_documents.requests_title') ?>">
    <span class="text-body-secondary small"><?= lang('Stock_documents.count', [$total]) ?></span>
    <?php if ($offset > 0): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/requests?' . $query(['offset' => max(0, $offset - $page_size)])), 'attr') ?>"><?= lang('Stock_documents.previous') ?></a>
    <?php endif; ?>
    <?php if ($offset + $page_size < $total): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/requests?' . $query(['offset' => $offset + $page_size])), 'attr') ?>"><?= lang('Stock_documents.next') ?></a>
    <?php endif; ?>
</nav>

<?= view('partial/footer') ?>
