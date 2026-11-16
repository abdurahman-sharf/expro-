<?php
/**
 * @var string               $table_headers
 * @var int|null             $selected_id
 * @var bool                 $can_manage
 * @var bool                 $scope_notice
 * @var array<string, mixed> $tree
 * @var array                $config
 */
$messages = [
    'activate'   => lang('Accounting_chart.confirm_activate'),
    'deactivate' => lang('Accounting_chart.confirm_deactivate'),
    'delete'     => lang('Accounting_chart.confirm_delete'),
];
$text = [
    'loading'     => lang('Accounting_chart.loading'),
    'load_failed' => lang('Accounting_chart.load_failed'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        accounting_chart.init({
            selectedId: <?= (int) ($selected_id ?? 0) ?>,
            confirmations: <?= json_encode($messages, $jsonFlags) ?>,
            text: <?= json_encode($text, $jsonFlags) ?>,
            table: function() {
                table_support.init({
                    resource: 'accounting_chart',
                    headers: <?= $table_headers ?>,
                    pageSize: <?= $config['lines_per_page'] ?>,
                    uniqueId: 'account_id',
                    cardTitle: ['name'],
                    queryParams: function(params) {
                        return $.extend(params, {flat: 1});
                    }
                });
            }
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_chart/view?main=1" title="<?= esc(lang('Accounting_chart.add_main'), 'attr') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_chart.add_main') ?>
        </button>
    <?php endif; ?>
</div>

<?php if ($scope_notice): ?>
    <p class="acc-scope-note text-secondary small" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= lang('Accounting.scope_note') ?></p>
<?php endif; ?>

<p class="text-secondary small mb-3" id="chart_nominal_note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= lang('Accounting_chart.nominal_note') ?></p>

<section class="acc-chart" id="acc_chart">
    <div class="acc-chart-bar print_hide">
        <div class="btn-group" role="group" id="chart_view_switch" aria-label="<?= esc(lang('Accounting_chart.view_switch'), 'attr') ?>">
            <button type="button" class="btn btn-sm btn-outline-primary active" data-chart-view="tree" aria-pressed="true"><span class="bi bi-diagram-3" aria-hidden="true"></span> <?= lang('Accounting_chart.view_tree') ?></button>
            <button type="button" class="btn btn-sm btn-outline-primary" data-chart-view="table" aria-pressed="false"><span class="bi bi-table" aria-hidden="true"></span> <?= lang('Accounting_chart.view_table') ?></button>
        </div>

        <div class="acc-chart-search" id="chart_search_box">
            <label class="visually-hidden" for="chart_search"><?= lang('Accounting_chart.search') ?></label>
            <input type="search" id="chart_search" class="form-control form-control-sm" placeholder="<?= esc(lang('Accounting_chart.search'), 'attr') ?>" autocomplete="off">
        </div>

        <div class="btn-group" role="group" id="chart_tree_tools">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="chart_expand_all"><span class="bi bi-arrows-expand" aria-hidden="true"></span> <?= lang('Accounting_chart.expand_all') ?></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="chart_collapse_all"><span class="bi bi-arrows-collapse" aria-hidden="true"></span> <?= lang('Accounting_chart.collapse_all') ?></button>
        </div>
    </div>

    <?php if ($can_manage): ?>
        <ul class="acc-hints print_hide">
            <li><span class="bi bi-lightbulb" aria-hidden="true"></span> <?= lang('Accounting_chart.hint_add_child') ?></li>
            <li><span class="bi bi-lightbulb" aria-hidden="true"></span> <?= lang('Accounting_chart.hint_code') ?></li>
        </ul>
    <?php endif; ?>

    <div id="chart_tree_view">
        <div id="chart_results" aria-live="polite" hidden></div>
        <ul class="acc-tree" id="chart_tree_root">
            <?php foreach ($tree['nodes'][0] ?? [] as $root): ?>
                <?= view('accounting_chart/node', ['account' => $root, 'ctx' => $tree]) ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div id="chart_table_view" hidden>
        <div id="toolbar"></div>

        <div id="table_holder">
            <table id="table"></table>
        </div>
    </div>
</section>

<?= view('partial/footer') ?>
