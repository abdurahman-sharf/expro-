<?php
/**
 * @var InventoryValuationReport                               $report
 * @var string|null                                            $error
 * @var array{as_of: string, empty: bool, branches: list<int>} $filters
 * @var list<object>                                           $branches
 * @var string|null                                            $scope_label
 * @var ReportScopeKind                                        $scope_kind
 * @var string                                                 $csvUrl
 * @var Closure                                                $amount
 * @var Closure                                                $quantity
 * @var Closure                                                $date
 * @var Closure                                                $ledger_url
 */

use App\Libraries\Accounting\InventoryValuationLocation;
use App\Libraries\Accounting\InventoryValuationReport;
use App\Libraries\Accounting\ReportScopeKind;

$shelves = $report->current;
$columns = $shelves ? 8 : 5;
$verdict = static function (InventoryValuationLocation $location): string {
    if ($location->accountId === null) {
        return lang('Accounting_inventory_reports.no_account');
    }

    return lang(bccomp($location->difference(), '0', 4) > 0 ? 'Accounting_inventory_reports.account_more' : 'Accounting_inventory_reports.account_less', [to_currency_no_money(ltrim($location->difference(), '-'))]);
};
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" id="export_csv" href="<?= esc($csvUrl, 'attr') ?>">
        <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_inventory_reports.csv') ?>
    </a>
    <?= view('partial/report_files', ['csvUrl' => $csvUrl, 'id' => 'export_csv']) ?>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_reports'), 'attr') ?>">
        <span class="bi bi-file-earmark-bar-graph" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.trial_balance') ?>
    </a>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_reports/inventory_valuation'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_as_of"><?= lang('Accounting_inventory_reports.as_of') ?></label>
            <input type="date" id="f_as_of" name="as_of" class="form-control form-control-sm" value="<?= esc($filters['as_of'], 'attr') ?>">
        </div>
        <fieldset class="acc-filter acc-filter-status">
            <legend class="form-label"><?= lang('Accounting_reports.show') ?></legend>
            <label class="choice"><input type="checkbox" class="form-check-input" id="f_empty" name="empty" value="1"<?= $filters['empty'] ? ' checked' : '' ?>> <?= lang('Accounting_inventory_reports.show_empty') ?></label>
        </fieldset>
        <?php if ($branches !== []): ?>
            <fieldset class="acc-filter acc-filter-status" id="f_branches">
                <legend class="form-label"><?= lang('Accounting_reports.branches') ?></legend>
                <?php foreach ($branches as $branch): ?>
                    <label class="choice"><input type="checkbox" class="form-check-input" name="branches[]" value="<?= (int) $branch->branch_id ?>"<?= in_array((int) $branch->branch_id, $filters['branches'], true) ? ' checked' : '' ?>> <?= esc($branch->code . ' - ' . $branch->name) ?><?= $branch->active ? '' : ' (' . esc(lang('Accounting_reports.branch_inactive')) . ')' ?></label>
                <?php endforeach; ?>
            </fieldset>
        <?php endif; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_reports/inventory_valuation'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
    <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_inventory_reports.as_of_hint') ?></p>
    <?php if ($branches !== []): ?>
        <p class="small text-body-secondary mb-0 mt-2"><?= lang('Accounting_reports.branches_hint') ?></p>
    <?php endif; ?>
</form>

<div class="container-fluid py-3" id="inventory_valuation">
    <h1 class="h4"><?= lang('Accounting_inventory_reports.title') ?></h1>

    <p class="text-body-secondary mb-2" id="report_period">
        <?= $report->asOf === null || $report->current
            ? lang('Accounting_inventory_reports.period_now')
            : esc(lang('Accounting_inventory_reports.period_as_of', [$date($report->asOf)])) ?>
    </p>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($scope_label !== null): ?>
        <?php if ($scope_kind === ReportScopeKind::Partial): ?>
            <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scope_label) ?></p>
        <?php else: ?>
            <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scope_label) ?></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($report->scope->isEmpty()): ?>
        <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
    <?php endif; ?>

    <p class="h5 mb-3" id="report_total"><?= lang('Accounting_inventory_reports.total_value') ?>: <?= $amount($report->value()) ?></p>

    <?php if ($report->ties()): ?>
        <p class="text-success" id="report_ties"><span class="bi bi-check-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_inventory_reports.ties_all') ?></p>
    <?php else: ?>
        <p class="alert alert-danger" id="report_differs" role="alert"><?= lang('Accounting_inventory_reports.differs_all') ?></p>
    <?php endif; ?>

    <?php if ($report->unpostedLines() > 0): ?>
        <p class="alert alert-warning" id="report_unposted" role="note"><?= lang('Accounting_inventory_reports.unposted_summary', [$report->unpostedLines()]) ?></p>
    <?php endif; ?>

    <?php if ($report->locations === []): ?>
        <p class="text-body-secondary" id="report_empty"><?= lang('Accounting_inventory_reports.empty') ?></p>
    <?php endif; ?>

    <?php foreach ($report->locations as $location): ?>
        <section class="mb-4" id="location_<?= $location->locationId ?>" data-location="<?= $location->locationId ?>" aria-labelledby="location_title_<?= $location->locationId ?>">
            <h2 class="h5" id="location_title_<?= $location->locationId ?>">
                <?= esc($location->name) ?>
                <?php if ($report->scope->choosable() && $location->branchCode !== null): ?>
                    <small class="text-body-secondary fs-6">(<bdi dir="ltr"><?= esc($location->branchCode) ?></bdi>)</small>
                <?php endif; ?>
                <?php if ($location->ties()): ?>
                    <span class="badge text-bg-success" data-status="ties"><?= lang('Accounting_inventory_reports.agrees') ?></span>
                <?php else: ?>
                    <span class="badge text-bg-danger" data-status="differs"><?= lang('Accounting_inventory_reports.differs') ?></span>
                <?php endif; ?>
            </h2>

            <div class="table-responsive">
                <table class="table table-sm acc-lines" data-location-table="<?= $location->locationId ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_inventory_reports.col_item') ?></th>
                            <th scope="col"><?= lang('Accounting_inventory_reports.col_item_number') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_inventory_reports.col_quantity') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_inventory_reports.col_average_cost') ?></th>
                            <th scope="col" class="text-end"><?= lang('Accounting_inventory_reports.col_value') ?></th>
                            <?php if ($shelves): ?>
                                <th scope="col" class="text-end"><?= lang('Accounting_inventory_reports.col_shelves') ?></th>
                                <th scope="col" class="text-end"><?= lang('Accounting_inventory_reports.col_unposted') ?></th>
                                <th scope="col" class="text-end"><?= lang('Accounting_inventory_reports.col_item_cost') ?></th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($location->lines === []): ?>
                            <tr><td colspan="<?= $columns ?>" class="text-body-secondary"><?= lang('Accounting_inventory_reports.empty_location') ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($location->lines as $line): ?>
                            <tr data-item="<?= $line->position->itemId ?>"<?= $line->hasUnpostedUnits() ? ' data-unposted="1"' : '' ?>>
                                <td><?= esc($line->position->itemName) ?></td>
                                <td><bdi dir="ltr"><?= esc($line->itemNumber ?? '') ?></bdi></td>
                                <td class="text-end"><?= $quantity($line->position->quantity) ?></td>
                                <td class="text-end"><?= $line->averageCost() === null ? '&mdash;' : $amount($line->averageCost()) ?></td>
                                <td class="text-end"><?= $amount($line->position->value) ?></td>
                                <?php if ($shelves && $line->onHand() === null): ?>
                                    <td class="text-end text-body-secondary" colspan="3">&mdash;</td>
                                <?php elseif ($shelves): ?>
                                    <td class="text-end"><?= $quantity((string) $line->onHand()) ?></td>
                                    <td class="text-end">
                                        <?php if ($line->hasUnpostedUnits()): ?>
                                            <?= $quantity((string) $line->unposted()) ?> <span class="badge text-bg-warning"><?= lang(bccomp((string) $line->unposted(), '0', 3) > 0 ? 'Accounting_inventory_reports.unposted_units' : 'Accounting_inventory_reports.unposted_negative') ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end"><?= $amount((string) $line->valueAtItemCost()) ?></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-bold" data-total="location">
                            <th scope="row" colspan="4"><?= esc(lang('Accounting_inventory_reports.location_total', [$location->name])) ?></th>
                            <td class="text-end"><?= $amount($location->value) ?></td>
                            <?php if ($shelves): ?><td colspan="3"></td><?php endif; ?>
                        </tr>
                        <tr data-total="account">
                            <th scope="row" colspan="4">
                                <?php if ($location->accountId === null): ?>
                                    <?= lang('Accounting_inventory_reports.no_account_row') ?>
                                <?php else: ?>
                                    <?= lang('Accounting_inventory_reports.account_balance') ?> <a href="<?= esc($ledger_url($location->accountId), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $location->accountCode) ?></bdi></a>
                                <?php endif; ?>
                            </th>
                            <td class="text-end"><?= $amount($location->accountBalance) ?></td>
                            <?php if ($shelves): ?><td colspan="3"></td><?php endif; ?>
                        </tr>
                        <tr data-total="difference">
                            <th scope="row" colspan="4"><?= lang('Accounting_inventory_reports.difference') ?></th>
                            <td class="text-end"><?= $amount($location->difference()) ?></td>
                            <?php if ($shelves): ?><td colspan="3"></td><?php endif; ?>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php if (! $location->ties()): ?>
                <p class="alert alert-danger small" data-verdict="differs" role="alert"><?= esc($verdict($location)) ?></p>
            <?php endif; ?>
            <?php if ($shelves && ! $location->shelvesCompared): ?>
                <p class="alert alert-secondary small" data-verdict="shelves_skipped" role="note"><?= lang('Accounting_inventory_reports.shelves_skipped') ?></p>
            <?php endif; ?>
            <?php if ($location->unpostedLines() > 0): ?>
                <p class="alert alert-warning small" data-verdict="unposted" role="note"><?= lang('Accounting_inventory_reports.unposted_note', [$location->unpostedLines()]) ?></p>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($report->stockAccountBalance !== null): ?>
        <section class="mb-4" id="stock_accounts" aria-labelledby="stock_accounts_title">
            <h2 class="h5" id="stock_accounts_title"><?= lang('Accounting_inventory_reports.stock_accounts') ?></h2>
            <div class="table-responsive">
                <table class="table table-sm acc-lines" id="stock_accounts_table">
                    <tbody>
                        <tr data-total="movements">
                            <th scope="row"><?= lang('Accounting_inventory_reports.movements_row') ?></th>
                            <td class="text-end"><?= $amount($report->value()) ?></td>
                        </tr>
                        <tr data-total="stock_account">
                            <th scope="row"><?= esc(lang('Accounting_inventory_reports.stock_account_row', [(string) $report->stockAccountCode])) ?></th>
                            <td class="text-end"><?= $amount($report->stockAccountBalance) ?></td>
                        </tr>
                        <tr data-total="stock_difference">
                            <th scope="row"><?= lang('Accounting_inventory_reports.difference') ?></th>
                            <td class="text-end"><?= $amount((string) $report->stockAccountDifference()) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <p class="small text-body-secondary" id="report_basis_note"><?= lang('Accounting_inventory_reports.basis_note') ?></p>
    <p class="small text-body-secondary" id="report_unposted_legend"><?= lang($shelves ? 'Accounting_inventory_reports.unposted_legend' : 'Accounting_inventory_reports.past_note') ?></p>
</div>

<?= view('partial/footer') ?>
