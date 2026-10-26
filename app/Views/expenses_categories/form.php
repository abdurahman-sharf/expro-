<?php
/**
 * @var object $category_info
 * @var bool $accounting_on
 * @var array<int, string> $account_options
 * @var bool $can_assign_account
 * @var string $controller_name
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("expenses_categories/save/$category_info->expense_category_id", ['id' => 'expense_category_edit_form', 'class' => 'app-form']) ?>
    <fieldset id="expenses_categories">

        <div class="field">
            <?= form_label(lang('Expenses_categories.name'), 'category_name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <?= form_input([
                    'name'  => 'category_name',
                    'id'    => 'category_name',
                    'class' => 'form-control form-control-sm',
                    'value' => $category_info->category_name
                ]) ?>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Expenses_categories.description'), 'category_description', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_textarea([
                    'name'  => 'category_description',
                    'id'    => 'category_description',
                    'class' => 'form-control form-control-sm',
                    'value' => $category_info->category_description
                ]) ?>
            </div>
        </div>

        <?php if (! empty($accounting_on)): ?>
            <div class="field">
                <?= form_label(lang('Expenses_categories.account'), 'account_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?= form_dropdown('account_id', $account_options, (string) ((int) $category_info->account_id), ['class' => 'form-select', 'id' => 'account_id'] + ($can_assign_account ? [] : ['disabled' => 'disabled'])) ?>
                    <p class="form-text mb-0"><?= lang($can_assign_account ? 'Expenses_categories.account_desc' : 'Expenses_categories.account_locked') ?></p>
                </div>
            </div>
        <?php endif; ?>

    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $('#expense_category_edit_form').validate($.extend({
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
                category_name: 'required'
            },

            messages: {
                category_name: "<?= lang('Expenses_categories.category_name_required') ?>"
            }
        }, form_support.error));
    });
</script>
