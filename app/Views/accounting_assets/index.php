<?php
/**
 * @var AssetsReportResult                                    $result
 * @var AssetsFilter                                          $filter
 * @var ReportScope                                           $scope
 * @var list<object>                                          $branches
 * @var string|null                                           $scope_label
 * @var list<object>                                          $employees
 * @var list<object>                                          $accounts
 * @var list<object>                                          $categories
 * @var list<AssetStatus>                                     $statuses
 * @var bool                                                  $can_manage
 * @var string                                                $csv_url
 * @var Closure                                               $amount
 * @var Closure                                               $date
 * @var string|null                                           $notice
 */

use App\Libraries\Accounting\ReportScope;
use App\Libraries\Assets\AssetsFilter;
use App\Libraries\Assets\AssetsReportResult;
use App\Libraries\Assets\AssetStatus;

$chosen = array_map(static fn (AssetStatus $status): string => $status->value, $filter->statuses);
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_assets/tabs', ['active' => 'assets']) ?>

<div class="container-fluid py-3" id="assets_page">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h1 class="h4 me-auto mb-0"><?= lang('Accounting_assets.title') ?></h1>
        <?php if ($can_manage): ?>
            <a class="btn btn-primary btn-sm" id="new_asset" href="<?= esc(base_url('accounting_assets/form'), 'attr') ?>"><span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_assets.new') ?></a>
        <?php endif; ?>
    </div>
    <p class="text-body-secondary"><?= lang('Accounting_assets.intro') ?></p>

    <form id="assets_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_assets'), 'attr') ?>">
        <div class="acc-filter-row">
            <?php if ($branches !== []): ?>
                <div class="acc-filter">
                    <label class="form-label" for="f_branches"><?= lang('Accounting_assets.filter_branches') ?></label>
                    <select name="branches[]" id="f_branches" class="form-select form-select-sm" multiple>
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch->branch_id ?>"<?= in_array((int) $branch->branch_id, $scope->ids, true) ? ' selected' : '' ?>><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="acc-filter">
                <label class="form-label" for="f_employee"><?= lang('Accounting_assets.filter_employee') ?></label>
                <select name="employee" id="f_employee" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_assets.all') ?></option>
                    <?php foreach ($employees as $employee): ?>
                        <option value="<?= (int) $employee->person_id ?>"<?= (int) $employee->person_id === $filter->employeeId ? ' selected' : '' ?>><?= esc($employee->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_purchase_from"><?= lang('Accounting_assets.filter_purchase_from') ?></label>
                <input type="date" name="purchase_from" id="f_purchase_from" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc((string) $filter->purchaseFrom, 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_purchase_to"><?= lang('Accounting_assets.filter_purchase_to') ?></label>
                <input type="date" name="purchase_to" id="f_purchase_to" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc((string) $filter->purchaseTo, 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_depreciation_from"><?= lang('Accounting_assets.filter_depreciation_from') ?></label>
                <input type="date" name="depreciation_from" id="f_depreciation_from" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc((string) $filter->depreciationFrom, 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_depreciation_to"><?= lang('Accounting_assets.filter_depreciation_to') ?></label>
                <input type="date" name="depreciation_to" id="f_depreciation_to" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc((string) $filter->depreciationTo, 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_as_of"><?= lang('Accounting_assets.as_of') ?></label>
                <input type="date" name="as_of" id="f_as_of" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($result->asOf, 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_account"><?= lang('Accounting_assets.filter_account') ?></label>
                <select name="account" id="f_account" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_assets.all') ?></option>
                    <?php foreach ($accounts as $account): ?>
                        <option value="<?= (int) $account->account_id ?>"<?= (int) $account->account_id === $filter->accountId ? ' selected' : '' ?>><?= esc($account->code . ' ' . $account->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_category"><?= lang('Accounting_assets.filter_category') ?></label>
                <select name="category" id="f_category" class="form-select form-select-sm">
                    <option value=""><?= lang('Accounting_assets.all') ?></option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category->category_id ?>"<?= (int) $category->category_id === $filter->categoryId ? ' selected' : '' ?>><?= esc($category->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_group"><?= lang('Accounting_assets.filter_group') ?></label>
                <select name="group" id="f_group" class="form-select form-select-sm">
                    <?php foreach (AssetsFilter::GROUPS as $group): ?>
                        <option value="<?= esc($group, 'attr') ?>"<?= $group === $filter->group ? ' selected' : '' ?>><?= lang('Accounting_assets.group_' . $group) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <fieldset class="acc-filter-row" id="f_status">
            <legend class="form-label"><?= lang('Accounting_assets.filter_status') ?></legend>
            <?php foreach ($statuses as $status): ?>
                <div class="form-check form-check-inline">
                    <input type="checkbox" class="form-check-input" name="status[]" id="f_status_<?= esc($status->value, 'attr') ?>" value="<?= esc($status->value, 'attr') ?>"<?= in_array($status->value, $chosen, true) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="f_status_<?= esc($status->value, 'attr') ?>"><?= lang('Accounting_assets.status_' . $status->value) ?></label>
                </div>
            <?php endforeach; ?>
        </fieldset>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_assets.show') ?></button>
        </div>
    </form>

    <?php if ($scope_label !== null): ?>
        <p class="text-body-secondary small" id="assets_scope"><?= esc($scope_label) ?></p>
    <?php endif; ?>

    <?php if ($result->tie !== []): ?>
        <section id="assets_tie" class="mb-3" aria-label="<?= esc(lang('Accounting_assets.tie_title'), 'attr') ?>">
            <h2 class="h6"><?= lang('Accounting_assets.tie_title') ?>
                <span class="badge <?= $result->ties() ? 'text-bg-success' : 'text-bg-danger' ?>"><?= lang($result->ties() ? 'Accounting_assets.tie_ok' : 'Accounting_assets.tie_off') ?></span>
            </h2>
            <div class="acc-lines-wrap">
                <table class="table table-sm acc-lines">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_assets.tie_account') ?></th>
                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.tie_register') ?></th>
                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.tie_ledger') ?></th>
                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.tie_difference') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($result->tie as $tie): ?>
                            <tr class="<?= $tie->ties() ? '' : 'table-danger' ?>">
                                <td data-label="<?= esc(lang('Accounting_assets.tie_account'), 'attr') ?>"><?= esc(lang('Accounting_assets.tie_' . $tie->kind)) ?>: <bdi dir="ltr"><?= esc($tie->code) ?></bdi> <?= esc($tie->name) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.tie_register'), 'attr') ?>" class="acc-amount"><?= $amount($tie->register) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.tie_ledger'), 'attr') ?>" class="acc-amount"><?= $amount($tie->ledger) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.tie_difference'), 'attr') ?>" class="acc-amount"><?= $amount($tie->difference()) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($result->count() === 0): ?>
        <p class="alert alert-info" id="assets_empty"><?= lang('Accounting_assets.empty') ?></p>
    <?php else: ?>
        <?php foreach ($result->groups as $group): ?>
            <section class="assets-group mb-4" id="assets_group_<?= esc($group->key === '' ? 'all' : $group->key, 'attr') ?>">
                <?php if ($group->key !== ''): ?>
                    <h2 class="h6"><?= esc($filter->group === 'status' ? lang('Accounting_assets.status_' . $group->key) : $date($group->key)) ?></h2>
                <?php endif; ?>
                <div class="acc-lines-wrap">
                    <table class="table table-sm acc-lines" id="assets_table">
                        <thead>
                            <tr>
                                <th scope="col"><?= lang('Accounting_assets.column_code') ?></th>
                                <th scope="col"><?= lang('Accounting_assets.column_name') ?></th>
                                <th scope="col"><?= lang('Accounting_assets.column_branch') ?></th>
                                <th scope="col"><?= lang('Accounting_assets.column_category') ?></th>
                                <th scope="col"><?= lang('Accounting_assets.column_status') ?></th>
                                <th scope="col"><?= lang('Accounting_assets.column_purchase_date') ?></th>
                                <th scope="col"><?= lang('Accounting_assets.column_service_date') ?></th>
                                <th scope="col" class="acc-amount"><?= lang('Accounting_assets.column_cost') ?></th>
                                <th scope="col" class="acc-amount"><?= lang('Accounting_assets.column_accumulated') ?></th>
                                <th scope="col" class="acc-amount"><?= lang('Accounting_assets.column_depreciation') ?></th>
                                <th scope="col" class="acc-amount"><?= lang('Accounting_assets.column_book_value') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($group->rows as $row): ?>
                                <tr class="asset-row<?= $row->onTheBooks ? '' : ' text-body-secondary' ?>" data-asset="<?= $row->assetId ?>">
                                    <td data-label="<?= esc(lang('Accounting_assets.column_code'), 'attr') ?>"><a href="<?= esc(base_url('accounting_assets/view/' . $row->assetId), 'attr') ?>"><bdi dir="ltr"><?= esc($row->code) ?></bdi></a></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_name'), 'attr') ?>"><?= esc($row->name) ?></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_branch'), 'attr') ?>"><bdi dir="ltr"><?= esc($row->branchCode) ?></bdi></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_category'), 'attr') ?>"><?= esc((string) $row->category) ?></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_status'), 'attr') ?>"><span class="badge text-bg-secondary"><?= lang('Accounting_assets.status_' . $row->status->value) ?></span></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_purchase_date'), 'attr') ?>"><?= esc($date($row->purchaseDate)) ?></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_service_date'), 'attr') ?>"><?= esc($date($row->inServiceDate)) ?></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_cost'), 'attr') ?>" class="acc-amount"><?= $amount($row->cost) ?></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_accumulated'), 'attr') ?>" class="acc-amount"><?= $amount($row->accumulated) ?></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_depreciation'), 'attr') ?>" class="acc-amount"><?= $amount($row->depreciation) ?></td>
                                    <td data-label="<?= esc(lang('Accounting_assets.column_book_value'), 'attr') ?>" class="acc-amount"><?= $amount($row->bookValue) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <?php if ($group->key !== ''): ?>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <th scope="row" colspan="7"><?= lang('Accounting_assets.group_total') ?></th>
                                    <td class="acc-amount"><?= $amount($group->cost) ?></td>
                                    <td class="acc-amount"><?= $amount($group->accumulated) ?></td>
                                    <td class="acc-amount"><?= $amount($group->depreciation) ?></td>
                                    <td class="acc-amount"><?= $amount($group->bookValue) ?></td>
                                </tr>
                            </tfoot>
                        <?php endif; ?>
                    </table>
                </div>
            </section>
        <?php endforeach; ?>
        <dl class="row mb-3" id="assets_totals">
            <dt class="col-sm-3"><?= lang('Accounting_assets.total_cost') ?></dt>
            <dd class="col-sm-9"><?= $amount($result->cost) ?></dd>
            <dt class="col-sm-3"><?= lang('Accounting_assets.total_accumulated') ?></dt>
            <dd class="col-sm-9"><?= $amount($result->accumulated) ?></dd>
            <dt class="col-sm-3"><?= lang('Accounting_assets.total_depreciation') ?></dt>
            <dd class="col-sm-9"><?= $amount($result->depreciation) ?></dd>
            <dt class="col-sm-3"><?= lang('Accounting_assets.total_book_value') ?></dt>
            <dd class="col-sm-9"><?= $amount($result->bookValue) ?></dd>
        </dl>
        <p class="text-body-secondary small"><?= lang('Accounting_assets.totals_note') ?></p>
    <?php endif; ?>

    <div class="print_hide mb-3 clearfix" id="assets_files">
        <a class="btn btn-outline-secondary btn-sm float-end" id="assets_csv" href="<?= esc($csv_url, 'attr') ?>"><span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?></a>
        <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()"><span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?></button>
    </div>
</div>

<?= view('partial/footer') ?>
