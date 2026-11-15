<?php
/**
 * @var array $tax_codes
 */
?>

<?php
$i = 0;

foreach ($tax_codes as $tax_code => $tax_code_data) {
    $tax_code_id   = $tax_code_data['tax_code_id'];
    $tax_code      = $tax_code_data['tax_code'];
    $tax_code_name = $tax_code_data['tax_code_name'];
    $city          = $tax_code_data['city'];
    $state         = $tax_code_data['state'];
    $i++;
    ?>

    <div class="tax-row<?= $tax_code_data['deleted'] ? ' d-none' : '' ?>">
        <?= form_label(lang('Taxes.tax_code') . " {$i}", "tax_code_{$i}", ['class' => 'form-label tax-row-label']) ?>
        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'tax_code[]',
                'id'          => "tax_code_{$i}",
                'class'       => 'valid_chars text-uppercase form-control form-control-sm',
                'placeholder' => lang('Taxes.code'),
                'value'       => $tax_code,
            ];
    echo form_input($form_data)
    ?>
        </div>
        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'tax_code_name[]',
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.name'),
                'value'       => $tax_code_name,
            ];
    echo form_input($form_data)
    ?>
        </div>
        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'city[]',
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.city'),
                'value'       => $city,
            ];
    echo form_input($form_data)
    ?>
        </div>
        <div class="tax-row-field">
            <?php $form_data = [
                'name'        => 'state[]',
                'class'       => 'valid_chars form-control form-control-sm',
                'placeholder' => lang('Taxes.state'),
                'value'       => $state,
            ];
    echo form_input($form_data)
    ?>
        </div>

        <div class="tax-row-actions">
            <button type="button" class="btn btn-outline-secondary btn-sm add_tax_code" aria-label="<?= lang('Common.add') ?>"><span class="bi bi-plus-lg" aria-hidden="true"></span></button>
            <button type="button" class="btn btn-outline-secondary btn-sm remove_tax_code" aria-label="<?= lang('Common.delete') ?>"><span class="bi bi-dash-lg" aria-hidden="true"></span></button>
        </div>
        <?= form_hidden('tax_code_id[]', (string) $tax_code_id) ?>
    </div>

<?php } ?>
