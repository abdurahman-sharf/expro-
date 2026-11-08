<?php
/**
 * @var object|null $category
 * @var array{cost: list<object>, accumulated: list<object>, expense: list<object>} $accounts
 */
$isNew  = $category === null;
$action = $isNew ? 'accounting_assets/save_category' : 'accounting_assets/save_category/' . (int) $category->category_id;
$value  = static fn (string $field): string => $isNew ? '' : (string) ($category->{$field} ?? '');
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box" role="alert"></ul>

<?= form_open($action, ['id' => 'category_form', 'class' => 'app-form']) ?>
    <fieldset>
        <div class="form-grid">
            <div class="field">
                <label class="form-label text-danger required" for="name"><?= lang('Accounting_assets.category_name') ?></label>
                <div class="field-control"><input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="100" value="<?= esc($value('name'), 'attr') ?>"></div>
            </div>
            <?php foreach (['cost' => 'cost_account_id', 'accumulated' => 'accumulated_account_id', 'expense' => 'expense_account_id'] as $kind => $field): ?>
                <div class="field">
                    <label class="form-label" for="<?= $field ?>"><?= lang('Accounting_assets.field_' . $kind . '_account') ?></label>
                    <div class="field-control">
                        <select name="<?= $field ?>" id="<?= $field ?>" class="form-select form-select-sm">
                            <option value=""><?= lang('Accounting_assets.account_default') ?></option>
                            <?php foreach ($accounts[$kind] as $account): ?>
                                <option value="<?= (int) $account->account_id ?>"<?= (int) $value($field) === (int) $account->account_id ? ' selected' : '' ?>><?= esc($account->code . ' ' . $account->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endforeach; ?>
            <div class="field">
                <label class="form-label" for="method"><?= lang('Accounting_assets.field_method') ?></label>
                <div class="field-control">
                    <select name="method" id="method" class="form-select form-select-sm">
                        <option value=""><?= lang('Accounting_assets.none') ?></option>
                        <?php foreach (['fixed', 'declining', 'units', 'none'] as $method): ?>
                            <option value="<?= $method ?>"<?= $value('method') === $method ? ' selected' : '' ?>><?= lang('Accounting_assets.method_' . $method) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="life_months"><?= lang('Accounting_assets.category_life') ?></label>
                <div class="field-control"><input type="number" min="1" max="1200" name="life_months" id="life_months" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($value('life_months'), 'attr') ?>"></div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#category_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        if (response.success) {
                            dialog_support.hide();
                            toast_support.show(response.message, 'success');
                            window.location.reload();

                            return;
                        }
                        $('#error_message_box').empty().append($('<li></li>').text(response.message));
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {name: 'required'},

            messages: {name: "<?= esc(lang('Accounting_assets.category_name_required'), 'js') ?>"}
        }, form_support.error));
    });
</script>
