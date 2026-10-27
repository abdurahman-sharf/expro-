<?php
/**
 * @var string                     $tableHeaders
 * @var list<array<string, mixed>> $rows
 * @var list<array<string, mixed>> $unattributed
 * @var string|null                $difference
 * @var string|null                $error
 * @var string                     $asOf
 * @var list<object>               $branches
 * @var list<int>                  $selected
 * @var string|null                $scopeLabel
 * @var bool                       $scopeEmpty
 * @var bool                       $scopePartial
 * @var bool                       $canSettle
 * @var array                      $config
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?= view('partial/bootstrap_tables_locale') ?>

        table_support.init({
            resource: 'accounting_treasuries/inter_branch/settlements',
            headers: <?= $tableHeaders ?>,
            pageSize: <?= $config['lines_per_page'] ?>,
            uniqueId: 'settlement_id',
            cardTitle: ['settlement_id'],
            formatNoMatches: function() {
                return <?= json_encode('<p class="mb-0">' . esc(lang('Accounting_treasuries.settlements_empty')) . '</p>', $jsonFlags) ?>;
            }
        });

        window.reloadPairs = function() {
            $('#pairs_holder').load('accounting_treasuries/inter_branch/pairs' + window.location.search);
        };
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries"><span class="bi bi-wallet2" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.treasuries') ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="accounting_treasuries/transfers"><span class="bi bi-arrow-left-right" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.transfers') ?></a>
</div>

<form id="pair_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_treasuries/inter_branch'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_as_of"><?= lang('Accounting_treasuries.as_of') ?></label>
            <input type="date" id="f_as_of" name="as_of" class="form-control form-control-sm" value="<?= esc($asOf, 'attr') ?>">
        </div>
        <?php if ($branches !== []): ?>
            <fieldset class="acc-filter acc-filter-status" id="f_branches">
                <legend class="form-label"><?= lang('Accounting_reports.branches') ?></legend>
                <?php foreach ($branches as $branch): ?>
                    <label class="choice"><input type="checkbox" class="form-check-input" name="branches[]" value="<?= (int) $branch->branch_id ?>"<?= in_array((int) $branch->branch_id, $selected, true) ? ' checked' : '' ?>> <?= esc($branch->code . ' - ' . $branch->name) ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/inter_branch'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
</form>

<div class="container-fluid py-3">
    <h1 class="h4"><?= lang('Accounting_treasuries.inter_branch') ?></h1>
    <p class="text-body-secondary" id="inter_branch_note"><?= esc(lang('Accounting_treasuries.inter_branch_note')) ?></p>

    <div id="pairs_holder">
        <?= view('accounting_treasuries/inter_branch_pairs', compact('rows', 'unattributed', 'difference', 'error', 'asOf', 'scopeLabel', 'scopeEmpty', 'scopePartial', 'canSettle')) ?>
    </div>

    <h2 class="h5 mt-4"><?= lang('Accounting_treasuries.settlements_title') ?></h2>
</div>

<div id="toolbar"></div>

<div id="table_holder">
    <table id="table"></table>
</div>

<?= view('partial/footer') ?>
