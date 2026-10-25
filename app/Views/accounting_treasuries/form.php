<?php
/**
 * @var object|null $treasury
 * @var list<object> $branches
 * @var array<string, string> $kinds
 */

$isNew = $treasury === null;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_treasuries/save/' . ($isNew ? NEW_ENTRY : (int) $treasury->treasury_id), ['id' => 'treasury_form', 'class' => 'app-form']) ?>
    <fieldset id="treasury_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Accounting_treasuries.name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="231" value="<?= esc($isNew ? '' : $treasury->name, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.kind'), 'kind', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="kind" id="kind" class="form-select form-select-sm"<?= ! $isNew && (int) $treasury->is_main === 1 ? ' disabled' : '' ?>>
                        <?php foreach ($kinds as $value => $text): ?>
                            <option value="<?= esc($value, 'attr') ?>"<?= ! $isNew && $treasury->kind === $value ? ' selected' : '' ?>><?= esc($text) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (! $isNew && (int) $treasury->is_main === 1): ?>
                        <input type="hidden" name="kind" value="<?= esc($treasury->kind, 'attr') ?>">
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.branch'), 'branch_id', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?php if ($isNew && count($branches) > 1): ?>
                        <select name="branch_id" id="branch_id" class="form-select form-select-sm">
                            <?php foreach ($branches as $branch): ?>
                                <option value="<?= (int) $branch->branch_id ?>"><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <span class="form-control-plaintext"><?= esc($isNew ? ($branches[0]->code ?? '') : $treasury->branch_code) ?></span>
                        <?php if ($isNew && $branches !== []): ?>
                            <input type="hidden" name="branch_id" value="<?= (int) $branches[0]->branch_id ?>">
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_treasuries.description'), 'description', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <textarea name="description" id="description" class="form-control form-control-sm" rows="2" maxlength="500"><?= esc($isNew ? '' : $treasury->description) ?></textarea>
                </div>
            </div>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#treasury_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_treasuries', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: 'required'
            },

            messages: {
                name: "<?= esc(lang('Accounting_treasuries.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
