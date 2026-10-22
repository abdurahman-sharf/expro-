<?php
/**
 * @var string                                   $table_headers
 * @var list<array{code: string, label: string}> $sources
 * @var list<object>                             $branches
 * @var int|null                                 $current_branch
 * @var array                                    $config
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'accounting_journals/failures',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'failure_id',
            cardTitle: ['source'],
            sortName: 'created_at',
            sortOrder: 'desc',
            queryParams: function(params) {
                return $.extend(params, {
                    date_from: $('#f_date_from').val(),
                    date_to: $('#f_date_to').val(),
                    source_type: $('#f_source').val(),
                    branch_id: $('#f_branch').val() || ''
                });
            }
        });

        $('#failure_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        $('#failure_reset').on('click', function() {
            $('#failure_filters')[0].reset();
            table_support.refresh();
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_journals'), 'attr') ?>">
        <span class="bi bi-arrow-<?= isRtl() ? 'right' : 'left' ?>" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.back') ?>
    </a>
</div>

<?= view('accounting_journals/audit_tabs', ['active' => 'failures']) ?>

<p class="text-body-secondary small"><?= lang('Accounting_journals.failures_desc') ?></p>

<form id="failure_filters" class="acc-filters print_hide" role="search" aria-label="<?= lang('Accounting_journals.filters') ?>">
    <div class="acc-filter-row">
        <?php if ($branches !== []): ?>
            <div class="acc-filter">
                <label class="form-label" for="f_branch"><?= lang('Accounting_journals.branch') ?></label>
                <select id="f_branch" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_journals.all_branches') ?></option>
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch->branch_id ?>"<?= $current_branch === (int) $branch->branch_id ? ' selected' : '' ?>><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="acc-filter">
            <label class="form-label" for="f_date_from"><?= lang('Accounting_journals.date_from') ?></label>
            <input type="date" id="f_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_to"><?= lang('Accounting_journals.date_to') ?></label>
            <input type="date" id="f_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_source"><?= lang('Accounting_journals.failure_source') ?></label>
            <select id="f_source" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_journals.all_sources') ?></option>
                <?php foreach ($sources as $source): ?>
                    <option value="<?= esc($source['code'], 'attr') ?>"><?= esc($source['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
            <button type="button" id="failure_reset" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_journals.reset') ?></button>
        </div>
    </div>
</form>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
