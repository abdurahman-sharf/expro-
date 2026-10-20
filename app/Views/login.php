<?php
/**
 * @var bool $hasErrors
 * @var bool $isLatest
 * @var bool $isNewInstall
 * @var string $latestVersion
 * @var bool $gcaptchaEnabled
 * @var array $config
 * @var $validation
 */

$formStyle = $config['login_form'] ?? '';
?>

<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= text_direction() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc($config['company']) . ' | ' . BRAND_NAME . ' | ' . esc(lang('Login.login')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#042359">
    <link rel="icon" href="<?= asset_url('img/favicon.ico') ?>">
    <link rel="apple-touch-icon" href="<?= asset_url('img/apple-touch-icon.png') ?>">
    <link rel="stylesheet" href="<?= asset_url(is_rtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('css/vendor.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('css/login.css') ?>">
</head>

<body class="login-page">
    <div class="login-layout">
        <section class="login-brand">
            <img class="login-logo" src="<?= asset_url('img/logo-full-white.webp') ?>" alt="<?= BRAND_NAME ?>">
            <p class="login-tagline"><?= BRAND_TAGLINE ?></p>
        </section>

        <main class="login-panel">
            <?= form_open('login', ['id' => 'login-form', 'class' => 'login-card']) ?>

            <?php if (!empty($config['company_logo'])): ?>
                <img class="login-company-logo" src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="<?= esc(lang('Common.logo') . ' ' . $config['company']) ?>">
            <?php endif; ?>

            <h1 id="form-heading" class="login-heading">
                <?php if ($isNewInstall): ?>
                    <?= lang('Login.initialization_required') ?>
                <?php elseif (!$isLatest): ?>
                    <?= lang('Login.migration_required') ?>
                <?php else: ?>
                    <?= lang('Login.welcome', [BRAND_NAME]) ?>
                <?php endif; ?>
            </h1>

            <div id="migration-warning" class="alert alert-warning<?= ($isNewInstall || !$isLatest) ? '' : ' d-none' ?>">
                <strong>
                    <?php if ($isNewInstall): ?>
                        <?= lang('Login.initialization_message') ?>
                    <?php else: ?>
                        <?= lang('Login.migration_auth_message', [$latestVersion]) ?>
                    <?php endif; ?>
                </strong>
            </div>

            <?php if ($hasErrors): ?>
                <?php foreach ($validation->getErrors() as $error): ?>
                    <div class="alert alert-danger" role="alert">
                        <?= $error ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div id="migration-success" class="alert alert-success d-none">
                <strong><?= lang('Login.migration_complete') ?></strong> <?= lang('Login.migration_complete_login') ?>
            </div>

            <div id="migration-progress" class="d-none">
                <h2 class="h5 text-center mb-3"><?= lang('Login.migration_initializing') ?></h2>
                <div class="progress mb-3" style="height: 24px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" role="progressbar" style="width: 100%"></div>
                </div>
                <p class="text-center text-secondary" id="migration-status">
                    <?= lang('Login.migration_running') ?>
                </p>
            </div>

            <div id="migration-error" class="alert alert-danger d-none" role="alert">
                <span id="migration-error-message"></span>
            </div>

            <div id="login-fields" class="login-fields<?= $isNewInstall ? ' d-none' : '' ?>">
                <?php if ($formStyle === 'input_groups'): ?>
                    <div>
                        <label class="form-label" for="input-username"><?= lang('Login.username') ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-person" aria-hidden="true"></i></span>
                            <input class="form-control" id="input-username" name="username" type="text" autocomplete="username" <?php if (ENVIRONMENT == 'testing') echo 'value="admin"'; ?>>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" for="input-password"><?= lang('Login.password') ?></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-key" aria-hidden="true"></i></span>
                            <input class="form-control" id="input-password" name="password" type="password" autocomplete="current-password" <?php if (ENVIRONMENT == 'testing') echo 'value="pointofsale"'; ?>>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-floating">
                        <input class="form-control" id="input-username" name="username" type="text" placeholder="<?= lang('Login.username') ?>" autocomplete="username" <?php if (ENVIRONMENT == 'testing') echo 'value="admin"'; ?>>
                        <label for="input-username"><?= lang('Login.username') ?></label>
                    </div>
                    <div class="form-floating">
                        <input class="form-control" id="input-password" name="password" type="password" placeholder="<?= lang('Login.password') ?>" autocomplete="current-password" <?php if (ENVIRONMENT == 'testing') echo 'value="pointofsale"'; ?>>
                        <label for="input-password"><?= lang('Login.password') ?></label>
                    </div>
                <?php endif; ?>

                <?php if ($gcaptchaEnabled): ?>
                    <script src="https://www.google.com/recaptcha/api.js"></script>
                    <div class="g-recaptcha" style="text-align: center;" data-sitekey="<?= esc($config['gcaptcha_site_key']) ?>"></div>
                <?php endif; ?>
            </div>

            <div class="d-grid">
                <button id="submit-button" class="btn btn-lg btn-primary" name="login-button" type="submit">
                    <?php if ($isNewInstall): ?>
                        <?= lang('Login.initialize') ?>
                    <?php elseif (!$isLatest): ?>
                        <?= lang('Module.migrate') ?>
                    <?php else: ?>
                        <?= lang('Login.go') ?>
                    <?php endif; ?>
                </button>
            </div>

            <p class="login-foot"><?= BRAND_NAME ?> · <?= BRAND_TAGLINE ?></p>
            <?= form_close() ?>
        </main>
    </div>

    <script src="<?= asset_url('js/jquery.min.js') ?>"></script>
    <script>
        // @noinspection JSInitializingVariableWithUndefined
        const APP_STATE = {
            isNewInstall: <?= $isNewInstall ? 'true' : 'false' ?>,
            isLatest: <?= $isLatest ? 'true' : 'false' ?>,
            csrfToken: '<?= csrf_token() ?>',
            csrfHash: '<?= csrf_hash() ?>',
            migrateUrl: '<?= site_url('migrate') ?>',
            loginUrl: '<?= site_url('login') ?>',
            i18n: {
                welcome: <?= json_encode(lang('Login.welcome', [BRAND_NAME])) ?>,
                initialize: <?= json_encode(lang('Login.initialize')) ?>,
                initializationRequired: <?= json_encode(lang('Login.initialization_required')) ?>,
                initializationMessage: <?= json_encode(lang('Login.initialization_message')) ?>,
                migrate: <?= json_encode(lang('Module.migrate')) ?>,
                go: <?= json_encode(lang('Login.go')) ?>,
                migrationRequired: <?= json_encode(lang('Login.migration_required')) ?>,
                migrationInitializing: <?= json_encode(lang('Login.migration_initializing')) ?>,
                migratingDatabase: <?= json_encode(lang('Login.migrating_database')) ?>,
                migrationRunning: <?= json_encode(lang('Login.migration_running')) ?>,
                migrationComplete: <?= json_encode(lang('Login.migration_complete')) ?>,
                migrationCompleteMigrate: <?= json_encode(lang('Login.migration_complete_migrate')) ?>,
                migrationCompleteLogin: <?= json_encode(lang('Login.migration_complete_login')) ?>,
                migrationFailed: <?= json_encode(lang('Login.migration_failed')) ?>,
                migrationErrorConnection: <?= json_encode(lang('Login.migration_error_connection')) ?>
            }
        };

        $(document).ready(function() {
            const $form = $('#login-form');
            const $heading = $('#form-heading');
            const $warning = $('#migration-warning');
            const $success = $('#migration-success');
            const $progress = $('#migration-progress');
            const $error = $('#migration-error');
            const $errorMessage = $('#migration-error-message');
            const $loginFields = $('#login-fields');
            const $submitButton = $('#submit-button');

            function showMigrationRequired() {
                if (APP_STATE.isNewInstall) {
                    $heading.text(APP_STATE.i18n.initializationRequired);
                    $submitButton.text(APP_STATE.i18n.initialize);
                    $loginFields.addClass('d-none');
                } else {
                    $heading.text(APP_STATE.i18n.migrationRequired);
                    $submitButton.text(APP_STATE.i18n.migrate);
                    $loginFields.removeClass('d-none');
                }
                $warning.removeClass('d-none');
                $success.addClass('d-none');
                $progress.addClass('d-none');
                $error.addClass('d-none');
            }

            function showMigrationProgress() {
                $warning.addClass('d-none');
                $success.addClass('d-none');
                $error.addClass('d-none');
                $loginFields.addClass('d-none');
                $progress.find('h3').text(APP_STATE.isNewInstall ? APP_STATE.i18n.migrationInitializing : APP_STATE.i18n.migratingDatabase);
                $progress.removeClass('d-none');
                $submitButton.prop('disabled', true);
            }

            function showMigrationSuccess() {
                $progress.addClass('d-none');
                $error.addClass('d-none');
                $warning.addClass('d-none');
                $success.find('strong').text(APP_STATE.isNewInstall ? APP_STATE.i18n.migrationComplete : APP_STATE.i18n.migrationCompleteMigrate);
                $success.removeClass('d-none');
                $heading.text(APP_STATE.i18n.welcome);
                $loginFields.removeClass('d-none');
                $submitButton.text(APP_STATE.i18n.go);
                $submitButton.prop('disabled', false);
            }

            function showMigrationError(message) {
                $progress.addClass('d-none');
                $success.addClass('d-none');
                $errorMessage.text(message);
                $error.removeClass('d-none');
                $warning.addClass('d-none');
                if (APP_STATE.isNewInstall) {
                    $loginFields.addClass('d-none');
                    $submitButton.text(APP_STATE.i18n.initialize);
                } else {
                    $loginFields.removeClass('d-none');
                    $submitButton.text(APP_STATE.i18n.migrate);
                }
                $submitButton.prop('disabled', false);
            }

            function showLoginForm() {
                $heading.text(APP_STATE.i18n.welcome);
                $warning.addClass('d-none');
                $progress.addClass('d-none');
                $error.addClass('d-none');
                $success.addClass('d-none');
                $loginFields.removeClass('d-none');
                $submitButton.text(APP_STATE.i18n.go);
            }

            if (!APP_STATE.isNewInstall && APP_STATE.isLatest) {
                showLoginForm();
            } else {
                showMigrationRequired();
            }

            $form.on('submit', function(e) {
                if (APP_STATE.isNewInstall || !APP_STATE.isLatest) {
                    e.preventDefault();

                    showMigrationProgress();

                    $.ajax({
                        url: APP_STATE.migrateUrl,
                        type: 'POST',
                        dataType: 'json',
                        timeout: 3600000,
                        data: {
                            [APP_STATE.csrfToken]: APP_STATE.csrfHash,
                            username: $('#input-username').val(),
                            password: $('#input-password').val(),
                        },
                        success: function(response) {
                            if (response.success) {
                                APP_STATE.isNewInstall = false;
                                APP_STATE.isLatest = true;
                                showMigrationSuccess();
                            } else {
                                showMigrationError(response.message || APP_STATE.i18n.migrationFailed);
                            }
                        },
                        error: function(xhr, status, error) {
                            let message = APP_STATE.i18n.migrationErrorConnection;
                            if (xhr.responseJSON && xhr.responseJSON.message) {
                                message = xhr.responseJSON.message;
                            }
                            showMigrationError(message);
                        }
                    });
                }
            });
        });
    </script>
</body>

</html>
