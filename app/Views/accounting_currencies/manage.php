<?php
/**
 * @var string $table_headers
 * @var bool   $can_manage
 * @var array  $config
 */
$messages = [
    'activate'   => lang('Accounting_currencies.confirm_activate'),
    'deactivate' => lang('Accounting_currencies.confirm_deactivate'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

        table_support.init({
            resource: 'accounting_currencies',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'code',
            cardTitle: ['name']
        });

        $(document).on('click', '.acc-action', function() {
            const action = $(this).data('acc-action');
            const id = $(this).data('acc-id');

            appConfirm(confirmations[action], {tone: action === 'delete' ? 'danger' : 'primary'}).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }

                $.post('accounting_currencies/' + action + '/' + id, {}, function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        table_support.refresh();
                    }
                }, 'json');
            });
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_currencies/form" title="<?= lang('Accounting_currencies.new') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_currencies.new') ?>
        </button>
    <?php endif; ?>
</div>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
