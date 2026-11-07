<?php

use App\Libraries\SalesOrders\SalesOrderStatus;

/**
 * @var list<object>                                                    $rows
 * @var int                                                             $total
 * @var int                                                             $offset
 * @var int                                                             $page_size
 * @var array{status: string, search: string, from: string, to: string} $filters
 * @var bool                                                            $enabled
 * @var bool                                                            $allow_excess
 * @var bool                                                            $can_add
 * @var bool                                                            $can_settings
 * @var list<SalesOrderStatus>                                          $statuses
 */
$query = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Sales_orders.title') ?></h1>
    <?php if ($can_add): ?>
        <a class="btn btn-primary btn-sm" id="sales_order_new" href="<?= esc(base_url('sales_orders/add'), 'attr') ?>">
            <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Sales_orders.new') ?>
        </a>
    <?php endif; ?>
</div>

<?php if (! $enabled): ?>
    <div class="alert alert-info" id="sales_orders_off"><?= lang('Sales_orders.off') ?></div>
<?php endif; ?>

<?php if ($can_settings): ?>
    <?= form_open('sales_orders/settings', ['id' => 'sales_order_settings', 'class' => 'app-form card card-body mb-3']) ?>
        <h2 class="h6"><?= lang('Sales_orders.settings') ?></h2>
        <div class="form-check form-switch">
            <input type="hidden" name="enabled" value="0">
            <input type="checkbox" class="form-check-input" role="switch" id="so_enabled" name="enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
            <label class="form-check-label" for="so_enabled"><?= lang('Sales_orders.setting_enabled') ?></label>
        </div>
        <div class="form-check form-switch">
            <input type="hidden" name="allow_excess" value="0">
            <input type="checkbox" class="form-check-input" role="switch" id="so_allow_excess" name="allow_excess" value="1"<?= $allow_excess ? ' checked' : '' ?>>
            <label class="form-check-label" for="so_allow_excess"><?= lang('Sales_orders.setting_allow_excess') ?></label>
        </div>
        <p class="small text-body-secondary mb-2"><?= lang('Sales_orders.setting_note') ?></p>
        <div><button type="submit" class="btn btn-outline-secondary btn-sm"><?= lang('Common.submit') ?></button></div>
    <?= form_close() ?>
<?php endif; ?>

<?php if ($enabled): ?>
    <form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="sales_order_filters">
        <div>
            <label class="form-label" for="f_search"><?= lang('Sales_orders.search') ?></label>
            <input type="text" class="form-control form-control-sm" id="f_search" name="search" value="<?= esc($filters['search'], 'attr') ?>">
        </div>
        <div>
            <label class="form-label" for="f_status"><?= lang('Sales_orders.status') ?></label>
            <select class="form-select form-select-sm" id="f_status" name="status">
                <option value=""><?= lang('Sales_orders.status_all') ?></option>
                <?php foreach ($statuses as $status): ?>
                    <option value="<?= esc($status->value, 'attr') ?>"<?= $filters['status'] === $status->value ? ' selected' : '' ?>><?= lang($status->languageKey()) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="f_from"><?= lang('Sales_orders.date_from') ?></label>
            <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_from" name="from" value="<?= esc($filters['from'], 'attr') ?>">
        </div>
        <div>
            <label class="form-label" for="f_to"><?= lang('Sales_orders.date_to') ?></label>
            <input type="date" class="form-control form-control-sm ltr-input" dir="ltr" id="f_to" name="to" value="<?= esc($filters['to'], 'attr') ?>">
        </div>
        <button type="submit" class="btn btn-outline-secondary btn-sm"><?= lang('Sales_orders.filter') ?></button>
    </form>

    <div class="table-responsive">
        <table class="table table-sm align-middle" id="sales_orders_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Sales_orders.number') ?></th>
                    <th scope="col"><?= lang('Sales_orders.date') ?></th>
                    <th scope="col"><?= lang('Sales_orders.customer') ?></th>
                    <th scope="col"><?= lang('Sales_orders.branch') ?></th>
                    <th scope="col" class="text-end"><?= lang('Sales_orders.gross') ?></th>
                    <th scope="col"><?= lang('Sales_orders.status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="6" class="text-body-secondary" id="sales_orders_empty"><?= lang('Sales_orders.empty') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr id="sales_order_<?= (int) $row->order_id ?>">
                        <td><a href="<?= esc(base_url('sales_orders/view/' . (int) $row->order_id), 'attr') ?>"><bdi dir="ltr"><?= esc((string) ($row->document_number ?? str_pad((string) $row->order_id, 5, '0', STR_PAD_LEFT))) ?></bdi></a></td>
                        <td><bdi dir="ltr"><?= esc((string) $row->order_date) ?></bdi></td>
                        <td><?= esc((string) $row->customer_name) ?></td>
                        <td><bdi dir="ltr"><?= esc((string) $row->branch_code) ?></bdi></td>
                        <td class="text-end"><bdi dir="ltr"><?= to_currency($row->gross) ?></bdi></td>
                        <td><span class="badge text-bg-<?= $row->derived_status === 'invoiced' ? 'success' : ($row->derived_status === 'cancelled' ? 'secondary' : ($row->derived_status === 'open' ? 'warning' : 'primary')) ?>"><?= lang('Sales_orders.status_' . $row->derived_status) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <nav class="d-flex gap-2 align-items-center" aria-label="<?= lang('Sales_orders.title') ?>">
        <span class="text-body-secondary small"><?= lang('Sales_orders.count', [$total]) ?></span>
        <?php if ($offset > 0): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('sales_orders?' . $query(['offset' => max(0, $offset - $page_size)])), 'attr') ?>"><?= lang('Sales_orders.previous') ?></a>
        <?php endif; ?>
        <?php if ($offset + $page_size < $total): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('sales_orders?' . $query(['offset' => $offset + $page_size])), 'attr') ?>"><?= lang('Sales_orders.next') ?></a>
        <?php endif; ?>
    </nav>
<?php endif; ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#sales_order_settings').on('submit', function(event) {
            event.preventDefault();
            $(this).ajaxSubmit({
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

<?= view('partial/footer') ?>
