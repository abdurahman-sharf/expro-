<?php
/**
 * @var string $resource
 * @var string $kind
 * @var string $tableHeaders
 * @var array  $config
 */
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: '<?= esc($resource, 'js') ?>',
            headers: <?= $tableHeaders ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: '<?= $kind === 'item_brand' ? 'brand_id' : 'classification_id' ?>',
            cardTitle: ['name']
        });

        $('#table_holder').on('click', '.lookup-status', function() {
            $.post('<?= esc(base_url($resource . '/status'), 'js') ?>/' + $(this).data('id') + '/' + $(this).data('active'), function(response) {
                table_support.handle_submit('<?= esc($resource, 'js') ?>', response);
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url($resource . '/view'), 'attr') ?>" title="<?= esc(lang('Lookups.' . $kind . '_new'), 'attr') ?>">
        <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Lookups.' . $kind . '_new') ?>
    </button>
</div>

<div id="toolbar">
    <div class="float-start d-flex flex-wrap align-items-center gap-2" role="toolbar">
        <button id="delete" class="btn btn-outline-secondary btn-sm print_hide">
            <span class="bi bi-trash" aria-hidden="true">&nbsp;</span><?= lang('Common.delete') ?>
        </button>
    </div>
</div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
