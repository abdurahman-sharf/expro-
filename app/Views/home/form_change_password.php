<?php
/**
 * @var object $person_info
 */
?>

<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open("home/save/{$person_info->person_id}", ['id' => 'employee_form', 'class' => 'app-form dialog-narrow', 'autocomplete' => 'off']) ?>
    <input type="hidden" name="username" id="username" value="<?= esc($person_info->username, 'attr') ?>">

    <div class="dialog-account">
        <span class="dialog-account-avatar" aria-hidden="true"><?= esc(mb_substr(trim($person_info->first_name . $person_info->last_name) ?: $person_info->username, 0, 1)) ?></span>
        <span class="dialog-account-text">
            <b><?= esc(trim($person_info->first_name . ' ' . $person_info->last_name)) ?></b>
            <small dir="ltr"><?= esc($person_info->username) ?></small>
        </span>
        <i class="bi bi-shield-lock dialog-account-mark" aria-hidden="true"></i>
    </div>

    <div class="field">
        <?= form_label(lang('Employees.current_password'), 'current_password', ['class' => 'form-label required']) ?>
        <div class="input-group">
            <span class="input-group-text"><span class="bi bi-key" aria-hidden="true"></span></span>
            <?= form_password(['name' => 'current_password', 'id' => 'current_password', 'class' => 'form-control form-control-sm', 'autocomplete' => 'current-password']) ?>
        </div>
    </div>

    <div class="field">
        <?= form_label(lang('Employees.password'), 'password', ['class' => 'form-label required']) ?>
        <div class="input-group">
            <span class="input-group-text"><span class="bi bi-lock" aria-hidden="true"></span></span>
            <?= form_password(['name' => 'password', 'id' => 'password', 'class' => 'form-control form-control-sm', 'autocomplete' => 'new-password', 'aria-describedby' => 'password_help']) ?>
        </div>
        <p class="form-text mb-0" id="password_help"><?= esc(lang('Employees.password_minlength')) ?></p>
    </div>

    <div class="field">
        <?= form_label(lang('Employees.repeat_password'), 'repeat_password', ['class' => 'form-label required']) ?>
        <div class="input-group">
            <span class="input-group-text"><span class="bi bi-lock" aria-hidden="true"></span></span>
            <?= form_password(['name' => 'repeat_password', 'id' => 'repeat_password', 'class' => 'form-control form-control-sm', 'autocomplete' => 'new-password']) ?>
        </div>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $.validator.setDefaults({
            ignore: []
        });

        $.validator.addMethod("notEqualTo", function(value, element, param) {
            return this.optional(element) || value != $(param).val();
        }, '<?= lang('Employees.password_not_must_match') ?>');

        $('#employee_form').validate($.extend({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        dialog_support.hide();
                        $.notify(response.message, {
                            type: response.success ? 'success' : 'danger'
                        });
                    },
                    dataType: 'json'
                });
            },

            rules: {
                current_password: {
                    required: true,
                    minlength: 8
                },
                password: {
                    required: true,
                    minlength: 8,
                    notEqualTo: "#current_password"
                },
                repeat_password: {
                    equalTo: "#password"
                }
            },

            messages: {
                password: {
                    required: "<?= lang('Employees.password_required') ?>",
                    minlength: "<?= lang('Employees.password_minlength') ?>"
                },
                repeat_password: {
                    equalTo: "<?= lang('Employees.password_must_match') ?>"
                }
            }
        }, form_support.error));
    });
</script>
