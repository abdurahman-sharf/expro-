<?php
/**
 * @var string $controller_name
 * @var object $person_info
 * @var array $categories
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("$controller_name/save/$person_info->person_id", ['id' => 'supplier_form', 'class' => 'app-form']) ?>
    <fieldset id="supplier_basic_info">

        <div class="form-grid">
            <div class="field span-all">
                <?= form_label(lang('Suppliers.company_name'), 'company_name_input', ['class' => 'form-label text-danger required']) ?>
                <?= form_input([
                    'name'  => 'company_name',
                    'id'    => 'company_name_input',
                    'class' => 'form-control form-control-sm',
                    'value' => html_entity_decode($person_info->company_name)
                ]) ?>
            </div>

            <div class="field">
                <?= form_label(lang('Suppliers.category'), 'category', ['class' => 'form-label text-danger required']) ?>
                <?= form_dropdown('category', $categories, $person_info->category, ['class' => 'form-select', 'id' => 'category']) ?>
            </div>

            <div class="field">
                <?= form_label(lang('Suppliers.agency_name'), 'agency_name_input', ['class' => 'form-label']) ?>
                <?= form_input([
                    'name'  => 'agency_name',
                    'id'    => 'agency_name_input',
                    'class' => 'form-control form-control-sm',
                    'value' => $person_info->agency_name
                ]) ?>
            </div>
        </div>

        <?= view('people/form_basic_info') ?>

        <div class="form-section">
            <h3 class="form-section-title"><?= lang('Common.section_account') ?></h3>
            <div class="form-grid">
                <div class="field">
                    <?= form_label(lang('Suppliers.account_number'), 'account_number', ['class' => 'form-label']) ?>
                    <?= form_input([
                        'name'  => 'account_number',
                        'id'    => 'account_number',
                        'class' => 'form-control form-control-sm',
                        'value' => $person_info->account_number
                    ]) ?>
                </div>

                <div class="field">
                    <?= form_label(lang('Suppliers.tax_id'), 'tax_id', ['class' => 'form-label']) ?>
                    <?= form_input([
                        'name'  => 'tax_id',
                        'id'    => 'tax_id',
                        'class' => 'form-control form-control-sm',
                        'value' => $person_info->tax_id
                    ]) ?>
                </div>

                <div class="field">
                    <?= form_label(lang('Suppliers.country_code'), 'country_code', ['class' => 'form-label']) ?>
                    <?= form_input([
                        'name'      => 'country_code',
                        'id'        => 'country_code',
                        'class'     => 'form-control form-control-sm',
                        'maxlength' => 2,
                        'dir'       => 'ltr',
                        'value'     => $person_info->country_code ?? ''
                    ]) ?>
                    <div class="form-text"><?= lang('Customers.country_code_help') ?></div>
                </div>

                <div class="field">
                    <?= form_label(lang('Suppliers.commercial_registry'), 'commercial_registry', ['class' => 'form-label']) ?>
                    <?= form_input([
                        'name'      => 'commercial_registry',
                        'id'        => 'commercial_registry',
                        'class'     => 'form-control form-control-sm',
                        'maxlength' => 64,
                        'dir'       => 'ltr',
                        'value'     => $person_info->commercial_registry ?? ''
                    ]) ?>
                </div>
            </div>
        </div>

        <?= view('people/form_comments') ?>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $('#supplier_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit("<?= esc($controller_name) ?>", response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                company_name: 'required',
                first_name: 'required',
                last_name: 'required',
                email: 'email'
            },

            messages: {
                company_name: "<?= lang('Suppliers.company_name_required') ?>",
                first_name: "<?= lang('Common.first_name_required') ?>",
                last_name: "<?= lang('Common.last_name_required') ?>",
                email: "<?= lang('Common.email_invalid_format') ?>"
            }
        }, form_support.error));
    });
</script>
