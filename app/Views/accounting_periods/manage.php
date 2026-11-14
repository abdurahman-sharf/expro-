<?php
/**
 * @var string   $table_headers
 * @var bool     $can_manage
 * @var int|null $days_left
 * @var bool     $auto_create
 * @var array    $config
 */
$messages  = ['delete' => lang('Accounting_periods.confirm_delete')];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

        table_support.init({
            resource: 'accounting_periods',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'fiscal_year_id',
            cardTitle: ['name']
        });

        $(document).on('click', '.acc-action', function() {
            const id = $(this).data('acc-id');

            if (!confirm(confirmations.delete)) {
                return;
            }

            $.post('accounting_periods/delete/' + id, {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    table_support.refresh();
                }
            }, 'json');
        });
    });
</script>

<?= view('accounting_periods/tabs', ['active' => 'years']) ?>

<?php if ($days_left !== null && ! $auto_create): ?>
    <p class="alert alert-warning acc-year-notice" role="alert"><span class="bi bi-exclamation-triangle" aria-hidden="true"></span> <?= $days_left >= 0 ? lang('Accounting_periods.notice_ending', [(string) $days_left]) : lang('Accounting_periods.notice_ended') ?></p>
<?php endif; ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm float-end modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_periods/view" title="<?= lang('Accounting_periods.new') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.new') ?>
        </button>
    <?php endif; ?>
</div>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
