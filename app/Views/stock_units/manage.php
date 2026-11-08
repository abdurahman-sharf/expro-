<?php

use App\Libraries\Stock\UnitTemplate;

/**
 * @var list<UnitTemplate>                                                                                                     $templates
 * @var array<int, bool>                                                                                                       $used
 * @var array{units: bool, bundles: bool, tracking: bool, expired_refused: bool, negative_stock: string}                       $settings
 * @var bool                                                                                                                   $canManage
 * @var bool                                                                                                                   $canTrack
 * @var bool                                                                                                                   $canSettings
 * @var array{id: int, name: string, template: ?int, tracking: string, barcodes: list<object>, units: array<int, string>}|null $item
 * @var string                                                                                                                 $itemSearch
 */
$templateChoices = ['' => lang('Stock_units.no_template')];

foreach ($templates as $template) {
    if ($template->active || ($item !== null && $item['template'] === $template->templateId)) {
        $templateChoices[(string) $template->templateId] = $template->name . ' v' . $template->version;
    }
}
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $(document).on('click', '.units-dialog', function() {
            dialog_support.init(this);
        });

        $('.units-ajax').on('submit', function(event) {
            event.preventDefault();
            $.ajax({
                type: 'POST',
                url: $(this).attr('action'),
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.reload();
                    }
                }
            });
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Stock_units.title') ?></h1>
    <?php if ($canManage && $settings['units']): ?>
        <button class="btn btn-primary btn-sm modal-dlg units-dialog" id="template_new" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_units/templateForm/-1'), 'attr') ?>" title="<?= lang('Stock_units.new_template') ?>">
            <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Stock_units.new_template') ?>
        </button>
    <?php endif; ?>
</div>

<?php if ($canSettings): ?>
    <?= form_open('stock_units/settings', ['id' => 'stock_options_form', 'class' => 'app-form card units-ajax mb-3']) ?>
        <div class="card-body">
            <h2 class="h6"><?= lang('Stock_units.settings') ?></h2>
            <p class="small text-body-secondary"><?= lang('Stock_units.settings_note') ?></p>
            <div class="form-grid">
                <?php foreach (['units' => 'setting_units', 'bundles' => 'setting_bundles', 'tracking' => 'setting_tracking', 'expired_refused' => 'setting_expired_refuse'] as $flag => $label): ?>
                    <div class="field">
                        <label class="form-check-label" for="opt_<?= esc($flag, 'attr') ?>"><?= lang("Stock_units.{$label}") ?></label>
                        <div class="field-control">
                            <input type="hidden" name="<?= esc($flag, 'attr') ?>" value="0">
                            <input type="checkbox" class="form-check-input" id="opt_<?= esc($flag, 'attr') ?>" name="<?= esc($flag, 'attr') ?>" value="1"<?= $settings[$flag] ? ' checked' : '' ?>>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="field span-all">
                    <label class="form-label" for="opt_negative_stock"><?= lang('Stock_units.setting_negative_stock') ?></label>
                    <div class="field-control">
                        <?= form_dropdown('negative_stock', ['allow' => lang('Stock_units.negative_allow'), 'refuse' => lang('Stock_units.negative_refuse'), 'refuse_below_available' => lang('Stock_units.negative_refuse_below_available')], $settings['negative_stock'], ['class' => 'form-select form-select-sm', 'id' => 'opt_negative_stock']) ?>
                    </div>
                </div>
            </div>
            <p class="small text-body-secondary mt-2 mb-2"><?= lang('Stock_units.bundles_note') ?></p>
            <button type="submit" class="btn btn-primary btn-sm" id="stock_options_submit"><?= lang('Common.submit') ?></button>
        </div>
    <?= form_close() ?>
<?php endif; ?>

<?php if ($settings['units']): ?>
    <h2 class="h6"><?= lang('Stock_units.templates') ?></h2>
    <?php if ($templates === []): ?>
        <p class="text-body-secondary"><?= lang('Stock_units.no_templates') ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm" id="templates_table">
                <thead>
                    <tr>
                        <th><?= lang('Stock_units.template_name') ?></th>
                        <th><?= lang('Stock_units.version') ?></th>
                        <th><?= lang('Stock_units.main_unit_name') ?></th>
                        <th><?= lang('Stock_units.larger_units') ?></th>
                        <th><?= lang('Stock_units.active') ?></th>
                        <th><?= lang('Stock_units.used') ?></th>
                        <th><span class="visually-hidden"><?= lang('Stock_units.actions') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $template): ?>
                        <tr>
                            <td><?= esc($template->name) ?></td>
                            <td><?= (int) $template->version ?></td>
                            <td><?= esc($template->mainUnitName) ?> (<?= esc($template->mainSmallName) ?>)</td>
                            <td>
                                <?php foreach ($template->units as $unit): ?>
                                    <span class="d-block"><?= esc($unit->name) ?> &times; <bdi dir="ltr"><?= esc(rtrim(rtrim($unit->factor, '0'), '.')) ?></bdi> (<?= esc($unit->smallName) ?>)</span>
                                <?php endforeach; ?>
                            </td>
                            <td><?= $template->active ? '<span class="bi bi-check-lg" aria-hidden="true"></span>' : '' ?><span class="visually-hidden"><?= lang($template->active ? 'Common.yes' : 'Common.no') ?></span></td>
                            <td><?= ($used[$template->templateId] ?? false) ? '<span class="bi bi-lock" aria-hidden="true"></span>' : '' ?><span class="visually-hidden"><?= lang(($used[$template->templateId] ?? false) ? 'Common.yes' : 'Common.no') ?></span></td>
                            <td>
                                <?php if ($canManage): ?>
                                    <button class="btn btn-outline-secondary btn-sm modal-dlg units-dialog" data-btn-submit="<?= lang('Common.submit') ?>" data-href="<?= esc(base_url('stock_units/templateForm/' . $template->templateId), 'attr') ?>" title="<?= lang('Common.edit') ?>"><span class="bi bi-pencil" aria-hidden="true"></span></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p class="small text-body-secondary"><?= lang('Stock_units.update_all_costs_note') ?></p>
<?php endif; ?>

<h2 class="h6 mt-4"><?= lang('Stock_units.item_profile') ?></h2>
<?= form_open('stock_units', ['method' => 'get', 'class' => 'app-form mb-3', 'id' => 'item_find_form']) ?>
    <div class="input-group input-group-sm" style="max-width: 28rem">
        <input type="text" name="item" class="form-control" value="<?= esc($itemSearch, 'attr') ?>" aria-label="<?= esc(lang('Stock_units.find_item'), 'attr') ?>" placeholder="<?= esc(lang('Stock_units.find_item'), 'attr') ?>">
        <button type="submit" class="btn btn-outline-primary"><?= lang('Stock_units.open') ?></button>
    </div>
<?= form_close() ?>

<?php if ($itemSearch !== '' && $item === null): ?>
    <p class="text-danger"><?= lang('Stock_units.item_not_found') ?></p>
<?php endif; ?>

<?php if ($item !== null): ?>
    <h3 class="h6"><?= esc($item['name']) ?></h3>
    <?= form_open('stock_units/saveItem/' . $item['id'], ['id' => 'item_profile_form', 'class' => 'app-form card units-ajax mb-3']) ?>
        <div class="card-body">
            <div class="form-grid">
                <div class="field">
                    <label class="form-label" for="unit_template_id"><?= lang('Stock_units.unit_template') ?></label>
                    <div class="field-control"><?= form_dropdown('unit_template_id', $templateChoices, (string) ($item['template'] ?? ''), ['class' => 'form-select form-select-sm', 'id' => 'unit_template_id'] + ($canManage && $settings['units'] ? [] : ['disabled' => 'disabled'])) ?></div>
                </div>
                <div class="field">
                    <label class="form-label" for="tracking_type"><?= lang('Stock_units.tracking_type') ?></label>
                    <div class="field-control"><?= form_dropdown('tracking_type', ['none' => lang('Stock_units.tracking_none'), 'serial' => lang('Stock_units.tracking_serial'), 'batch' => lang('Stock_units.tracking_batch')], $item['tracking'], ['class' => 'form-select form-select-sm', 'id' => 'tracking_type'] + ($canTrack && $settings['tracking'] ? [] : ['disabled' => 'disabled'])) ?></div>
                </div>
            </div>
            <p class="small text-body-secondary mt-2"><?= lang('Stock_units.tracking_note') ?></p>
            <button type="submit" class="btn btn-primary btn-sm" id="item_profile_submit"><?= lang('Common.submit') ?></button>
        </div>
    <?= form_close() ?>

    <h3 class="h6"><?= lang('Stock_units.barcodes') ?></h3>
    <ul class="list-unstyled">
        <?php foreach ($item['barcodes'] as $barcode): ?>
            <li class="mb-1">
                <bdi dir="ltr"><?= esc($barcode->barcode) ?></bdi>
                <span class="text-body-secondary">(<?= $barcode->unit_id === null ? lang('Stock_units.base_unit') : esc($item['units'][(int) $barcode->unit_id] ?? '') ?>)</span>
                <?php if ($canManage): ?>
                    <?= form_open('stock_units/removeBarcode/' . (int) $barcode->barcode_id, ['class' => 'd-inline units-ajax']) ?>
                        <button type="submit" class="btn btn-link btn-sm p-0"><?= lang('Stock_units.remove') ?></button>
                    <?= form_close() ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($canManage): ?>
        <?= form_open('stock_units/addBarcode/' . $item['id'], ['id' => 'barcode_form', 'class' => 'app-form units-ajax']) ?>
            <div class="input-group input-group-sm" style="max-width: 36rem">
                <input type="text" name="barcode" class="form-control" maxlength="255" aria-label="<?= esc(lang('Stock_units.barcode'), 'attr') ?>" placeholder="<?= esc(lang('Stock_units.barcode'), 'attr') ?>" dir="ltr">
                <?= form_dropdown('unit_id', ['' => lang('Stock_units.base_unit')] + array_map('strval', $item['units']), '', ['class' => 'form-select form-select-sm', 'aria-label' => lang('Stock_units.unit')]) ?>
                <button type="submit" class="btn btn-outline-primary"><?= lang('Stock_units.add_barcode') ?></button>
            </div>
        <?= form_close() ?>
    <?php endif; ?>
<?php endif; ?>

<?= view('partial/footer') ?>
