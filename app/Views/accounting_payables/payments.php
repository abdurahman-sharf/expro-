<?php
/**
 * @var string $table_headers
 * @var list<object> $branches
 * @var list<int> $selected
 * @var string|null $scope_label
 * @var bool $scope_empty
 * @var list<string> $ways
 * @var array<string, string> $statuses
 * @var list<object> $treasuries
 * @var list<object> $payers
 * @var int $supplier_id
 * @var bool $sees_balances
 * @var array $config
 */

use App\Libraries\Accounting\SupplierPaymentList;

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const filterValues = function() {
            return {
                search: $('#f_search').val(),
                date_from: $('#f_date_from').val(),
                date_to: $('#f_date_to').val(),
                supplier_id: $('#f_supplier').val(),
                payment_type: $('#f_way').val(),
                status: $('#f_status').val(),
                kind: $('#f_kind').val(),
                treasury_id: $('#f_treasury').val(),
                employee_id: $('#f_payer').val(),
                branches: $('.f-branch:checked').map(function() {
                    return this.value;
                }).get()
            };
        };

        table_support.init({
            resource: 'accounting_payables/payments',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'doc_key',
            cardTitle: ['number'],
            sortName: 'date',
            sortOrder: 'desc',
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_payables.payments_empty')) . '</p>', $jsonFlags) ?>;
            },
            queryParams: function(params) {
                return $.extend(params, filterValues());
            }
        });

        $('#payment_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        $('#filters_reset').on('click', function() {
            $('#payment_filters')[0].reset();
            $('#f_supplier').val('');
            table_support.refresh();
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <p class="mb-0 me-auto text-body-secondary" id="payments_note"><?= lang('Accounting_payables.payments_note') ?></p>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_payables/debit_notes'), 'attr') ?>"><span class="bi bi-file-earmark-minus" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.debit_notes') ?></a>
    <?php if ($sees_balances): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_payables'), 'attr') ?>"><span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Accounting_payables.back') ?></a>
    <?php endif; ?>
</div>

<?php if ($scope_label !== null): ?>
    <div class="alert alert-info mx-2" role="note" id="scope_label"><?= esc($scope_label) ?></div>
<?php endif; ?>

<form id="payment_filters" class="acc-filters print_hide" role="search" aria-label="<?= esc(lang('Accounting_payables.filters'), 'attr') ?>">
    <input type="hidden" id="f_supplier" value="<?= $supplier_id > 0 ? $supplier_id : '' ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_search"><?= lang('Common.search') ?></label>
            <input type="search" id="f_search" class="form-control form-control-sm" autocomplete="off">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_from"><?= lang('Accounting_journals.date_from') ?></label>
            <input type="date" id="f_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_to"><?= lang('Accounting_journals.date_to') ?></label>
            <input type="date" id="f_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_kind"><?= lang('Accounting_payables.payment_kind') ?></label>
            <select id="f_kind" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_payables.all_kinds') ?></option>
                <?php foreach ([SupplierPaymentList::PURCHASE, SupplierPaymentList::ADVANCE, SupplierPaymentList::REFUND] as $kind): ?>
                    <option value="<?= $kind ?>"><?= esc(lang('Accounting_payables.kind_' . $kind)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_way"><?= lang('Accounting_payables.way') ?></label>
            <select id="f_way" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_payables.all_ways') ?></option>
                <?php foreach ($ways as $way): ?>
                    <option value="<?= esc($way, 'attr') ?>"><?= esc($way) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_status"><?= lang('Accounting_payables.status') ?></label>
            <select id="f_status" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_payables.all_statuses') ?></option>
                <?php foreach ($statuses as $status => $label): ?>
                    <option value="<?= esc($status, 'attr') ?>"><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($treasuries !== []): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_treasury"><?= lang('Accounting_payables.treasury') ?></label>
                <select id="f_treasury" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_payables.all_treasuries') ?></option>
                    <?php foreach ($treasuries as $treasury): ?>
                        <option value="<?= (int) $treasury->treasury_id ?>"><?= esc($treasury->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($payers !== []): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_payer"><?= lang('Accounting_payables.paid_by') ?></label>
                <select id="f_payer" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_payables.all_payers') ?></option>
                    <?php foreach ($payers as $payer): ?>
                        <option value="<?= (int) $payer->employee_id ?>"><?= esc($payer->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <?php if ($branches !== []): ?>
            <fieldset class="acc-filter acc-filter-status" id="f_branches">
                <legend class="form-label"><?= lang('Accounting_reports.branches') ?></legend>
                <?php foreach ($branches as $branch): ?>
                    <label class="choice"><input type="checkbox" class="form-check-input f-branch" value="<?= (int) $branch->branch_id ?>"<?= in_array((int) $branch->branch_id, $selected, true) ? ' checked' : '' ?>> <?= esc($branch->code . ' - ' . $branch->name) ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
            <button type="button" id="filters_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_reports.reset') ?></button>
        </div>
    </div>
</form>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
