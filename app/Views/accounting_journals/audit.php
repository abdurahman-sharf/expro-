<?php
/**
 * @var string $table_headers
 * @var list<string> $actions
 * @var list<object> $employees
 * @var list<object> $branches
 * @var int|null $current_branch
 * @var array $config
 */

$actionLabel = static function (string $action): string {
    $key  = 'Accounting.action_' . $action;
    $line = lang($key);

    return $line === $key ? $action : $line;
};
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'accounting_journals/audit',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'audit_id',
            cardTitle: ['action'],
            sortName: 'created_at',
            sortOrder: 'desc',
            queryParams: function(params) {
                return $.extend(params, {
                    date_from: $('#a_date_from').val(),
                    date_to: $('#a_date_to').val(),
                    action: $('#a_action').val(),
                    entity_type: $('#a_entity').val(),
                    employee_id: $('#a_employee').val(),
                    branch_id: $('#a_branch').val() || ''
                });
            }
        });

        $('#audit_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        $('#audit_reset').on('click', function() {
            $('#audit_filters')[0].reset();
            table_support.refresh();
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_journals'), 'attr') ?>">
        <span class="bi bi-arrow-<?= isRtl() ? 'right' : 'left' ?>" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.back') ?>
    </a>
</div>

<?= view('accounting_journals/audit_tabs', ['active' => 'log']) ?>

<form id="audit_filters" class="acc-filters print_hide" role="search" aria-label="<?= lang('Accounting_journals.filters') ?>">
    <div class="acc-filter-row">
        <?php if ($branches !== []): ?>
            <div class="acc-filter">
                <label class="form-label" for="a_branch"><?= lang('Accounting_journals.branch') ?></label>
                <select id="a_branch" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_journals.all_branches') ?></option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch->branch_id ?>"<?= $current_branch === (int) $branch->branch_id ? ' selected' : '' ?>><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="acc-filter">
            <label class="form-label" for="a_date_from"><?= lang('Accounting_journals.date_from') ?></label>
            <input type="date" id="a_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="a_date_to"><?= lang('Accounting_journals.date_to') ?></label>
            <input type="date" id="a_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="a_action"><?= lang('Accounting_journals.audit_action') ?></label>
            <select id="a_action" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_journals.all_actions') ?></option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= esc($action, 'attr') ?>"><?= esc($actionLabel($action)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="a_entity"><?= lang('Accounting_journals.audit_entity') ?></label>
            <select id="a_entity" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_journals.all_entities') ?></option>
                <option value="account"><?= lang('Accounting.entity_account') ?></option>
                <option value="cost_center"><?= lang('Accounting.entity_cost_center') ?></option>
                <option value="journal"><?= lang('Accounting.entity_journal') ?></option>
                <option value="settings"><?= lang('Accounting.entity_settings') ?></option>
                <option value="branch"><?= lang('Accounting.entity_branch') ?></option>
                <option value="employee_scope"><?= lang('Accounting.entity_employee_scope') ?></option>
                <option value="routing"><?= lang('Accounting.entity_routing') ?></option>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="a_employee"><?= lang('Accounting_journals.audit_employee') ?></label>
            <select id="a_employee" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_journals.all_creators') ?></option>
                <?php foreach ($employees as $employee): ?>
                    <option value="<?= (int) $employee->employee_id ?>"><?= esc($employee->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
            <button type="button" id="audit_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_journals.reset') ?></button>
        </div>
    </div>
</form>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
