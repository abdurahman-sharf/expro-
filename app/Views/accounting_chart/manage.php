<?php
/**
 * @var string $table_headers
 * @var int|null $parent_id
 * @var bool $can_manage
 * @var bool $scope_notice
 * @var array $tree
 * @var array $panel
 * @var array $config
 */

$messages = [
    'activate'   => lang('Accounting_chart.confirm_activate'),
    'deactivate' => lang('Accounting_chart.confirm_deactivate'),
    'delete'     => lang('Accounting_chart.confirm_delete'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const selectedId = <?= (int) ($parent_id ?? 0) ?>;
        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

        table_support.init({
            resource: 'accounting_chart',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'account_id',
            cardTitle: ['name'],
            queryParams: function(params) {
                return $.extend(params, {parent_id: selectedId});
            }
        });

        window.refreshAccountingChart = function() {
            $('#chart_tree').load('accounting_chart/tree?parent=' + selectedId);
            $('#chart_panel').load('accounting_chart/panel?parent=' + selectedId);
            table_support.refresh();
        };

        $(document).on('click', '.acc-tree-caret', function() {
            const expanded = $(this).attr('aria-expanded') === 'true';
            $(this).attr('aria-expanded', expanded ? 'false' : 'true');
            $(this).closest('li').children('ul').prop('hidden', expanded);
        });

        $(document).on('click', '.acc-action', function() {
            const action = $(this).data('acc-action');
            const id = $(this).data('acc-id');

            if (!confirm(confirmations[action])) {
                return;
            }

            $.post('accounting_chart/' + action + '/' + id, {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.refreshAccountingChart();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc('accounting_chart/view?parent_id=' . (int) ($parent_id ?? 0), 'attr') ?>" title="<?= lang('Accounting_chart.new') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_chart.new') ?>
        </button>
    <?php endif; ?>
</div>

<?php if ($scope_notice): ?>
    <p class="acc-scope-note text-secondary small" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= lang('Accounting.scope_note') ?></p>
<?php endif; ?>

<div class="acc-chart">
    <button class="btn btn-outline-secondary btn-sm acc-tree-toggle d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#chart_tree" aria-expanded="false" aria-controls="chart_tree">
        <span class="bi bi-diagram-3" aria-hidden="true">&nbsp;</span><?= lang('Accounting_chart.tree') ?>
    </button>

    <aside id="chart_tree" class="acc-chart-tree collapse d-lg-block">
        <?= view('accounting_chart/tree', $tree) ?>
    </aside>

    <section class="acc-chart-main">
        <div id="chart_panel">
            <?= view('accounting_chart/panel', $panel + ['can_manage' => $can_manage]) ?>
        </div>

        <div id="toolbar"></div>

        <div id="table_holder">
            <table id="table"></table>
        </div>
    </section>
</div>

<?= view('partial/footer') ?>
