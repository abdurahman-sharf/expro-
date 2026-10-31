<?php
/**
 * @var object|null        $category
 * @var array<int, string> $accountOptions
 * @var bool               $canAssignAccount
 */
$isNew = $category === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('revenue_categories/save/' . ($isNew ? NEW_ENTRY : (int) $category->revenue_category_id), ['id' => 'revenue_category_form', 'class' => 'app-form']) ?>
    <fieldset id="revenue_category_fields">
        <div class="field">
            <?= form_label(lang('Revenue_categories.name'), 'category_name', ['class' => 'form-label text-danger required']) ?>
            <div class="field-control">
                <input type="text" name="category_name" id="category_name" class="form-control form-control-sm" maxlength="255" value="<?= $isNew ? '' : esc($category->category_name, 'attr') ?>">
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Revenue_categories.description'), 'category_description', ['class' => 'form-label']) ?>
            <div class="field-control">
                <textarea name="category_description" id="category_description" class="form-control form-control-sm" rows="2" maxlength="255"><?= $isNew ? '' : esc($category->category_description) ?></textarea>
            </div>
        </div>

        <div class="field">
            <?= form_label(lang('Revenue_categories.account'), 'account_id', ['class' => 'form-label']) ?>
            <div class="field-control">
                <?= form_dropdown('account_id', $accountOptions, (string) ($isNew ? 0 : (int) $category->account_id), ['class' => 'form-select form-select-sm', 'id' => 'account_id'] + ($canAssignAccount ? [] : ['disabled' => 'disabled'])) ?>
                <p class="form-text mb-0"><?= lang($canAssignAccount ? 'Revenue_categories.account_desc' : 'Revenue_categories.account_locked') ?></p>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#revenue_category_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('revenue_categories', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                category_name: 'required'
            },

            messages: {
                category_name: "<?= esc(lang('Revenue_categories.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
