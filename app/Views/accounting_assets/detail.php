<?php
/**
 * @var object                                              $asset
 * @var list<array{date: string, amount: string, posted: bool}> $schedule
 * @var list<object>                                        $depreciation
 * @var list<object>                                        $operations
 * @var bool                                                $can_manage
 * @var bool                                                $can_dispose
 * @var Closure                                             $amount
 * @var Closure                                             $date
 * @var string|null                                         $notice
 */

use App\Libraries\Assets\AssetStatus;

$status   = AssetStatus::from((string) $asset->status);
$id       = (int) $asset->asset_id;
$modal    = static fn (string $path, string $label, string $title, string $class = 'btn-outline-secondary'): string => '<button type="button" class="btn btn-sm modal-dlg ' . $class . '" data-btn-submit="' . esc($label, 'attr') . '" data-href="' . esc($path, 'attr') . '" title="' . esc($title, 'attr') . '">' . esc($label) . '</button>';
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_assets/tabs', ['active' => 'assets']) ?>

<div class="container-fluid py-3" id="asset_detail">
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2 print_hide">
        <h1 class="h4 me-auto mb-0"><bdi dir="ltr"><?= esc($asset->code) ?></bdi> <?= esc($asset->name) ?> <span class="badge text-bg-secondary"><?= lang('Accounting_assets.status_' . $status->value) ?></span></h1>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_assets'), 'attr') ?>"><?= lang('Accounting_assets.back') ?></a>
        <?php if ($can_manage && $status->canBeDisposed()): ?>
            <a class="btn btn-outline-primary btn-sm" id="edit_asset" href="<?= esc(base_url('accounting_assets/form/' . $id), 'attr') ?>"><?= lang('Accounting_assets.edit') ?></a>
            <?php if ($status === AssetStatus::Active || $status === AssetStatus::Consumed): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm asset-post" id="deactivate_asset" data-url="<?= esc(base_url('accounting_assets/status/' . $id . '/0'), 'attr') ?>"><?= lang('Accounting_assets.deactivate') ?></button>
            <?php elseif ($status === AssetStatus::Inactive): ?>
                <button type="button" class="btn btn-outline-secondary btn-sm asset-post" id="activate_asset" data-url="<?= esc(base_url('accounting_assets/status/' . $id . '/1'), 'attr') ?>"><?= lang('Accounting_assets.activate') ?></button>
            <?php endif; ?>
            <button type="button" class="btn btn-outline-danger btn-sm asset-post" id="delete_asset" data-confirm="<?= esc(lang('Accounting_assets.delete_confirm'), 'attr') ?>" data-url="<?= esc(base_url('accounting_assets/delete/' . $id), 'attr') ?>"><?= lang('Accounting_assets.delete') ?></button>
        <?php endif; ?>
        <?php if ($can_dispose): ?>
            <?= $modal('accounting_assets/dispose_form/sale/' . $id, lang('Accounting_assets.sell'), lang('Accounting_assets.sell_title'), 'btn-outline-primary') ?>
            <?= $modal('accounting_assets/dispose_form/write_off/' . $id, lang('Accounting_assets.write_off'), lang('Accounting_assets.write_off_title'), 'btn-outline-danger') ?>
        <?php endif; ?>
        <?php if ($can_manage && $asset->method === 'units' && $status->canBeDisposed()): ?>
            <?= $modal('accounting_assets/usage_form/' . $id, lang('Accounting_assets.usage'), lang('Accounting_assets.usage_title')) ?>
        <?php endif; ?>
    </div>

    <dl class="row" id="asset_terms">
        <dt class="col-sm-3"><?= lang('Accounting_assets.column_branch') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc($asset->branch_code) ?></bdi> <?= esc($asset->branch_name) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.column_category') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $asset->category_name) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.column_purchase_date') ?></dt>
        <dd class="col-sm-9"><?= esc($date((string) $asset->purchase_date)) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.column_service_date') ?></dt>
        <dd class="col-sm-9"><?= esc($date((string) $asset->in_service_date)) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.column_cost') ?></dt>
        <dd class="col-sm-9"><?= $amount((string) $asset->cost) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.field_salvage') ?></dt>
        <dd class="col-sm-9"><?= $amount((string) $asset->salvage) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.column_accumulated') ?></dt>
        <dd class="col-sm-9"><?= $amount((string) $asset->accumulated) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.column_book_value') ?></dt>
        <dd class="col-sm-9"><?= $amount((string) $asset->book_value) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.field_method') ?></dt>
        <dd class="col-sm-9"><?= lang('Accounting_assets.method_' . $asset->method) ?></dd>
        <?php if ($asset->method === 'fixed' || $asset->method === 'declining'): ?>
            <dt class="col-sm-3"><?= lang('Accounting_assets.field_period') ?></dt>
            <dd class="col-sm-9"><?= (int) $asset->period_length ?> <?= lang('Accounting_assets.interval_' . $asset->period_interval) ?></dd>
            <dt class="col-sm-3"><?= lang($asset->method === 'fixed' ? 'Accounting_assets.field_amount' : 'Accounting_assets.field_percentage') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc(rtrim(rtrim((string) ($asset->method === 'fixed' ? $asset->amount_per_period : $asset->percentage), '0'), '.')) ?></bdi></dd>
            <dt class="col-sm-3"><?= lang('Accounting_assets.field_first_date') ?></dt>
            <dd class="col-sm-9"><?= esc($date((string) $asset->first_depreciation_date)) ?></dd>
        <?php elseif ($asset->method === 'units'): ?>
            <dt class="col-sm-3"><?= lang('Accounting_assets.field_cost_per_unit') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc(rtrim(rtrim((string) $asset->cost_per_unit, '0'), '.')) ?></bdi> <?= esc((string) $asset->unit_name) ?></dd>
        <?php endif; ?>
        <dt class="col-sm-3"><?= lang('Accounting_assets.field_funding') ?></dt>
        <dd class="col-sm-9"><?= lang('Accounting_assets.funding_' . $asset->funding) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_assets.field_cost_account') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $asset->cost_account_code) ?></bdi> <?= esc((string) $asset->cost_account_name) ?></dd>
        <?php if ($asset->accumulated_account_id !== null): ?>
            <dt class="col-sm-3"><?= lang('Accounting_assets.field_accumulated_account') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $asset->accumulated_account_code) ?></bdi> <?= esc((string) $asset->accumulated_account_name) ?></dd>
            <dt class="col-sm-3"><?= lang('Accounting_assets.field_expense_account') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $asset->expense_account_code) ?></bdi> <?= esc((string) $asset->expense_account_name) ?></dd>
        <?php endif; ?>
        <?php if ((string) $asset->description !== ''): ?>
            <dt class="col-sm-3"><?= lang('Accounting_assets.field_description') ?></dt>
            <dd class="col-sm-9"><?= esc((string) $asset->description) ?></dd>
        <?php endif; ?>
    </dl>

    <?php if ($asset->units !== []): ?>
        <section class="mb-3" id="asset_units_list">
            <h2 class="h6"><?= lang('Accounting_assets.units_title') ?></h2>
            <div class="acc-lines-wrap">
                <table class="table table-sm acc-lines">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_assets.unit_serial') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.unit_barcode') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.unit_used_by') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.unit_location') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($asset->units as $unit): ?>
                            <tr>
                                <td data-label="<?= esc(lang('Accounting_assets.unit_serial'), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $unit->serial_number) ?></bdi></td>
                                <td data-label="<?= esc(lang('Accounting_assets.unit_barcode'), 'attr') ?>"><bdi dir="ltr"><?= esc((string) $unit->barcode) ?></bdi></td>
                                <td data-label="<?= esc(lang('Accounting_assets.unit_used_by'), 'attr') ?>"><?= lang('Accounting_assets.used_by_' . $unit->used_by) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.unit_location'), 'attr') ?>"><?= esc((string) $unit->physical_location) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($schedule !== []): ?>
        <section class="mb-3" id="asset_schedule">
            <h2 class="h6"><?= lang('Accounting_assets.schedule_title') ?></h2>
            <div class="acc-lines-wrap">
                <table class="table table-sm acc-lines">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_assets.schedule_date') ?></th>
                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.column_depreciation') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.schedule_state') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedule as $event): ?>
                            <tr>
                                <td data-label="<?= esc(lang('Accounting_assets.schedule_date'), 'attr') ?>"><?= esc($date($event['date'])) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.column_depreciation'), 'attr') ?>" class="acc-amount"><?= $amount($event['amount']) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.schedule_state'), 'attr') ?>"><?= lang($event['posted'] ? 'Accounting_assets.schedule_posted' : 'Accounting_assets.schedule_due') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-body-secondary small"><?= lang('Accounting_assets.schedule_note') ?></p>
        </section>
    <?php endif; ?>

    <?php if ($depreciation !== []): ?>
        <section class="mb-3" id="asset_history">
            <h2 class="h6"><?= lang('Accounting_assets.history_title') ?></h2>
            <div class="acc-lines-wrap">
                <table class="table table-sm acc-lines">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_assets.history_month') ?></th>
                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.column_depreciation') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.history_state') ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($depreciation as $line): ?>
                            <tr>
                                <td data-label="<?= esc(lang('Accounting_assets.history_month'), 'attr') ?>"><?= esc(substr((string) $line->period_start, 0, 7)) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.column_depreciation'), 'attr') ?>" class="acc-amount"><?= $amount((string) $line->amount) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.history_state'), 'attr') ?>"><?= lang('Accounting_assets.run_' . $line->run_status) ?></td>
                                <td><a href="<?= esc(base_url('accounting_journals/view/' . (int) $line->journal_id), 'attr') ?>"><?= lang('Accounting_assets.journal') ?></a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($operations !== []): ?>
        <section class="mb-3" id="asset_operations">
            <h2 class="h6"><?= lang('Accounting_assets.operations_title') ?></h2>
            <div class="acc-lines-wrap">
                <table class="table table-sm acc-lines">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_assets.operation_kind') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.operation_date') ?></th>
                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.operation_proceeds') ?></th>
                            <th scope="col" class="acc-amount"><?= lang('Accounting_assets.operation_result') ?></th>
                            <th scope="col"><?= lang('Accounting_assets.history_state') ?></th>
                            <th scope="col"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operations as $operation): ?>
                            <tr>
                                <td data-label="<?= esc(lang('Accounting_assets.operation_kind'), 'attr') ?>"><?= lang('Accounting_assets.kind_' . $operation->kind) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.operation_date'), 'attr') ?>"><?= esc($date((string) $operation->operation_date)) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.operation_proceeds'), 'attr') ?>" class="acc-amount"><?= $amount((string) $operation->proceeds) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.operation_result'), 'attr') ?>" class="acc-amount"><?= $amount((string) $operation->result_amount) ?></td>
                                <td data-label="<?= esc(lang('Accounting_assets.history_state'), 'attr') ?>"><?= lang('Accounting_assets.run_' . $operation->status) ?></td>
                                <td class="print_hide">
                                    <a href="<?= esc(base_url('accounting_journals/view/' . (int) $operation->journal_id), 'attr') ?>"><?= lang('Accounting_assets.journal') ?></a>
                                    <?php if ($can_manage && $operation->status === 'active'): ?>
                                        <?= $modal('accounting_assets/dispose_reverse_form/' . (int) $operation->operation_id, lang('Accounting_assets.reverse'), lang('Accounting_assets.reverse_disposal_title')) ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('.asset-post').on('click', function() {
            const button = $(this);
            const message = button.data('confirm');

            if (message && !window.confirm(message)) {
                return;
            }

            $.post(button.data('url'), {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.href = response.redirect;
                }
            }, 'json');
        });
    });
</script>

<?= view('partial/footer') ?>
