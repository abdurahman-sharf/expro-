<?php
/**
 * @var object|null $account
 * @var list<object> $groups
 * @var int $parent_id
 * @var string $suggested_code
 * @var bool $used
 * @var bool $contra
 * @var array<string, string> $natural
 */

$isNew      = $account === null;
$isSystem   = ! $isNew && (int) $account->is_system === 1;
$codeLocked = ! $isNew && ($isSystem || $used);
$jsonFlags  = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('accounting_chart/save/' . ($isNew ? NEW_ENTRY : (int) $account->account_id), ['id' => 'account_form', 'class' => 'app-form']) ?>
    <fieldset id="account_fields">
        <div class="form-grid">
            <?php if ($isNew): ?>
                <div class="field span-all">
                    <span class="form-label"><?= lang('Accounting_chart.kind') ?></span>
                    <div class="field-control">
                        <label class="choice"><input type="radio" class="form-check-input" name="is_group" value="0" checked> <?= lang('Accounting_chart.kind_posting') ?></label>
                        <label class="choice"><input type="radio" class="form-check-input" name="is_group" value="1"> <?= lang('Accounting_chart.kind_group') ?></label>
                    </div>
                </div>

                <div class="field span-all">
                    <?= form_label(lang('Accounting_chart.parent'), 'parent_id', ['class' => 'form-label text-danger required']) ?>
                    <div class="field-control">
                        <select name="parent_id" id="parent_id" class="form-select form-select-sm">
                            <?php foreach ($groups as $group): ?>
                                <option value="<?= (int) $group->account_id ?>" data-type="<?= esc($group->account_type, 'attr') ?>"<?= (int) $group->account_id === $parent_id ? ' selected' : '' ?>><?= esc($group->code . ' - ' . $group->name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <?= form_label(lang('Accounting_chart.code'), 'code', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="code" id="code" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="32" value="<?= esc($isNew ? $suggested_code : $account->code, 'attr') ?>" data-suggested="<?= esc($suggested_code, 'attr') ?>"<?= $codeLocked ? ' readonly' : '' ?>>
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Accounting_chart.name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="255" value="<?= esc($isNew ? '' : $account->name, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <span class="form-label"><?= lang('Accounting_chart.direction') ?></span>
                <div class="field-control">
                    <span id="direction_text" class="form-control-plaintext"><?= $isNew ? '' : esc(lang('Accounting.direction_' . $account->normal_balance)) ?></span>
                </div>
            </div>

            <div class="field field-check">
                <?= form_label(lang('Accounting_chart.contra'), 'contra', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <?php if ($isNew || ! $used): ?>
                        <input type="hidden" name="contra" value="0">
                        <input type="checkbox" name="contra" id="contra" value="1" class="form-check-input"<?= $contra ? ' checked' : '' ?>>
                    <?php else: ?>
                        <input type="hidden" name="contra" value="<?= $contra ? '1' : '0' ?>">
                        <input type="checkbox" id="contra" class="form-check-input" disabled<?= $contra ? ' checked' : '' ?>>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (! $isNew): ?>
                <p class="form-text span-all">
                    <?= lang($used ? 'Accounting_chart.locked_used' : ($isSystem ? 'Accounting_chart.locked_system' : 'Accounting_chart.locked_parent')) ?>
                </p>
            <?php endif; ?>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        const natural = <?= json_encode($natural, $jsonFlags) ?>;
        const directions = <?= json_encode(['debit' => lang('Accounting.direction_debit'), 'credit' => lang('Accounting.direction_credit')], $jsonFlags) ?>;
        const isNew = <?= $isNew ? 'true' : 'false' ?>;
        let codeEdited = false;

        const showDirection = function() {
            if (!isNew) {
                return;
            }
            const type = $('#parent_id option:selected').data('type');
            const base = natural[type];
            const flipped = $('#contra').is(':checked') ? (base === 'debit' ? 'credit' : 'debit') : base;
            $('#direction_text').text(base ? directions[flipped] : '');
        };

        $('#code').on('input', function() {
            codeEdited = true;
        });

        $('#parent_id').on('change', function() {
            showDirection();
            if (!codeEdited) {
                $.getJSON('accounting_chart/suggest_code/' + $(this).val(), function(response) {
                    if (response.success && !codeEdited) {
                        $('#code').val(response.code);
                    }
                });
            }
        });

        $('#contra').on('change', showDirection);
        showDirection();

        $('#account_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('accounting_chart', response);
                        if (response.success && window.refreshAccountingChart) {
                            window.refreshAccountingChart();
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                code: 'required',
                name: 'required'
            },

            messages: {
                code: "<?= esc(lang('Accounting_chart.code_required'), 'js') ?>",
                name: "<?= esc(lang('Accounting_chart.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
