<?php
/**
 * @var string $controller_name
 * @var string $table_headers
 * @var array $config
 * @var array<string, array<int|string, string>> $filterOptions
 */

$filterNames = ['classification_id', 'manual_status', 'assigned_employee_id', 'area', 'city', 'country_code', 'customer_type', 'created_from', 'created_to'];
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const filterIds = <?= json_encode($filterNames) ?>;

        table_support.init({
            resource: '<?= esc($controller_name) ?>',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'people.person_id',
            cardTitle: ['last_name', 'first_name', 'company_name'],
            queryParams: function() {
                const filters = {};

                filterIds.forEach(function(name) {
                    filters[name] = $('#filter_' + name).val();
                });

                return $.extend(arguments[0], filters);
            },
            enableActions: function() {
                const email_disabled = $("td input:checkbox:checked").parents("tr").find("td a[href^='mailto:']").length == 0;
                $("#email").prop('disabled', email_disabled);
            }
        });

        $('.customer-filter').on('change', function() {
            table_support.refresh();
        });

        $('#filter_reset').on('click', function() {
            $('.customer-filter').val('');
            table_support.refresh();
        });

        $("#email").click(function(event) {
            const recipients = $.map($("tr.selected a[href^='mailto:']"), function(element) {
                return $(element).attr('href').replace(/^mailto:/, '');
            });
            location.href = "mailto:" + recipients.join(",");
        });
    });
</script>

<div id="title_bar" class="btn-toolbar">
    <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "$controller_name/csvImport" ?>" title="<?= lang('Customers.import_items_csv') ?>">
        <span class="bi bi-box-arrow-in-down" aria-hidden="true">&nbsp;</span><?= lang('Common.import_csv') ?>
    </button>
    <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= "$controller_name/view" ?>" title="<?= lang('Customers.new') ?>">
        <span class="bi bi-person" aria-hidden="true">&nbsp;</span><?= lang('Customers.new') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(site_url('customer_classifications'), 'attr') ?>">
        <span class="bi bi-tags" aria-hidden="true">&nbsp;</span><?= lang('Customers.classifications_manage') ?>
    </a>
</div>

<div id="toolbar">
    <div class="float-start btn-toolbar">
        <button id="delete" class="btn btn-outline-secondary btn-sm">
            <span class="bi bi-trash" aria-hidden="true">&nbsp;</span><?= lang('Common.delete') ?>
        </button>
        <button id="email" class="btn btn-outline-secondary btn-sm">
            <span class="bi bi-envelope" aria-hidden="true">&nbsp;</span><?= lang('Common.email') ?>
        </button>
    </div>
    <div class="float-start d-flex flex-wrap align-items-center gap-2 ms-2" id="customer_filters" role="search" aria-label="<?= esc(lang('Customers.filters'), 'attr') ?>">
        <?= form_dropdown('classification_id', array_map('esc', ['' => lang('Customers.classification')] + $filterOptions['classifications']), '', 'id="filter_classification_id" class="customer-filter form-select form-select-sm w-auto" aria-label="' . esc(lang('Customers.classification'), 'attr') . '"') ?>
        <?= form_dropdown('manual_status', array_map('esc', ['' => lang('Customers.manual_status')] + $filterOptions['statuses']), '', 'id="filter_manual_status" class="customer-filter form-select form-select-sm w-auto" aria-label="' . esc(lang('Customers.manual_status'), 'attr') . '"') ?>
        <?= form_dropdown('assigned_employee_id', array_map('esc', ['' => lang('Customers.assigned_employee')] + $filterOptions['employees']), '', 'id="filter_assigned_employee_id" class="customer-filter form-select form-select-sm w-auto" aria-label="' . esc(lang('Customers.assigned_employee'), 'attr') . '"') ?>
        <?= form_dropdown('customer_type', array_map('esc', ['' => lang('Customers.type')] + $filterOptions['types']), '', 'id="filter_customer_type" class="customer-filter form-select form-select-sm w-auto" aria-label="' . esc(lang('Customers.type'), 'attr') . '"') ?>
        <input type="text" id="filter_area" class="customer-filter form-control form-control-sm w-auto" maxlength="100" placeholder="<?= esc(lang('Customers.area'), 'attr') ?>" aria-label="<?= esc(lang('Customers.area'), 'attr') ?>">
        <input type="text" id="filter_city" class="customer-filter form-control form-control-sm w-auto" maxlength="100" placeholder="<?= esc(lang('Common.city'), 'attr') ?>" aria-label="<?= esc(lang('Common.city'), 'attr') ?>">
        <input type="text" id="filter_country_code" class="customer-filter form-control form-control-sm w-auto" maxlength="2" dir="ltr" placeholder="<?= esc(lang('Customers.country_code'), 'attr') ?>" aria-label="<?= esc(lang('Customers.country_code'), 'attr') ?>">
        <input type="date" id="filter_created_from" class="customer-filter form-control form-control-sm w-auto" aria-label="<?= esc(lang('Customers.created_from'), 'attr') ?>" title="<?= esc(lang('Customers.created_from'), 'attr') ?>">
        <input type="date" id="filter_created_to" class="customer-filter form-control form-control-sm w-auto" aria-label="<?= esc(lang('Customers.created_to'), 'attr') ?>" title="<?= esc(lang('Customers.created_to'), 'attr') ?>">
        <button type="button" id="filter_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Customers.filters_reset') ?></button>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
