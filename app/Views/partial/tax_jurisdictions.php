<?php
/**
 * @var array $tax_jurisdictions
 * @var array $tax_types
 */
?>

<?php
$i = 0;

foreach ($tax_jurisdictions as $tax_jurisdiction => $jurisdiction) {
    $jurisdiction_id = $jurisdiction['jurisdiction_id'];
    $jurisdiction_name = $jurisdiction['jurisdiction_name'];
    $tax_group = $jurisdiction['tax_group'];
    $reporting_authority = $jurisdiction['reporting_authority'];
    $tax_type = $jurisdiction['tax_type'];
    $tax_group_sequence = $jurisdiction['tax_group_sequence'];
    $cascade_sequence = $jurisdiction['cascade_sequence'];
    ++$i;
?>

    <div class="tax-row">
        <?= form_label(lang('Taxes.tax_jurisdiction') . " $i", "jurisdiction_name_$i", ['class' => 'form-label tax-row-label']) ?>
        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'jurisdiction_name[]',
                'id'          => "jurisdiction_name_$i",
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.jurisdiction_name'),
                'value'       => $jurisdiction_name
            ];
            echo form_input($form_data);
            ?>
        </div>

        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'tax_group[]',
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.tax_group'),
                'value'       => $tax_group
            ];
            echo form_input($form_data);
            ?>
        </div>

        <div class="tax-row-field">
            <?= form_dropdown('tax_type[]' . $i, $tax_types, $tax_type, ['class' => 'form-select'])    ?>
        </div>

        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'reporting_authority[]',
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.reporting_authority'),
                'value'       => $reporting_authority
            ];
            echo form_input($form_data)
            ?>
        </div>

        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'tax_group_sequence[]',
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.sequence'),
                'value'       => $tax_group_sequence
            ];
            echo form_input($form_data)
            ?>
        </div>

        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'cascade_sequence[]',
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.cascade_sequence'),
                'value'       => $cascade_sequence
            ];
            echo form_input($form_data)
            ?>
        </div>
        <div class="tax-row-actions">
            <button type="button" class="btn btn-outline-secondary btn-sm add_tax_jurisdiction" aria-label="<?= lang('Common.add') ?>"><span class="bi bi-plus-lg" aria-hidden="true"></span></button>
            <button type="button" class="btn btn-outline-secondary btn-sm remove_tax_jurisdiction" aria-label="<?= lang('Common.delete') ?>"><span class="bi bi-dash-lg" aria-hidden="true"></span></button>
        </div>
        <?= form_hidden('jurisdiction_id[]', (string)$jurisdiction_id) ?>
    </div>

<?php } ?>
