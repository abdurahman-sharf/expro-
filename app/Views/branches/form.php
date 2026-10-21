<?php
/**
 * @var object|null $branch
 * @var string $suggested_code
 * @var list<object> $locations
 */

$isNew     = $branch === null;
$isDefault = ! $isNew && (int) $branch->is_default === 1;
$value     = static fn (string $field): string => $isNew ? '' : (string) ($branch->{$field} ?? '');
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('branches/save/' . ($isNew ? NEW_ENTRY : (int) $branch->branch_id), ['id' => 'branch_form', 'class' => 'app-form']) ?>
    <fieldset id="branch_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Branches.code'), 'code', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="code" id="code" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="16" value="<?= esc($isNew ? $suggested_code : $branch->code, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Branches.name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="255" value="<?= esc($value('name'), 'attr') ?>">
                </div>
            </div>

            <div class="field span-all">
                <?= form_label(lang('Branches.address'), 'address', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="address" id="address" class="form-control form-control-sm" maxlength="255" value="<?= esc($value('address'), 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Branches.phone'), 'phone', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="phone" id="phone" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="64" value="<?= esc($value('phone'), 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Branches.tax_registration'), 'tax_registration', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="tax_registration" id="tax_registration" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="64" value="<?= esc($value('tax_registration'), 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Branches.opened_on'), 'opened_on', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="date" name="opened_on" id="opened_on" class="form-control form-control-sm" value="<?= esc($value('opened_on'), 'attr') ?>">
                </div>
            </div>

            <?php if (! $isNew && ! $isDefault): ?>
                <div class="field">
                    <?= form_label(lang('Branches.status'), 'active', ['class' => 'form-label']) ?>
                    <div class="field-control">
                        <select name="active" id="active" class="form-select form-select-sm">
                            <option value="1"<?= (int) $branch->active === 1 ? ' selected' : '' ?>><?= lang('Branches.active') ?></option>
                            <option value="0"<?= (int) $branch->active === 0 ? ' selected' : '' ?>><?= lang('Branches.inactive') ?></option>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($locations !== []): ?>
                <fieldset class="field span-all branch-locations">
                    <legend class="form-label"><?= lang('Branches.locations') ?></legend>
                    <p class="form-text"><?= lang('Branches.locations_desc') ?></p>
                    <input type="hidden" name="locations_present" value="1">
                    <?php foreach ($locations as $location): ?>
                        <?php $mine = ! $isNew && (int) $location->branch_id === (int) $branch->branch_id; ?>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" name="locations[]" id="location_<?= (int) $location->location_id ?>" value="<?= (int) $location->location_id ?>"<?= $mine ? ' checked' : '' ?>>
                            <label class="form-check-label" for="location_<?= (int) $location->location_id ?>">
                                <?= esc($location->location_name) ?>
                                <?php if (! $mine): ?>
                                    <span class="text-secondary small">— <?= esc(lang('Branches.location_now_in', [$location->branch_code])) ?></span>
                                <?php endif; ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#branch_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('branches', response);
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
                code: "<?= esc(lang('Branches.code_required'), 'js') ?>",
                name: "<?= esc(lang('Branches.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
