<?php
/**
 * @var string $table_headers
 * @var bool $can_create
 * @var array $config
 */

$messages = [
    'activate'   => lang('Branches.confirm_activate'),
    'deactivate' => lang('Branches.confirm_deactivate'),
    'delete'     => lang('Branches.confirm_delete'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

        table_support.init({
            resource: 'branches',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'branch_id',
            cardTitle: ['name']
        });

        $(document).on('click', '.branch-action', function() {
            const action = $(this).data('branch-action');
            const id = $(this).data('branch-id');

            if (!confirm(confirmations[action])) {
                return;
            }

            $.post('branches/' + action + '/' + id, {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    table_support.refresh();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <?php if ($can_create): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="branches/view" title="<?= lang('Branches.new') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Branches.new') ?>
        </button>
    <?php endif; ?>
</div>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
