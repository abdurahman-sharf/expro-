<?php
/**
 * @var string $table_headers
 * @var bool $can_manage
 * @var array $config
 */

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'roles',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'role_id',
            cardTitle: ['name']
        });

        $(document).on('click', '.role-action', function() {
            const id = $(this).data('role-id');

            if (!confirm(<?= json_encode(lang('Roles.confirm_delete'), $jsonFlags) ?>)) {
                return;
            }

            $.post('roles/delete/' + id, {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    table_support.refresh();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="roles/view" title="<?= lang('Roles.new') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Roles.new') ?>
        </button>
    <?php endif; ?>
</div>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
