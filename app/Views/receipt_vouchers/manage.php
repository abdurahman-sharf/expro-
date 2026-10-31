<?php
/**
 * @var string       $table_headers
 * @var list<object> $categories
 * @var array        $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'receipt_vouchers',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'revenue_id',
            cardTitle: ['description'],
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Receipt_vouchers.empty')) . '</p>', $jsonFlags) ?>;
            },
            queryParams: function(params) {
                return $.extend(params, {
                    start_date: $('#f_date_from').val(),
                    end_date: $('#f_date_to').val(),
                    category_id: $('#f_category').val(),
                    deleted: $('#f_deleted').is(':checked') ? '1' : ''
                });
            }
        });

        $('#revenue_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('receipt_vouchers/view'), 'attr') ?>" title="<?= esc(lang('Receipt_vouchers.new'), 'attr') ?>">
        <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Receipt_vouchers.new') ?>
    </button>
</div>

<form id="revenue_filters" class="acc-filters print_hide" role="search" aria-label="<?= esc(lang('Receipt_vouchers.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_date_from"><?= lang('Receipt_vouchers.date_from') ?></label>
            <input type="date" id="f_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_to"><?= lang('Receipt_vouchers.date_to') ?></label>
            <input type="date" id="f_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_category"><?= lang('Receipt_vouchers.category') ?></label>
            <select id="f_category" class="form-select form-select-sm">
                <option value=""><?= lang('Receipt_vouchers.all_categories') ?></option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category->revenue_category_id ?>"><?= esc($category->category_name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="choice"><input type="checkbox" class="form-check-input" id="f_deleted" value="1"> <?= lang('Receipt_vouchers.deleted') ?></label>
        </div>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
        </div>
    </div>
</form>

<div id="toolbar">
    <div class="float-start d-flex flex-wrap align-items-center gap-2" role="toolbar">
        <button id="delete" class="btn btn-outline-secondary btn-sm print_hide">
            <span class="bi bi-trash" aria-hidden="true">&nbsp;</span><?= lang('Common.delete') ?>
        </button>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
