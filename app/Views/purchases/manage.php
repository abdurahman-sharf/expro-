<?php

use App\Libraries\Purchases\PurchaseInvoiceFilters;

/**
 * @var string                 $table_headers
 * @var list<object>           $branches
 * @var list<int>              $selected
 * @var string|null            $scope_label
 * @var PurchaseInvoiceFilters $filters
 * @var string                 $supplier_label
 * @var array<string, string>  $statuses
 * @var array<string, string>  $payments
 * @var list<int>              $page_sizes
 * @var int                    $page_size
 * @var bool                   $can_receive
 * @var int                    $employee_id
 * @var array<string, mixed>   $csv_query
 * @var array                  $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        <?= view('partial/daterangepicker') ?>

        const picker = $('#daterangepicker').data('daterangepicker');
        const initialFrom = <?= json_encode($filters->dateFrom, $jsonFlags) ?>;
        const initialTo = <?= json_encode($filters->dateTo, $jsonFlags) ?>;

        const showRange = function(from, to) {
            start_date = from;
            end_date = to;
            picker.setStartDate(moment(from, 'YYYY-MM-DD'));
            picker.setEndDate(moment(to, 'YYYY-MM-DD'));
            $('#daterangepicker').val(picker.startDate.format(picker.locale.format) + picker.locale.separator + picker.endDate.format(picker.locale.format));
        };

        const thisMonth = function() {
            const today = moment();
            showRange(today.clone().startOf('month').format('YYYY-MM-DD'), today.format('YYYY-MM-DD'));
        };

        initialFrom && initialTo ? showRange(initialFrom, initialTo) : thisMonth();

        const filterValues = function() {
            return {
                date_from: String(start_date).slice(0, 10),
                date_to: String(end_date).slice(0, 10),
                supplier_id: $('#f_supplier').val(),
                status: $('#f_status').val(),
                payment: $('#f_payment').val(),
                branches: $('.f-branch:checked').map(function() {
                    return this.value;
                }).get()
            };
        };

        const fileQuery = function() {
            const sort = $('#table').bootstrapTable('getOptions');
            return $.param($.extend(filterValues(), {search: $('.search-input').val() || '', sort: sort.sortName || '', order: sort.sortOrder || ''}), true);
        };

        const showActive = function() {
            const values = filterValues();
            const count = [$('#daterangepicker').val() !== '' && (values.date_from || values.date_to) ? 1 : 0, values.supplier_id ? 1 : 0, values.status ? 1 : 0, values.payment ? 1 : 0].reduce(function(sum, item) {
                return sum + item;
            }, 0);
            $('#filters_count').text(<?= json_encode(lang('Purchases.filters_active'), $jsonFlags) ?>.replace('{0}', count)).toggleClass('d-none', count === 0);
        };

        const syncAddress = function() {
            try {
                history.replaceState(null, '', location.pathname + '?' + $.param(filterValues(), true));
            } catch (error) {
                // the address bar is only a convenience
            }
        };

        const links = function() {
            $.each({csv: 'purchases/csv', xlsx: 'purchases/xlsx', pdf: 'purchases/pdf'}, function(format, url) {
                $('#export_' + format).attr('href', url + '?' + fileQuery());
            });
        };

        let sumsFor = '';

        const loadSums = function() {
            const query = $.param(filterValues(), true);

            if (query === sumsFor) {
                return;
            }

            sumsFor = query;
            $('#purchase_sums').attr('aria-busy', 'true');
            $.get('purchases/sums', filterValues(), function(response) {
                $('#purchase_sums').attr('aria-busy', 'false').html(response.success ? response.html : '<p class="text-body-secondary mb-0">' + $('<div>').text(response.message).html() + '</p>');
            }, 'json').fail(function() {
                $('#purchase_sums').attr('aria-busy', 'false').empty();
            });
        };

        table_support.submit_handler(<?= json_encode(site_url('purchases/row'), $jsonFlags) ?>);

        table_support.init({
            resource: 'purchases',
            headers: <?= $table_headers ?>,
            pageSize: <?= (int) $page_size ?>,
            uniqueId: 'receiving_id',
            cardTitle: ['number'],
            sortName: 'date',
            sortOrder: 'desc',
            employee_id: <?= (int) $employee_id ?>,
            rowActionsFirst: true,
            rowActionsTitle: <?= json_encode(lang('Purchases.col_options'), $jsonFlags) ?>,
            showExport: false,
            buttons: {},
            paginationParts: ['pageInfo', 'pageList'],
            pageList: <?= json_encode($page_sizes) ?>,
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Purchases.empty')) . '</p>', $jsonFlags) ?>;
            },
            queryParams: function(params) {
                return $.extend(params, filterValues());
            },
            onLoadSuccess: function() {
                links();
                loadSums();
            },
            onLoadError: function(status, response) {
                const message = response && response.responseJSON && response.responseJSON.message;

                message && $.notify(message, {type: 'danger'});
            }
        });

        const refresh = function() {
            showActive();
            syncAddress();
            table_support.refresh();
        };

        $('#purchase_filters').on('submit', function(event) {
            event.preventDefault();
            refresh();
        });

        $('#daterangepicker').on('apply.daterangepicker', refresh);

        $('#f_status, #f_payment').on('change', refresh);

        $('#filters_reset').on('click', function() {
            $('#f_supplier').val('');
            $('#f_supplier_name').val('');
            $('#f_status, #f_payment').val('');
            $('.f-branch').prop('checked', true);
            thisMonth();
            refresh();
        });

        $('#f_supplier_name').autocomplete({
            minLength: 1,
            source: function(request, response) {
                $.get('purchases/suppliers', {term: request.term}, function(found) {
                    response($.map(found, function(supplier) {
                        return {label: supplier.name, value: supplier.name, id: supplier.id};
                    }));
                }, 'json');
            },
            select: function(event, ui) {
                $('#f_supplier').val(ui.item.id);
                refresh();
            }
        }).on('input', function() {
            if ($(this).val() === '' && $('#f_supplier').val() !== '') {
                $('#f_supplier').val('');
                refresh();
            }
        });

        $('#page_size').on('change', function() {
            $('#table').bootstrapTable('refreshOptions', {pageSize: parseInt(this.value, 10)});
        });

        $('#print_list').on('click', function() {
            window.print();
        });

        $('#table').on('click', '[data-purchase-action="delete"]', function() {
            table_support.do_delete('receivings', String($(this).data('id')));
        });

        $('#table').on('click', '[data-purchase-action="debit_note"]', function() {
            $.post('receivings/debitNote/' + $(this).data('id'), function(response) {
                response.success ? (window.location = response.redirect) : $.notify(response.message, {type: 'danger'});
            }, 'json');
        });

        showActive();
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" id="legacy_report" href="<?= esc(site_url('reports/detailed_receivings'), 'attr') ?>"><span class="bi bi-file-earmark-text" aria-hidden="true">&nbsp;</span><?= lang('Purchases.legacy_report') ?></a>
</div>

<?php if ($scope_label !== null): ?>
    <div class="alert alert-info mx-2" role="note" id="scope_label"><?= esc($scope_label) ?></div>
<?php endif; ?>

<section class="card mb-3 print_hide" aria-labelledby="filters_title">
    <div class="card-header d-flex align-items-center gap-2">
        <h2 class="h6 mb-0" id="filters_title"><span class="bi bi-funnel" aria-hidden="true">&nbsp;</span><?= lang('Purchases.filters') ?></h2>
        <span class="badge text-bg-primary d-none" id="filters_count"></span>
        <button type="button" class="btn btn-outline-secondary btn-sm ms-auto" data-bs-toggle="collapse" data-bs-target="#purchase_filters_body" aria-expanded="true" aria-controls="purchase_filters_body"><span class="bi bi-chevron-expand" aria-hidden="true">&nbsp;</span><?= lang('Purchases.filters_toggle') ?></button>
    </div>
    <div class="collapse show" id="purchase_filters_body">
        <form id="purchase_filters" class="card-body" role="search" aria-label="<?= esc(lang('Purchases.filters'), 'attr') ?>">
            <input type="hidden" id="f_supplier" value="<?= $filters->supplierId > 0 ? $filters->supplierId : '' ?>">
            <div class="acc-filter-row">
                <?php if ($branches !== []): ?>
                    <fieldset class="acc-filter acc-filter-status" id="f_branches">
                        <legend class="form-label"><?= lang('Purchases.branch') ?></legend>
                        <?php foreach ($branches as $branch): ?>
                            <label class="choice"><input type="checkbox" class="form-check-input f-branch" value="<?= (int) $branch->branch_id ?>"<?= $selected === [] || in_array((int) $branch->branch_id, $selected, true) ? ' checked' : '' ?>> <?= esc($branch->code . ' - ' . $branch->name) ?></label>
                        <?php endforeach; ?>
                    </fieldset>
                <?php endif; ?>
                <div class="acc-filter">
                    <label class="form-label" for="f_supplier_name"><?= lang('Purchases.supplier') ?></label>
                    <input type="text" id="f_supplier_name" class="form-control form-control-sm" autocomplete="off" placeholder="<?= esc(lang('Purchases.supplier_placeholder'), 'attr') ?>" value="<?= esc($supplier_label, 'attr') ?>">
                </div>
                <div class="acc-filter">
                    <label class="form-label" for="f_status"><?= lang('Purchases.purchase_status') ?></label>
                    <select id="f_status" class="form-select form-select-sm">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= esc((string) $value, 'attr') ?>"<?= $value === $filters->status ? ' selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="acc-filter">
                    <label class="form-label" for="f_payment"><?= lang('Purchases.payment_status') ?></label>
                    <select id="f_payment" class="form-select form-select-sm">
                        <?php foreach ($payments as $value => $label): ?>
                            <option value="<?= esc((string) $value, 'attr') ?>"<?= $value === $filters->payment ? ' selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="acc-filter">
                    <label class="form-label" for="daterangepicker"><?= lang('Purchases.date_range') ?></label>
                    <input type="text" id="daterangepicker" class="form-control form-control-sm" autocomplete="off">
                </div>
                <div class="acc-filter acc-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
                    <button type="button" id="filters_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_reports.reset') ?></button>
                </div>
            </div>
        </form>
    </div>
</section>

<section class="card" aria-labelledby="list_title">
    <div class="card-header d-flex align-items-center gap-2">
        <h2 class="h6 mb-0" id="list_title"><?= lang('Purchases.all_purchases') ?></h2>
        <?php if ($can_receive): ?>
            <a class="btn btn-primary btn-sm ms-auto print_hide" id="add_purchase" href="<?= esc(site_url('receivings'), 'attr') ?>"><span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Purchases.add') ?></a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <div id="toolbar" class="print_hide">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <label class="d-flex align-items-center gap-2 mb-0" for="page_size"><?= lang('Purchases.page_size') ?>
                    <select id="page_size" class="form-select form-select-sm w-auto">
                        <?php foreach ($page_sizes as $size): ?>
                            <option value="<?= (int) $size ?>"<?= $size === $page_size ? ' selected' : '' ?>><?= (int) $size ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="btn-group" role="group" aria-label="<?= esc(lang('Purchases.export'), 'attr') ?>">
                    <a class="btn btn-outline-secondary btn-sm" id="export_csv" href="<?= esc(site_url('purchases/csv') . '?' . http_build_query($csv_query), 'attr') ?>"><span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Purchases.export_csv') ?></a>
                    <a class="btn btn-outline-secondary btn-sm" id="export_xlsx" href="<?= esc(site_url('purchases/xlsx') . '?' . http_build_query($csv_query), 'attr') ?>"><span class="bi bi-file-earmark-excel" aria-hidden="true">&nbsp;</span><?= lang('Purchases.export_excel') ?></a>
                    <a class="btn btn-outline-secondary btn-sm" id="export_pdf" href="<?= esc(site_url('purchases/pdf') . '?' . http_build_query($csv_query), 'attr') ?>"><span class="bi bi-file-earmark-pdf" aria-hidden="true">&nbsp;</span><?= lang('Purchases.export_pdf') ?></a>
                </div>
                <a class="btn btn-outline-secondary btn-sm" id="print_list" href="#" role="button"><span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Purchases.print') ?></a>
            </div>
        </div>

        <div id="table_holder">
            <table id="table"></table>
        </div>

        <div id="purchase_sums" class="mt-3" aria-live="polite"></div>
    </div>
</section>

<?= view('partial/footer') ?>
