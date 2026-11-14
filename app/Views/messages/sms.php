<?= view('partial/header') ?>

<div class="sms-card p-4 bg-body-tertiary rounded-3">
    <?= form_open("messages/send/", ['id' => 'send_sms_form', 'enctype' => 'multipart/form-data', 'method' => 'post', 'class' => 'app-form']) ?>
        <fieldset>

            <legend class="text-center"><?= lang('Messages.sms_send') ?></legend>
            <div class="field">
                <label for="phone" class="form-label"><?= lang('Messages.phone') ?></label>
                <input class="form-control form-control-sm" type="text" id="phone" name="phone" placeholder="<?= lang('Messages.phone_placeholder') ?>">
                <span class="form-text"><?= lang('Messages.multiple_phones') ?></span>
            </div>

            <div class="field">
                <label for="message" class="form-label"><?= lang('Messages.message') ?></label>
                <textarea class="form-control form-control-sm" rows="3" id="message" name="message" placeholder="<?= lang('Messages.message_placeholder') ?>"></textarea>
            </div>

            <?= form_submit([
                'name'  => 'submit_form',
                'id'    => 'submit_form',
                'value' => lang('Common.submit'),
                'class' => 'btn btn-primary btn-sm'
            ]) ?>

        </fieldset>
    <?= form_close() ?>
</div>

<?= view('partial/footer') ?>

<script type="text/javascript">
    // Validation and submit handling
    $(document).ready(function() {
        $('#send_sms_form').validate({
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        $.notify({
                            message: response.message
                        }, {
                            type: response.success ? 'success' : 'danger'
                        })
                    },
                    dataType: 'json'
                });
            }
        });
    });
</script>
