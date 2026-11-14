<?php
/**
 * @var array $dinner_tables
 */
?>

<?php
$i = 0;

foreach ($dinner_tables as $table_key => $table) {
    $dinner_table_id   = $table['dinner_table_id'];
    $dinner_table_name = $table['name'];
    $i++;
    ?>

    <div class="row mb-3 repeat-row<?= $table['deleted'] ? ' d-none' : '' ?>">
        <?= form_label(lang('Config.dinner_table') . " {$i}", "dinner_table_{$i}", ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
        <div class="col-7 col-md-4 col-lg-3">
            <?php $form_data = [
                    'name'  => "dinner_table_{$dinner_table_id}",
                    'id'    => "dinner_table_{$dinner_table_id}",
                    'class' => 'dinner_table valid_chars form-control form-control-sm required',
                    'value' => $dinner_table_name,
                ];
    $table['deleted'] && $form_data['disabled'] = 'disabled';
    echo form_input($form_data);
    ?>
        </div>
        <button type="button" class="btn btn-outline-secondary repeat-action add_dinner_table" aria-label="<?= lang('Common.add') ?>"><span class="bi bi-plus-lg" aria-hidden="true"></span></button>
        <button type="button" class="btn btn-outline-secondary repeat-action remove_dinner_table" aria-label="<?= lang('Common.remove') ?>"><span class="bi bi-dash-lg" aria-hidden="true"></span></button>
    </div>

<?php } ?>
