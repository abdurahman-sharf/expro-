<?php
/**
 * @var string $table_headers
 * @var bool $can_manage
 * @var bool $can_reconcile
 * @var bool $scope_notice
 * @var array $config
 */

$messages = [
    'activate'   => lang('Accounting_treasuries.confirm_activate'),
    'deactivate' => lang('Accounting_treasuries.confirm_deactivate'),
    'delete'     => lang('Accounting_treasuries.confirm_delete'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

        table_support.init({
            resource: 'accounting_treasuries',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'treasury_id',
            cardTitle: ['name'],
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_treasuries.empty')) . '</p>', $jsonFlags) ?>;
            }
        });

        $(document).on('click', '.acc-action', function() {
            const action = $(this).data('acc-action');
            const id = $(this).data('acc-id');

            if (!confirm(confirmations[action])) {
                return;
            }

            const endpoint = action === 'delete' ? 'delete' : action;

            $.post('accounting_treasuries/' + endpoint + '/' + id, {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    table_support.refresh();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <p class="mb-0 me-auto text-body-secondary"><?= lang('Accounting_treasuries.note') ?></p>
    <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries/transfers"><span class="bi bi-arrow-left-right" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.transfers') ?></a>
    <?php if ($can_reconcile): ?>
        <a class="btn btn-outline-secondary btn-sm" id="bank_reconciliation_link" href="accounting_treasuries/bank"><span class="bi bi-bank" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.bank_reconciliation') ?></a>
    <?php endif; ?>
    <?php if ($can_manage): ?>
        <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries/defaults"><span class="bi bi-person-gear" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.defaults') ?></a>
        <button class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_treasuries/view" title="<?= lang('Accounting_treasuries.new') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.new') ?>
        </button>
    <?php endif; ?>
</div>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
