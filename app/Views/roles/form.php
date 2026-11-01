<?php
/**
 * @var object|null $role
 * @var list<string> $held
 * @var list<array{module_id: string, label: string, description: string, selectable: bool, permissions: list<array{permission_id: string, label: string, sensitive: bool}>}> $modules
 * @var bool $is_admin
 * @var list<string> $my_grants
 */

$isNew = $role === null;
$scope = $isNew ? 'all' : (string) $role->record_scope;
$mayGive = static fn (string $permissionId): bool => $is_admin || in_array($permissionId, $my_grants, true);
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open('roles/save/' . ($isNew ? NEW_ENTRY : (int) $role->role_id), ['id' => 'role_form', 'class' => 'app-form']) ?>
    <fieldset id="role_fields">
        <div class="form-grid">
            <div class="field">
                <?= form_label(lang('Roles.name'), 'name', ['class' => 'form-label text-danger required']) ?>
                <div class="field-control">
                    <input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="100" value="<?= esc($isNew ? '' : $role->name, 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <?= form_label(lang('Roles.status'), 'active', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <select name="active" id="active" class="form-select form-select-sm">
                        <option value="1"<?= $isNew || (int) $role->active === 1 ? ' selected' : '' ?>><?= lang('Roles.active') ?></option>
                        <option value="0"<?= ! $isNew && (int) $role->active === 0 ? ' selected' : '' ?>><?= lang('Roles.inactive') ?></option>
                    </select>
                </div>
            </div>

            <div class="field span-all">
                <?= form_label(lang('Roles.description'), 'description', ['class' => 'form-label']) ?>
                <div class="field-control">
                    <input type="text" name="description" id="description" class="form-control form-control-sm" maxlength="255" value="<?= esc($isNew ? '' : (string) $role->description, 'attr') ?>">
                </div>
            </div>

            <fieldset class="field span-all">
                <legend class="form-label"><?= lang('Roles.record_scope') ?></legend>
                <p class="form-text"><?= lang('Roles.scope_help') ?></p>
                <?php foreach (['all', 'own'] as $option): ?>
                    <div class="form-check">
                        <input type="radio" class="form-check-input" name="record_scope" id="record_scope_<?= $option ?>" value="<?= $option ?>"<?= $scope === $option ? ' checked' : '' ?>>
                        <label class="form-check-label" for="record_scope_<?= $option ?>"><?= lang('Roles.scope_' . $option) ?></label>
                    </div>
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="field span-all">
                <legend class="form-label"><?= lang('Roles.permissions') ?></legend>
                <p class="form-text"><?= lang('Roles.permissions_help') ?></p>
                <p class="form-text text-warning-emphasis" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Roles.sensitive_note') ?></p>

                <div id="role_permission_list" class="perm-list">
                    <?php foreach ($modules as $module): ?>
                        <?php
                        $moduleId     = $module['module_id'];
                        $moduleHeld   = in_array($moduleId, $held, true);
                        $moduleLocked = ! $module['selectable'] || ! $mayGive($moduleId);
                        ?>
                        <section class="perm-module">
                            <div class="perm-head form-check form-switch">
                                <?php if ($moduleLocked && $moduleHeld && $module['selectable']): ?>
                                    <input type="hidden" name="permissions[]" value="<?= esc($moduleId, 'attr') ?>">
                                <?php endif; ?>
                                <input type="checkbox" class="form-check-input module-toggle" role="switch" id="perm_<?= esc($moduleId, 'attr') ?>"<?= $moduleLocked ? ' disabled' : ' name="permissions[]"' ?> value="<?= esc($moduleId, 'attr') ?>"<?= $moduleHeld ? ' checked' : '' ?>>
                                <label class="form-check-label" for="perm_<?= esc($moduleId, 'attr') ?>">
                                    <span class="perm-title"><?= esc($module['label']) ?></span>
                                    <span class="perm-note"><?= esc($module['description']) ?></span>
                                </label>
                            </div>

                            <?php if ($module['permissions'] !== []): ?>
                                <ul class="perm-subs">
                                    <?php foreach ($module['permissions'] as $permission): ?>
                                        <?php
                                        $permissionId = $permission['permission_id'];
                                        $isHeld       = in_array($permissionId, $held, true);
                                        $locked       = $permission['sensitive'] || ! $mayGive($permissionId);
                                        ?>
                                        <li class="form-check">
                                            <?php if ($locked && $isHeld && ! $permission['sensitive']): ?>
                                                <input type="hidden" name="permissions[]" value="<?= esc($permissionId, 'attr') ?>">
                                            <?php endif; ?>
                                            <input type="checkbox" class="form-check-input<?= $permission['sensitive'] ? ' sensitive-permission' : '' ?>" id="perm_<?= esc($permissionId, 'attr') ?>"<?= $locked ? ' disabled data-locked="1"' : ' name="permissions[]"' ?><?= $permission['sensitive'] ? ' title="' . esc(lang('Roles.sensitive_locked'), 'attr') . '"' : '' ?> value="<?= esc($permissionId, 'attr') ?>"<?= $isHeld && ! $permission['sensitive'] ? ' checked' : '' ?>>
                                            <label class="form-check-label" for="perm_<?= esc($permissionId, 'attr') ?>">
                                                <?= esc($permission['label']) ?>
                                                <?php if ($permission['sensitive']): ?>
                                                    <span class="badge text-bg-warning"><?= lang('Roles.sensitive_permission') ?></span>
                                                <?php endif; ?>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </div>
    </fieldset>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#role_permission_list .perm-module').each(function() {
            const $module = $(this);
            const $toggle = $module.find('.module-toggle');
            const sync = function() {
                $module.toggleClass('is-off', !$toggle.is(':checked') && !$toggle.is(':disabled'));
            };
            $toggle.on('change', sync);
            sync();
        });

        $('#role_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        table_support.handle_submit('roles', response);
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#error_message_box',

            rules: {
                name: 'required'
            },

            messages: {
                name: "<?= esc(lang('Roles.name_required'), 'js') ?>"
            }
        }, form_support.error));
    });
</script>
