<?php
/**
 * @var array $tax_categories
 */
?>

<?php
$i = 0;

foreach ($tax_categories as $key => $category) {
    $tax_category_id    = $category['tax_category_id'];
    $tax_category       = $category['tax_category'];
    $tax_group_sequence = $category['tax_group_sequence'];
    $i++;
    ?>

    <div class="tax-row">
        <?= form_label(lang('Taxes.tax_category') . " {$i}", "tax_category_{$i}", ['class' => 'form-label tax-row-label']) ?>
        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'tax_category[]',
                'id'          => "tax_category_{$i}",
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.tax_category_name'),
                'value'       => $tax_category,
            ];
    echo form_input($form_data);
    ?>
        </div>
        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'tax_group_sequence[]',
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.sequence'),
                'value'       => $tax_group_sequence,
            ];
    echo form_input($form_data);
    ?>
        </div>
        <div class="tax-row-actions">
            <button type="button" class="btn btn-outline-secondary btn-sm add_tax_category" aria-label="<?= lang('Common.add') ?>"><span class="bi bi-plus-lg" aria-hidden="true"></span></button>
            <button type="button" class="btn btn-outline-secondary btn-sm remove_tax_category" aria-label="<?= lang('Common.delete') ?>"><span class="bi bi-dash-lg" aria-hidden="true"></span></button>
        </div>
        <?= form_hidden('tax_category_id[]', (string) $tax_category_id) ?>
    </div>

<?php } ?>
