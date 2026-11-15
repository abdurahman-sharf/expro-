<?php
/**
 * @var array $stock_locations
 */
?>

<?php
$i = 0;

foreach ($stock_locations as $location => $location_data) {
    $location_id   = $location_data['location_id'];
    $location_name = $location_data['location_name'];
    $i++;
    ?>

    <div class="row mb-3 repeat-row<?= $location_data['deleted'] ? ' d-none' : '' ?>">
        <?= form_label(lang('Config.stock_location') . " {$i}", "stock_location_{$i}", ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
        <div class="col-7 col-md-4 col-lg-3">
            <?php $form_data = [
                'name'  => "stock_location[{$location_id}]",
                'id'    => "stock_location[{$location_id}]",
                'class' => 'stock_location valid_chars form-control form-control-sm required',
                'value' => $location_name,
            ];
    $location_data['deleted'] && $form_data['disabled'] = 'disabled';
    echo form_input($form_data);
    ?>
        </div>
        <div class="col-12 col-md-4 location-state d-flex align-items-center gap-3">
            <?= form_dropdown(
                "location_status[{$location_id}]",
                ['1' => lang('Config.location_active'), '0' => lang('Config.location_inactive')],
                (string) (int) ($location_data['status'] ?? 1),
                ['class' => 'form-select form-select-sm location-status', 'aria-label' => lang('Config.location_status')] + ($location_data['deleted'] ? ['disabled' => 'disabled'] : []),
            ) ?>
            <label class="form-check-label text-nowrap">
                <?= form_radio([
                    'name'    => 'location_primary',
                    'value'   => $location_id,
                    'checked' => (int) ($location_data['is_primary'] ?? 0) === 1,
                    'class'   => 'form-check-input location-primary',
                ] + ($location_data['deleted'] || (int) ($location_data['status'] ?? 1) === 0 ? ['disabled' => 'disabled'] : [])) ?> <?= lang('Config.location_primary') ?>
            </label>
        </div>
        <button type="button" class="btn btn-outline-secondary repeat-action add_stock_location" aria-label="<?= lang('Common.add') ?>"><span class="bi bi-plus-lg" aria-hidden="true"></span></button>
        <button type="button" class="btn btn-outline-secondary repeat-action remove_stock_location" aria-label="<?= lang('Common.remove') ?>"><span class="bi bi-dash-lg" aria-hidden="true"></span></button>
    </div>

<?php } ?>
