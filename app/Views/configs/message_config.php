<?php
/**
 * @var array $config
 * @var bool  $msg_pwd_set
 */
?>

<?= form_open('config/saveMessage/', ['id' => 'message_config_form', 'enctype' => 'multipart/form-data', 'class' => 'app-form']) ?>
    <div id="config_wrapper">
        <fieldset id="config_info">

            <div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
            <ul id="message_error_message_box" class="error_message_box"></ul>

            <div class="row mb-3">
                <?= form_label(lang('Config.msg_uid'), 'msg_uid', ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <span class="bi bi-person" aria-hidden="true"></span>
                        </span>
                        <?= form_input([
                            'name'  => 'msg_uid',
                            'id'    => 'msg_uid',
                            'class' => 'form-control form-control-sm required',
                            'value' => $config['msg_uid'],
                        ]) ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Config.msg_pwd'), 'msg_pwd', ['class' => 'col-form-label col-3 col-md-2']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <span class="bi bi-lock" aria-hidden="true"></span>
                        </span>
                        <input type="password"
                               name="msg_pwd"
                               id="msg_pwd"
                               class="form-control form-control-sm"
                               placeholder="<?= ! empty($msg_pwd_set) ? lang('Config.msg_pwd_set') : esc(lang('Config.msg_pwd')) ?>"
                               autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Config.msg_src'), 'msg_src', ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <span class="bi bi-megaphone" aria-hidden="true"></span>
                        </span>
                        <?= form_input([
                            'name'  => 'msg_src',
                            'id'    => 'msg_src',
                            'class' => 'form-control form-control-sm required',
                            'value' => $config['msg_src'] === null ? $config['company'] : $config['msg_src'],
                        ]) ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Config.msg_msg'), 'msg_msg', ['class' => 'col-form-label col-3 col-md-2']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <?= form_textarea([
                        'name'        => 'msg_msg',
                        'id'          => 'msg_msg',
                        'class'       => 'form-control form-control-sm',
                        'value'       => $config['msg_msg'],
                        'style'       => 'resize: vertical',
                        'placeholder' => lang('Config.msg_msg_placeholder'),
                    ]) ?>
                </div>
            </div>

            <div class="settings-actions">
                <?= form_submit([
                    'name'  => 'submit_message',
                    'id'    => 'submit_message',
                    'value' => lang('Common.submit'),
                    'class' => 'btn btn-primary',
                ]) ?>
            </div>

        </fieldset>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $('#message_config_form').validate($.extend(form_support.handler, {

            errorLabelContainer: "#message_error_message_box",

            rules: {
                msg_uid: "required",
                msg_src: "required"
            },

            messages: {
                msg_uid: "<?= lang('Config.msg_uid_required') ?>",
                msg_src: "<?= lang('Config.msg_src_required') ?>"
            }
        }));
    });
</script>
