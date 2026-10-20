<?php
/**
 * @var array $stock_locations
 */
?>

<?php
$i = 0;

foreach ($stock_locations as $location => $location_data) {
    $location_id = $location_data['location_id'];
    $location_name = $location_data['location_name'];
    ++$i;
?>

    <div class="row mb-3 repeat-row<?= $location_data['deleted'] ? ' d-none' : '' ?>">
        <?= form_label(lang('Config.stock_location') . " $i", "stock_location_$i", ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
        <div class="col-7 col-md-4 col-lg-3">
            <?php $form_data = [
                'name'  => "stock_location[$location_id]",
                'id'    => "stock_location[$location_id]",
                'class' => 'stock_location valid_chars form-control form-control-sm required',
                'value' => $location_name
            ];
            $location_data['deleted'] && $form_data['disabled'] = 'disabled';
            echo form_input($form_data);
            ?>
        </div>
        <button type="button" class="btn btn-outline-secondary repeat-action add_stock_location" aria-label="<?= lang('Common.add') ?>"><span class="bi bi-plus-lg" aria-hidden="true"></span></button>
        <button type="button" class="btn btn-outline-secondary repeat-action remove_stock_location" aria-label="<?= lang('Common.remove') ?>"><span class="bi bi-dash-lg" aria-hidden="true"></span></button>
    </div>

<?php } ?>
