<?php
/**
 * @var bool   $logo_exists
 * @var string $controller_name
 * @var array  $config
 */
?>

<?= form_open('config/saveInfo/', ['id' => 'info_config_form', 'enctype' => 'multipart/form-data', 'class' => 'app-form']) ?>
    <div id="config_wrapper">
        <fieldset id="config_info">

            <div id="required_fields_message"><?= lang('Common.fields_required_message') ?></div>
            <ul id="info_error_message_box" class="error_message_box"></ul>

            <div class="row mb-3">
                <?= form_label(lang('Config.company'), 'company', ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <span class="bi bi-house" aria-hidden="true"></span>
                        </span>
                        <?= form_input([
                            'name'  => 'company',
                            'id'    => 'company',
                            'class' => 'form-control form-control-sm required',
                            'value' => $config['company'],
                        ]) ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Config.company_logo'), 'company_logo', ['class' => 'col-form-label col-3 col-md-2']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="image-upload">
                        <img class="image-upload-preview<?= $logo_exists ? '' : ' d-none' ?>" alt="<?= esc(lang('Config.company_logo')) ?>" src="<?= esc($logo_src, 'attr') ?>">
                        <input type="file" class="form-control form-control-sm" id="company_logo" name="company_logo" accept="image/*" data-preview=".image-upload-preview">
                        <button type="button" class="btn btn-outline-danger btn-sm image-upload-remove<?= $logo_exists ? '' : ' d-none' ?>"><?= lang('Config.company_remove_image') ?></button>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Config.address'), 'address', ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <?= form_textarea([
                        'name'  => 'address',
                        'id'    => 'address',
                        'class' => 'form-control form-control-sm required',
                        'style' => 'resize: vertical',
                        'value' => $config['address'],
                    ]) ?>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Config.website'), 'website', ['class' => 'col-form-label col-3 col-md-2']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <span class="bi bi-globe" aria-hidden="true"></span>
                        </span>
                        <?= form_input([
                            'name'  => 'website',
                            'id'    => 'website',
                            'class' => 'form-control form-control-sm',
                            'value' => $config['website'],
                        ]) ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Common.email'), 'email', ['class' => 'col-form-label col-3 col-md-2']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <span class="bi bi-envelope" aria-hidden="true"></span>
                        </span>
                        <?= form_input([
                            'name'  => 'email',
                            'id'    => 'email',
                            'type'  => 'email',
                            'class' => 'form-control form-control-sm',
                            'value' => $config['email'],
                        ]) ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Config.phone'), 'phone', ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <span class="bi bi-telephone" aria-hidden="true"></span>
                        </span>
                        <?= form_input([
                            'type'  => 'tel',
                            'name'  => 'phone',
                            'id'    => 'phone',
                            'class' => 'form-control form-control-sm required',
                            'value' => $config['phone'],
                        ]) ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Config.fax'), 'fax', ['class' => 'col-form-label col-3 col-md-2']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <div class="input-group">
                        <span class="input-group-text">
                            <span class="bi bi-telephone" aria-hidden="true"></span>
                        </span>
                        <?= form_input([
                            'type'  => 'tel',
                            'name'  => 'fax',
                            'id'    => 'fax',
                            'class' => 'form-control form-control-sm',
                            'value' => $config['fax'],
                        ]) ?>
                    </div>
                </div>
            </div>

            <div class="row mb-3">
                <?= form_label(lang('Common.return_policy'), 'return_policy', ['class' => 'col-form-label col-3 col-md-2 text-danger required']) ?>
                <div class="col-9 col-md-6 col-lg-5 col-xl-4">
                    <?= form_textarea([
                        'name'  => 'return_policy',
                        'id'    => 'return_policy',
                        'class' => 'form-control form-control-sm required',
                        'style' => 'resize: vertical',
                        'value' => $config['return_policy'],
                    ]) ?>
                </div>
            </div>

            <div class="settings-actions">
                <?= form_submit([
                    'name'  => 'submit_info',
                    'id'    => 'submit_info',
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
        $('.image-upload-remove').click(function() {
            $.ajax({
                type: 'POST',
                url: '<?= "{$controller_name}/removeLogo"; ?>',
                dataType: 'json'
            })
        });

        $('#info_config_form').validate($.extend(form_support.handler, {

            errorLabelContainer: "#info_error_message_box",

            rules: {
                company: "required",
                address: "required",
                phone: "required",
                email: "email",
                return_policy: "required"
            },

            messages: {
                company: "<?= lang('Config.company_required') ?>",
                address: "<?= lang('Config.address_required') ?>",
                phone: "<?= lang('Config.phone_required') ?>",
                email: "<?= lang('Common.email_invalid_format') ?>",
                return_policy: "<?= lang('Config.return_policy_required') ?>"
            }
        }));
    });
</script>
