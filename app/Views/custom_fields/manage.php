<?php
/**
 * @var string $tableHeaders
 * @var bool   $canManage
 * @var array  $config
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'custom_fields',
            headers: <?= $tableHeaders ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'field_id',
            cardTitle: ['name']
        });

        $('#table_holder').on('click', '.custom-field-status', function() {
            $.post('<?= esc(base_url('custom_fields/status'), 'js') ?>/' + $(this).data('id') + '/' + $(this).data('active'), function(response) {
                table_support.handle_submit('custom_fields', response);
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <p class="mb-0 text-body-secondary"><?= lang('Custom_fields.manage_note') ?></p>
    <?php if ($canManage): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('custom_fields/view'), 'attr') ?>" title="<?= esc(lang('Custom_fields.new'), 'attr') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Custom_fields.new') ?>
        </button>
    <?php endif; ?>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
