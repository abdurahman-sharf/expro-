<?php
/**
 * @var string $table_headers
 * @var bool   $can_manage
 * @var array  $config
 */
$messages = [
    'activate'   => lang('Accounting_cost_centers.confirm_activate'),
    'deactivate' => lang('Accounting_cost_centers.confirm_deactivate'),
    'delete'     => lang('Accounting_cost_centers.confirm_delete'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

        table_support.init({
            resource: 'accounting_cost_centers',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'cost_center_id',
            cardTitle: ['name']
        });

        $(document).on('click', '.acc-action', function() {
            const action = $(this).data('acc-action');
            const id = $(this).data('acc-id');

            if (!confirm(confirmations[action])) {
                return;
            }

            $.post('accounting_cost_centers/' + action + '/' + id, {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    table_support.refresh();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_cost_centers/defaults'), 'attr') ?>">
        <span class="bi bi-diagram-3" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cost_centers.defaults_link') ?>
    </a>
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_cost_centers/view" title="<?= lang('Accounting_cost_centers.new') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cost_centers.new') ?>
        </button>
    <?php endif; ?>
</div>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
