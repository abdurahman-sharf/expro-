<?php
/**
 * @var list<object>                                                                        $rows
 * @var int                                                                                 $total
 * @var int                                                                                 $offset
 * @var int                                                                                 $page_size
 * @var array{status: string, location_id: int, search: string, from: string, to: string}   $filters
 * @var array<int, string>                                                                  $locations
 * @var bool                                                                                $can_manage
 * @var bool                                                                                $scopeNotice
 * @var string                                                                              $active
 * @var bool                                                                                $requestsOn
 */
$query = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $(document).on('click', '.stocktake-dialog', function() {
            dialog_support.init(this);
        });
    });
</script>

<?= view('stock_documents/tabs', ['active' => $active, 'requestsOn' => $requestsOn]) ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Stock_documents.stocktakes_title') ?></h1>
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm modal-dlg stocktake-dialog" id="stocktake_new" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_documents/stocktakes/form'), 'attr') ?>" title="<?= lang('Stock_documents.new_stocktake') ?>">
            <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Stock_documents.new_stocktake') ?>
        </button>
    <?php endif; ?>
</div>

<?php if ($scopeNotice): ?>
    <div class="alert alert-secondary" id="stocktakes_scope"><?= lang('Stock_documents.scope_notice') ?></div>
<?php endif; ?>

<form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="stocktake_filters">
    <div>
        <label class="form-label" for="f_search"><?= lang('Stock_documents.search') ?></label>
        <input type="text" class="form-control form-control-sm" id="f_search" name="search" value="<?= esc($filters['search'], 'attr') ?>">
    </div>
    <div>
        <label class="form-label" for="f_location"><?= lang('Stock_documents.location') ?></label>
        <select class="form-select form-select-sm" id="f_location" name="location_id">
            <option value=""><?= lang('Stock_documents.all') ?></option>
            <?php foreach ($locations as $locationId => $name): ?>
                <option value="<?= (int) $locationId ?>"<?= $filters['location_id'] === (int) $locationId ? ' selected' : '' ?>><?= esc($name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="form-label" for="f_status"><?= lang('Stock_documents.status') ?></label>
        <select class="form-select form-select-sm" id="f_status" name="status">
            <option value=""><?= lang('Stock_documents.all') ?></option>
            <?php foreach (['counting', 'approved', 'cancelled'] as $status): ?>
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
    <table class="table table-sm align-middle" id="stocktakes_table">
        <thead>
            <tr>
                <th scope="col"><?= lang('Stock_documents.number') ?></th>
                <th scope="col"><?= lang('Stock_documents.count_date') ?></th>
                <th scope="col"><?= lang('Stock_documents.location') ?></th>
                <th scope="col"><?= lang('Stock_documents.branch') ?></th>
                <th scope="col"><?= lang('Stock_documents.status') ?></th>
                <th scope="col"><?= lang('Stock_documents.note') ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-body-secondary" id="stocktakes_empty"><?= lang('Stock_documents.stocktakes_empty') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr id="stocktake_<?= (int) $row->stocktake_id ?>">
                    <td><bdi dir="ltr"><?= esc($row->document_number ?? '#' . $row->stocktake_id) ?></bdi></td>
                    <td><bdi dir="ltr"><?= esc((string) $row->count_date) ?></bdi></td>
                    <td><?= esc((string) $row->location_name) ?></td>
                    <td><bdi dir="ltr"><?= esc((string) $row->branch_code) ?></bdi></td>
                    <td><span class="badge <?= $row->status === 'approved' ? 'text-bg-success' : ($row->status === 'counting' ? 'text-bg-warning' : 'text-bg-secondary') ?>"<?= $row->status === 'cancelled' ? ' title="' . esc((string) $row->cancel_reason, 'attr') . '"' : '' ?>><?= lang('Stock_documents.status_' . $row->status) ?></span></td>
                    <td><?= esc((string) $row->note) ?></td>
                    <td class="text-nowrap">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/stocktakes/view/' . (int) $row->stocktake_id), 'attr') ?>"><?= lang('Stock_documents.view') ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<nav class="d-flex gap-2 align-items-center" aria-label="<?= lang('Stock_documents.stocktakes_title') ?>">
    <span class="text-body-secondary small"><?= lang('Stock_documents.count', [$total]) ?></span>
    <?php if ($offset > 0): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/stocktakes?' . $query(['offset' => max(0, $offset - $page_size)])), 'attr') ?>"><?= lang('Stock_documents.previous') ?></a>
    <?php endif; ?>
    <?php if ($offset + $page_size < $total): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('stock_documents/stocktakes?' . $query(['offset' => $offset + $page_size])), 'attr') ?>"><?= lang('Stock_documents.next') ?></a>
    <?php endif; ?>
</nav>

<?= view('partial/footer') ?>
