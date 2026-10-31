<?php
/**
 * @var string               $table_headers
 * @var list<object>         $branches
 * @var list<object>         $collectors
 * @var array<string,string> $ways
 * @var array<string,string> $statuses
 * @var int                  $customer_id
 * @var bool                 $limited
 * @var bool                 $company_scope
 * @var array                $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const filterValues = function() {
            return {
                date_from: $('#f_date_from').val(),
                date_to: $('#f_date_to').val(),
                customer: $('#f_customer').val(),
                customer_id: $('#f_customer_id').val(),
                way: $('#f_way').val(),
                employee_id: $('#f_employee').val(),
                branch_id: $('#f_branch').val() || '',
                status: $('#f_status').val()
            };
        };

        table_support.init({
            resource: 'accounting_receivables/payments',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'payment_id',
            cardTitle: ['number'],
            sortName: 'payment_time',
            sortOrder: 'desc',
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_receivables.payments_empty')) . '</p>', $jsonFlags) ?>;
            },
            queryParams: function(params) {
                return $.extend(params, filterValues());
            },
            onLoadSuccess: function(response) {
                $('#sum_amount').html(response.sum_amount || '');
                $('#sum_posted').html(response.sum_posted || '');
            }
        });

        $('#payment_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        $('#filters_reset').on('click', function() {
            $('#payment_filters')[0].reset();
            $('#f_customer_id').val('');
            table_support.refresh();
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <?= view('accounting_receivables/tabs', ['active' => 'payments', 'company_scope' => $company_scope]) ?>
    <p class="mb-0 text-body-secondary"><?= lang('Accounting_receivables.payments_note') ?></p>
    <?php if ($limited): ?>
        <p class="mb-0 text-body-secondary" id="payments_partial"><?= lang('Accounting_receivables.payments_partial') ?></p>
    <?php endif; ?>
</div>

<form id="payment_filters" class="acc-filters print_hide" role="search" aria-label="<?= esc(lang('Accounting_receivables.filters'), 'attr') ?>">
    <input type="hidden" id="f_customer_id" value="<?= $customer_id > 0 ? (int) $customer_id : '' ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_date_from"><?= lang('Accounting_receivables.date_from') ?></label>
            <input type="date" id="f_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_to"><?= lang('Accounting_receivables.date_to') ?></label>
            <input type="date" id="f_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_customer"><?= lang('Accounting_receivables.customer') ?></label>
            <input type="text" id="f_customer" class="form-control form-control-sm" autocomplete="off">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_way"><?= lang('Accounting_receivables.way') ?></label>
            <select id="f_way" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_receivables.all_ways') ?></option>
                <?php foreach ($ways as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_employee"><?= lang('Accounting_receivables.collected_by') ?></label>
            <select id="f_employee" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_receivables.all_employees') ?></option>
                <?php foreach ($collectors as $collector): ?>
                    <option value="<?= (int) $collector->employee_id ?>"><?= esc($collector->employee_name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_status"><?= lang('Accounting_receivables.status') ?></label>
            <select id="f_status" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_receivables.all_statuses') ?></option>
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($branches !== []): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_branch"><?= lang('Accounting_receivables.branch') ?></label>
                <select id="f_branch" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_receivables.all_branches') ?></option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch->branch_id ?>"><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
            <button type="button" id="filters_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_receivables.reset') ?></button>
        </div>
    </div>
</form>

<div id="table_holder">
    <table id="table"></table>
</div>

<p class="mt-2 mb-0" id="payments_totals">
    <?= lang('Accounting_receivables.payments_total') ?>: <strong id="sum_amount"></strong>
    <span class="text-body-secondary ms-3"><?= lang('Accounting_receivables.posted') ?>: <strong id="sum_posted"></strong></span>
</p>

<?= view('partial/footer') ?>
