<?php
/**
 * @var string                                                         $table_headers
 * @var bool                                                           $can_manage
 * @var list<object>                                                   $treasuries
 * @var list<array{code: string, receivable: string, payable: string}> $branch_balances
 * @var string|null                                                    $branch_unmatched
 * @var array                                                          $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'accounting_treasuries/transfers',
            headers: <?= $table_headers ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'transfer_id',
            cardTitle: ['transfer_id'],
            queryParams: function(params) {
                return $.extend(params, {
                    date_from: $('#t_date_from').val(),
                    date_to: $('#t_date_to').val(),
                    treasury_id: $('#t_treasury').val()
                });
            },
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_treasuries.empty_transfers')) . '</p>', $jsonFlags) ?>;
            }
        });

        $('#transfer_filters').on('submit', function(event) {
            event.preventDefault();
            table_support.refresh();
        });

        $('#transfer_reset').on('click', function() {
            $('#transfer_filters')[0].reset();
            table_support.refresh();
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries"><span class="bi bi-wallet2" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.treasuries') ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries/inter_branch"><span class="bi bi-diagram-2" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.inter_branch') ?></a>
    <?php if ($can_manage): ?>
        <button class="btn btn-primary btn-sm modal-dlg" data-btn-submit="<?= lang('Common.submit') ?>" data-href="accounting_treasuries/transfer_form" title="<?= lang('Accounting_treasuries.new_transfer') ?>">
            <span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.new_transfer') ?>
        </button>
    <?php endif; ?>
</div>

<?php if ($branch_unmatched !== null): ?>
    <div class="alert alert-warning mx-2" role="alert"><?= esc(lang('Accounting_treasuries.between_difference', [$branch_unmatched])) ?></div>
<?php endif; ?>

<?php if ($branch_balances !== []): ?>
    <div class="mx-2 mb-2">
        <h2 class="h6"><?= esc(lang('Accounting_treasuries.branch_balances_title')) ?></h2>
        <p class="form-text mb-1" id="branch_balances_note"><?= esc(lang('Accounting_treasuries.branch_balances_note')) ?></p>
        <table class="table table-sm table-bordered w-auto mb-0" id="branch_balances">
            <thead>
                <tr>
                    <th scope="col"><?= esc(lang('Accounting_treasuries.branch_balances_branch')) ?></th>
                    <th scope="col"><?= esc(lang('Accounting_treasuries.branch_balances_owed')) ?></th>
                    <th scope="col"><?= esc(lang('Accounting_treasuries.branch_balances_owing')) ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($branch_balances as $row): ?>
                    <tr>
                        <th scope="row"><bdi dir="ltr"><?= esc($row['code']) ?></bdi></th>
                        <td><bdi dir="ltr"><?= esc($row['receivable']) ?></bdi></td>
                        <td><bdi dir="ltr"><?= esc($row['payable']) ?></bdi></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<form id="transfer_filters" class="acc-filters print_hide" role="search" aria-label="<?= esc(lang('Accounting_treasuries.transfers_filter'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="t_date_from"><?= esc(lang('Accounting_treasuries.transfers_date_from')) ?></label>
            <input type="date" id="t_date_from" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="t_date_to"><?= esc(lang('Accounting_treasuries.transfers_date_to')) ?></label>
            <input type="date" id="t_date_to" class="form-control form-control-sm">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="t_treasury"><?= esc(lang('Accounting_treasuries.transfers_treasury')) ?></label>
            <select id="t_treasury" class="form-select form-select-sm">
                <option value=""><?= esc(lang('Accounting_treasuries.transfers_all_treasuries')) ?></option>
                <?php foreach ($treasuries as $treasury): ?>
                    <option value="<?= (int) $treasury->treasury_id ?>"><?= esc($treasury->name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= esc(lang('Common.search')) ?></button>
            <button type="button" id="transfer_reset" class="btn btn-outline-secondary btn-sm"><?= esc(lang('Accounting_treasuries.transfers_reset')) ?></button>
        </div>
    </div>
</form>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
