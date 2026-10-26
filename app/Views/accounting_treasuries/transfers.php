<?php
/**
 * @var string $table_headers
 * @var bool $can_manage
 * @var list<array{code: string, receivable: string, payable: string}> $branch_balances
 * @var string|null $branch_unmatched
 * @var array $config
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
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_treasuries.empty_transfers')) . '</p>', $jsonFlags) ?>;
            }
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries"><span class="bi bi-wallet2" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.treasuries') ?></a>
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

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
