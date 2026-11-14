<?php
/**
 * @var string $controller_name
 * @var object $person_info
 * @var array $all_modules
 * @var array $all_subpermissions
 * @var list<string> $sensitive_permissions
 * @var bool $is_admin
 * @var bool $security_locked
 * @var int $employee_id
 * @var array{scope: array{all: bool, branch_ids: list<int>, default_branch_id: ?int}, branches: list<object>, can_all: bool, locked: bool, hidden: bool}|null $branch_tab
 * @var array{roles: list<object>, held: list<int>, can_manage: bool, record_scope: string, locations: list<object>, default_location_id: ?int, role_permissions: array<string, list<string>>}|null $roles_tab
 */
?>

<div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>

<?= form_open("$controller_name/save/$person_info->person_id", ['id' => 'employee_form', 'class' => 'app-form']) ?>

    <ul class="nav nav-tabs nav-justified" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link active" data-bs-toggle="tab" role="tab" aria-controls="employee_basic_info" href="#employee_basic_info"><?= lang('Employees.basic_information') ?></a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="employee_login_info" href="#employee_login_info"><?= lang('Employees.login_info') ?></a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="employee_permission_info" href="#employee_permission_info"><?= lang('Employees.permission_info') ?></a>
        </li>
        <?php if ($branch_tab !== null): ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="employee_branch_info" href="#employee_branch_info"><?= lang('Branches.employee_tab') ?></a>
            </li>
        <?php endif; ?>
        <?php if ($roles_tab !== null): ?>
            <li class="nav-item" role="presentation">
                <a class="nav-link" data-bs-toggle="tab" role="tab" aria-controls="employee_roles_info" href="#employee_roles_info"><?= lang('Roles.employee_tab') ?></a>
            </li>
        <?php endif; ?>
    </ul>

    <ul id="error_message_box" class="error_message_box"></ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="employee_basic_info" role="tabpanel">
            <fieldset>
                <?= view('people/form_basic_info') ?>
                <?= view('people/form_comments') ?>
            </fieldset>
        </div>

        <div class="tab-pane fade" id="employee_login_info" role="tabpanel">
            <fieldset>
                <?php if ($security_locked) { ?>
                    <p class="perm-desc text-warning-emphasis" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= esc(lang('Employees.security_locked_note')) ?></p>
                <?php } ?>
                <div class="form-grid">
                    <div class="field span-all">
                        <?= form_label(lang('Employees.username'), 'username', ['class' => 'form-label text-danger required']) ?>
                        <div class="input-group">
                            <span class="input-group-text"><span class="bi bi-person" aria-hidden="true"></span></span>
                            <?= form_input([
                                'name'         => 'username',
                                'id'           => 'username',
                                'class'        => 'form-control form-control-sm',
                                'value'        => $person_info->username,
                                'autocomplete' => 'off'
                            ] + ($security_locked ? ['readonly' => 'readonly'] : [])) ?>
                        </div>
                    </div>

                    <?php $passwordLabelClass = $person_info->person_id == '' ? 'form-label text-danger required' : 'form-label'; ?>

                    <div class="field">
                        <?= form_label(lang('Employees.password'), 'password', ['class' => $passwordLabelClass]) ?>
                        <div class="input-group">
                            <span class="input-group-text"><span class="bi bi-lock" aria-hidden="true"></span></span>
                            <?= form_password([
                                'name'         => 'password',
                                'id'           => 'password',
                                'class'        => 'form-control form-control-sm',
                                'autocomplete' => 'new-password'
                            ] + ($security_locked ? ['disabled' => 'disabled'] : [])) ?>
                        </div>
                    </div>

                    <div class="field">
                        <?= form_label(lang('Employees.repeat_password'), 'repeat_password', ['class' => $passwordLabelClass]) ?>
                        <div class="input-group">
                            <span class="input-group-text"><span class="bi bi-lock" aria-hidden="true"></span></span>
                            <?= form_password([
                                'name'         => 'repeat_password',
                                'id'           => 'repeat_password',
                                'class'        => 'form-control form-control-sm',
                                'autocomplete' => 'new-password'
                            ] + ($security_locked ? ['disabled' => 'disabled'] : [])) ?>
                        </div>
                    </div>

                    <div class="field span-all">
                        <?= form_label(lang('Employees.language'), 'language', ['class' => 'form-label']) ?>
                        <?php
                        $languages = get_languages();
                        $languages[':'] = lang('Employees.system_language');
                        $languageCode = current_language_code();
                        $language = current_language();

                        if ($languageCode === current_language_code(true)) {
                            $languageCode = '';
                            $language = '';
                        }

                        echo form_dropdown(
                            'language',
                            $languages,
                            "$languageCode:$language",
                            ['class' => 'form-select form-select-sm', 'id' => 'language']
                        );
                        ?>
                    </div>
                </div>
            </fieldset>
        </div>

        <div class="tab-pane fade" id="employee_permission_info" role="tabpanel">
            <fieldset>
                <div class="perm-intro">
                    <p class="perm-desc"><?= lang('Employees.permission_desc') ?></p>
                    <?php if ($security_locked) { ?>
                        <p class="perm-desc text-warning-emphasis" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= esc(lang('Employees.security_locked_note')) ?></p>
                    <?php } ?>
                    <p class="perm-desc text-warning-emphasis" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Employees.sensitive_note') ?></p>
                    <div class="form-check form-switch">
                        <input type="checkbox" class="form-check-input" role="switch" id="permission_select_all">
                        <label class="form-check-label" for="permission_select_all"><?= lang('Employees.permission_select_all') ?></label>
                    </div>
                </div>

                <div id="permission_list" class="perm-list">
                    <?= form_hidden('module_validation', '1', 'class="module"') ?>
                    <?php foreach ($all_modules as $module) { ?>
                        <section class="perm-module">
                            <div class="perm-head form-check form-switch">
                                <?php
                                $moduleAttributes = [
                                    'name'    => "grant_$module->module_id",
                                    'id'      => "grant_$module->module_id",
                                    'value'   => $module->module_id,
                                    'checked' => $module->grant == 1,
                                    'class'   => 'form-check-input module-toggle' . (in_array($module->module_id, $sensitive_permissions, true) ? ' sensitive-permission' : ''),
                                    'role'    => 'switch'
                                ];

                                if ($security_locked) {
                                    $moduleAttributes += ['disabled' => 'disabled', 'data-locked' => '1', 'title' => lang('Employees.security_locked_note')];
                                } elseif (in_array($module->module_id, $sensitive_permissions, true) && ! $is_admin) {
                                    $moduleAttributes += ['disabled' => 'disabled', 'data-locked' => '1', 'title' => lang('Employees.sensitive_locked')];
                                }
                                ?>
                                <?= form_checkbox($moduleAttributes) ?>
                                <?= form_hidden("menu_group_$module->module_id", $module->menu_group) ?>
                                <label class="form-check-label" for="grant_<?= esc($module->module_id) ?>">
                                    <span class="perm-title"><?= lang("Module.$module->module_id") ?></span>
                                    <span class="perm-note"><?= lang("Module.$module->module_id" . '_desc') ?></span>
                                    <?php if (isset($roles_tab['role_permissions'][$module->module_id])): ?>
                                        <span class="badge text-bg-info"><?= esc(lang('Roles.via_role', [implode(', ', $roles_tab['role_permissions'][$module->module_id])])) ?></span>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <?php
                            $subpermissions = [];

                            foreach ($all_subpermissions as $permission) {
                                if ($permission->module_id != $module->module_id) {
                                    continue;
                                }

                                $explodedPermission = explode('_', $permission->permission_id, 2);
                                $langKey = $module->module_id . '.' . $explodedPermission[1];
                                $langLine = lang(ucfirst($langKey));

                                if ($langLine === '' || $langLine === ucfirst($langKey)) {
                                    $langLine = ucwords(str_replace('_', ' ', $explodedPermission[1]));
                                }

                                if (!empty($langLine)) {
                                    $subpermissions[] = ['permission' => $permission, 'label' => $langLine];
                                }
                            }
                            ?>
                            <?php if ($subpermissions) { ?>
                                <ul class="perm-subs">
                                    <?php foreach ($subpermissions as $subpermission) { ?>
                                        <?php
                                        $permission = $subpermission['permission'];
                                        $sensitive  = in_array($permission->permission_id, $sensitive_permissions, true);
                                        $locked     = $security_locked || ($sensitive && ! $is_admin);
                                        $attributes = [
                                            'name'    => "grant_$permission->permission_id",
                                            'id'      => "grant_$permission->permission_id",
                                            'value'   => $permission->permission_id,
                                            'checked' => $permission->grant == 1,
                                            'class'   => 'form-check-input' . ($sensitive ? ' sensitive-permission' : ''),
                                        ];

                                        if ($locked) {
                                            $attributes += ['disabled' => 'disabled', 'data-locked' => '1', 'title' => lang($security_locked ? 'Employees.security_locked_note' : 'Employees.sensitive_locked')];
                                        }
                                        ?>
                                        <li class="form-check">
                                            <?= form_checkbox($attributes) ?>
                                            <?= form_hidden("menu_group_$permission->permission_id", '--') ?>
                                            <label class="form-check-label" for="grant_<?= esc($permission->permission_id) ?>">
                                                <?= esc($subpermission['label']) ?>
                                                <?php if ($sensitive) { ?>
                                                    <span class="badge text-bg-warning"><?= lang('Employees.sensitive_permission') ?></span>
                                                <?php } ?>
                                                <?php if (isset($roles_tab['role_permissions'][$permission->permission_id])): ?>
                                                    <span class="badge text-bg-info"><?= esc(lang('Roles.via_role', [implode(', ', $roles_tab['role_permissions'][$permission->permission_id])])) ?></span>
                                                <?php endif; ?>
                                            </label>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                        </section>
                    <?php } ?>
                </div>
            </fieldset>
        </div>

        <?php if ($branch_tab !== null): ?>
            <?php $scope = $branch_tab['scope']; ?>
            <div class="tab-pane fade" id="employee_branch_info" role="tabpanel">
                <fieldset>
                    <p class="perm-desc"><?= lang('Branches.scope_desc') ?></p>

                    <?php if ($branch_tab['locked']): ?>
                        <p class="text-secondary" role="note"><span class="bi bi-lock" aria-hidden="true"></span> <?= lang('Branches.scope_locked_note') ?></p>
                    <?php else: ?>
                        <input type="hidden" name="branch_scope_present" value="1">

                        <div class="branch-scope">
                            <?php if ($branch_tab['can_all']): ?>
                                <div class="form-check">
                                    <input type="radio" class="form-check-input" name="branch_scope" id="branch_scope_all" value="all"<?= $scope['all'] ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="branch_scope_all"><?= lang('Branches.scope_all') ?></label>
                                </div>
                            <?php endif; ?>
                            <div class="form-check">
                                <input type="radio" class="form-check-input" name="branch_scope" id="branch_scope_selected" value="selected"<?= $scope['all'] ? '' : ' checked' ?>>
                                <label class="form-check-label" for="branch_scope_selected"><?= lang('Branches.scope_selected') ?></label>
                            </div>

                            <ul class="branch-scope-list" id="branch_scope_list">
                                <?php foreach ($branch_tab['branches'] as $branch): ?>
                                    <?php $branchId = (int) $branch->branch_id; ?>
                                    <li>
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" name="branch_ids[]" id="branch_<?= $branchId ?>" value="<?= $branchId ?>"<?= in_array($branchId, $scope['branch_ids'], true) ? ' checked' : '' ?>>
                                            <label class="form-check-label" for="branch_<?= $branchId ?>">
                                                <bdi dir="ltr"><?= esc($branch->code) ?></bdi> <?= esc($branch->name) ?>
                                                <?php if ((int) $branch->active === 0): ?>
                                                    <span class="badge text-bg-secondary"><?= lang('Branches.inactive') ?></span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                        <div class="form-check branch-default">
                                            <input type="radio" class="form-check-input" name="default_branch_id" id="default_branch_<?= $branchId ?>" value="<?= $branchId ?>"<?= $scope['default_branch_id'] === $branchId ? ' checked' : '' ?>>
                                            <label class="form-check-label text-secondary small" for="default_branch_<?= $branchId ?>"><?= lang('Branches.scope_default') ?></label>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if ($branch_tab['hidden']): ?>
                                <p class="form-text"><?= lang('Branches.scope_hidden_note') ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>
            </div>
        <?php endif; ?>

        <?php if ($roles_tab !== null): ?>
        <div class="tab-pane fade" id="employee_roles_info" role="tabpanel">
            <fieldset>
                <div class="form-grid">
                    <?php if ($roles_tab['can_manage']): ?>
                        <input type="hidden" name="roles_present" value="1">

                        <fieldset class="field span-all">
                            <legend class="form-label"><?= lang('Roles.employee_roles') ?></legend>
                            <p class="form-text"><?= lang('Roles.employee_roles_help') ?></p>
                            <?php if ($roles_tab['roles'] === []): ?>
                                <p class="text-secondary"><?= lang('Roles.none_yet') ?></p>
                            <?php endif; ?>
                            <?php foreach ($roles_tab['roles'] as $role): ?>
                                <?php $roleId = (int) $role->role_id; ?>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="role_ids[]" id="role_<?= $roleId ?>" value="<?= $roleId ?>"<?= in_array($roleId, $roles_tab['held'], true) ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="role_<?= $roleId ?>">
                                        <?= esc($role->name) ?>
                                        <?php if ((int) $role->active === 0): ?>
                                            <span class="badge text-bg-secondary"><?= lang('Roles.inactive') ?></span>
                                        <?php endif; ?>
                                        <?php if ($role->record_scope === 'own'): ?>
                                            <span class="badge text-bg-light"><?= lang('Roles.scope_own') ?></span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>

                        <fieldset class="field span-all">
                            <legend class="form-label"><?= lang('Roles.employee_record_scope') ?></legend>
                            <p class="form-text"><?= lang('Roles.scope_help') ?></p>
                            <?php foreach (['all', 'own'] as $option): ?>
                                <div class="form-check">
                                    <input type="radio" class="form-check-input" name="record_scope" id="employee_record_scope_<?= $option ?>" value="<?= $option ?>"<?= $roles_tab['record_scope'] === $option ? ' checked' : '' ?>>
                                    <label class="form-check-label" for="employee_record_scope_<?= $option ?>"><?= lang('Roles.scope_' . $option) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </fieldset>
                    <?php endif; ?>

                    <div class="field span-all">
                        <input type="hidden" name="default_location_present" value="1">
                        <?= form_label(lang('Roles.default_location'), 'default_location_id', ['class' => 'form-label']) ?>
                        <select name="default_location_id" id="default_location_id" class="form-select form-select-sm">
                            <option value="0"><?= lang('Roles.default_location_none') ?></option>
                            <?php foreach ($roles_tab['locations'] as $location): ?>
                                <option value="<?= (int) $location->location_id ?>"<?= $roles_tab['default_location_id'] === (int) $location->location_id ? ' selected' : '' ?>><?= esc($location->location_name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-text"><?= lang('Roles.default_location_help') ?></p>
                    </div>
                </div>
            </fieldset>
        </div>
        <?php endif; ?>
    </div>

<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $.validator.setDefaults({
            ignore: []
        });

        $.validator.addMethod('module', function() {
            return $('#permission_list input[type=checkbox]').is(':checked');
        }, "<?= lang('Employees.subpermission_required') ?>");

        const $selectAll = $('#permission_select_all');

        const refreshSelectAll = function() {
            const $toggles = $('#permission_list .module-toggle').not('.sensitive-permission');
            const on = $toggles.filter(':checked').length;
            $selectAll.prop('checked', on === $toggles.length).prop('indeterminate', on > 0 && on < $toggles.length);
        };

        $('#permission_list .perm-module').each(function() {
            const $module = $(this);
            const $toggle = $module.find('.module-toggle');
            const $subs = $module.find('.perm-subs input[type=checkbox]').not('[data-locked]');
            const sync = function() {
                const checked = $toggle.is(':checked');
                $subs.prop('disabled', !checked);
                if (!checked) {
                    $subs.prop('checked', false);
                }
                $module.toggleClass('is-off', !checked);
            };
            $toggle.on('change', function() {
                sync();
                refreshSelectAll();
            });
            sync();
        });

        $selectAll.on('change', function() {
            const checked = $selectAll.is(':checked');
            $('#permission_list .module-toggle').not('.sensitive-permission').prop('checked', checked).trigger('change');
            $('#permission_list .perm-subs input[type=checkbox]').not('.sensitive-permission').prop('checked', checked);
            refreshSelectAll();
        });

        refreshSelectAll();

        const syncBranchScope = function() {
            const everything = $('#branch_scope_all').is(':checked');
            $('#branch_scope_list').find('input').prop('disabled', everything);
        };

        $('input[name="branch_scope"]').on('change', syncBranchScope);
        $('#branch_scope_list').on('change', 'input[name="branch_ids[]"]', function() {
            if (!this.checked) {
                $('#default_branch_' + this.value).prop('checked', false);
            }
        });
        syncBranchScope();

        $('#employee_form').validate($.extend({
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
                first_name: 'required',
                last_name: 'required',
                username: {

                    required: true,
                    minlength: 5,
                    remote: '<?= esc("$controller_name/checkUsername/$employee_id") ?>'
                },
                password: {
                    <?php if ($person_info->person_id == '') { ?>
                        required: true,
                    <?php } ?>
                    minlength: 8
                },
                repeat_password: {
                    equalTo: '#password'
                },
                email: 'email'
            },

            messages: {
                first_name: "<?= lang('Common.first_name_required') ?>",
                last_name: "<?= lang('Common.last_name_required') ?>",
                username: {
                    required: "<?= lang('Employees.username_required') ?>",
                    minlength: "<?= lang('Employees.username_minlength') ?>",
                    remote: "<?= lang('Employees.username_duplicate') ?>"
                },
                password: {
                    <?php if ($person_info->person_id == "") { ?>
                        required: "<?= lang('Employees.password_required') ?>",
                    <?php } ?>
                    minlength: "<?= lang('Employees.password_minlength') ?>"
                },
                repeat_password: {
                    equalTo: "<?= lang('Employees.password_must_match') ?>"
                },
                email: "<?= lang('Common.email_invalid_format') ?>"
            }
        }, form_support.error));
    });
</script>
