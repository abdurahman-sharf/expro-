<?php
/**
 * @var string               $direction
 * @var string               $table_headers
 * @var array<string,string> $statuses
 * @var bool                 $can_manage
 * @var bool                 $scope_notice
 * @var array                $config
 */

$messages = [
    'undo'   => lang('Accounting_cheques.confirm_undo'),
    'cancel' => lang('Accounting_cheques.confirm_cancel'),
    'delete' => lang('Accounting_cheques.confirm_delete'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

        const filterValues = function() {
            return {
                direction: '<?= esc($direction, 'js') ?>',
                status: $('#f_status').val(),
                date_from: $('#f_date_from').val(),
                date_to: $('#f_date_to').val(),
                deleted: $('#f_deleted').is(':checked') ? '1' : ''
            };
        };

        table_support.init({
            resource: 'accounting_cheques',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'cheque_id',
            cardTitle: ['cheque_number'],
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_cheques.empty')) . '</p>', $jsonFlags) ?>;
            },
            queryParams: function(params) {
                return $.extend(params, filterValues());
            }
        });

        $('#cheque_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        $(document).on('click', '.acc-action', function() {
            const action = $(this).data('acc-action');
            const id = $(this).data('acc-id');

            if (!confirm(confirmations[action])) {
                return;
            }

            $.post('accounting_cheques/' + action + '/' + id, {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    table_support.refresh();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <ul class="nav nav-pills me-auto" role="tablist">
        <?php foreach (['received', 'paid'] as $kind): ?>
            <li class="nav-item"><a class="nav-link<?= $direction === $kind ? ' active' : '' ?>" href="<?= esc(base_url('accounting_cheques?direction=' . $kind), 'attr') ?>"><?= lang('Accounting_cheques.direction_' . $kind) ?></a></li>
        <?php endforeach; ?>
    </ul>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_cheques/report/' . $direction), 'attr') ?>"><span class="bi bi-file-earmark-bar-graph" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cheques.report') ?></a>
    <?php if ($direction === 'paid'): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_cheques/books'), 'attr') ?>"><span class="bi bi-journal-bookmark" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cheques.books') ?></a>
    <?php endif; ?>
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('accounting_cheques/form/' . $direction), 'attr') ?>" title="<?= esc(lang('Accounting_cheques.new_' . $direction), 'attr') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cheques.new_' . $direction) ?>
        </button>
    <?php endif; ?>
</div>

<form id="cheque_filters" class="acc-filters print_hide" role="search" aria-label="<?= esc(lang('Accounting_cheques.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_status"><?= lang('Accounting_cheques.status') ?></label>
            <select id="f_status" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_cheques.all_statuses') ?></option>
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>"><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_from"><?= lang('Accounting_cheques.date_from') ?></label>
            <input type="date" id="f_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_date_to"><?= lang('Accounting_cheques.date_to') ?></label>
            <input type="date" id="f_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="choice"><input type="checkbox" class="form-check-input" id="f_deleted" value="1"> <?= lang('Accounting_cheques.deleted') ?></label>
        </div>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
        </div>
    </div>
</form>

<?php if ($scope_notice): ?>
    <p class="text-body-secondary small mx-2"><?= lang('Accounting_cheques.scope_notice') ?></p>
<?php endif; ?>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
