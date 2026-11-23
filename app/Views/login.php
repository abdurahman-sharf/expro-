<?php
/**
 * @var bool        $hasErrors
 * @var bool        $isLatest
 * @var bool        $isNewInstall
 * @var string      $latestVersion
 * @var bool        $gcaptchaEnabled
 * @var array       $config
 * @var string|null $migrationRefusal
 * @var string|null $throttled
 */
$topLabels = ($config['login_form'] ?? '') === 'input_groups';
$mode      = $isNewInstall ? 'init' : ($isLatest ? 'login' : 'upgrade');
$errors    = $hasErrors ? $validation->getErrors() : [];
$required  = isset($errors['username']) && $errors['username'] === lang('Login.required_username');
$general   = array_values(array_filter($errors, static fn (string $error): bool => $error !== lang('Login.required_username')));
$testValue = static fn (string $value): string => ENVIRONMENT === 'testing' ? ' value="' . $value . '"' : '';
$titles    = ['login' => lang('Login.welcome', [BRAND_NAME]), 'init' => lang('Login.initialization_required'), 'upgrade' => lang('Login.migration_required')];
$buttons   = ['login' => lang('Login.go'), 'init' => lang('Login.initialize'), 'upgrade' => lang('Login.upgrade')];
?>

<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc($config['company']) . ' | ' . BRAND_NAME . ' | ' . esc(lang('Login.login')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0755cc">
    <link rel="icon" href="<?= assetUrl('img/favicon.ico') ?>">
    <link rel="apple-touch-icon" href="<?= assetUrl('img/apple-touch-icon.png') ?>">
    <link rel="stylesheet" href="<?= assetUrl(isRtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/vendor.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/login.css') ?>">
</head>

<body class="login-page">
    <div class="login-layout">
        <div class="login-side">
            <main class="login-card">
                <?= form_open('login', ['id' => 'login-form', 'class' => 'login-form', 'novalidate' => 'novalidate']) ?>

                <?php if (! empty($config['company_logo'])): ?>
                    <div class="login-company">
                        <img src="<?= base_url('uploads/' . esc($config['company_logo'], 'url')) ?>" alt="<?= esc(lang('Common.logo') . ' ' . $config['company']) ?>">
                    </div>
                <?php endif; ?>

                <h1 id="form-heading" class="login-heading"><?= $titles[$mode] ?></h1>

                <div id="login-alerts">
                    <?php if ($mode === 'init'): ?>
                        <div id="migration-warning" class="login-alert is-info" role="status"><i class="bi bi-database" aria-hidden="true"></i><p><?= lang('Login.initialization_message') ?></p></div>
                    <?php elseif ($mode === 'upgrade'): ?>
                        <div id="migration-warning" class="login-alert is-warning" role="status"><i class="bi bi-database-up" aria-hidden="true"></i><p><?= lang('Login.migration_auth_message', ['<bdi dir="ltr">' . esc($latestVersion) . '</bdi>']) ?></p></div>
                    <?php endif; ?>

                    <?php if (! empty($throttled)): ?>
                        <div id="login-throttled" class="login-alert is-warning" role="alert"><i class="bi bi-clock-history" aria-hidden="true"></i><p><?= esc($throttled) ?></p></div>
                    <?php endif; ?>

                    <?php foreach ($general as $error): ?>
                        <div class="login-alert is-danger login-error" role="alert"><i class="bi bi-x-circle" aria-hidden="true"></i><p><?= esc($error) ?></p></div>
                    <?php endforeach; ?>

                    <?php if (! empty($migrationRefusal)): ?>
                        <div id="migration-refusal" class="login-alert is-danger" role="alert"><i class="bi bi-x-circle" aria-hidden="true"></i><p><?= esc($migrationRefusal) ?></p></div>
                    <?php endif; ?>

                    <div id="migration-success" class="login-alert is-success" role="status" hidden><i class="bi bi-check-circle" aria-hidden="true"></i><p></p></div>
                    <div id="migration-error" class="login-alert is-danger" role="alert" hidden><i class="bi bi-x-circle" aria-hidden="true"></i><p id="migration-error-message"></p></div>
                </div>

                <div id="migration-progress" class="login-progress" role="status" aria-live="polite" hidden>
                    <p class="login-progress-title"><i class="bi bi-arrow-repeat login-spin" aria-hidden="true"></i> <span id="migration-progress-title"></span></p>
                    <div class="login-progress-bar" role="progressbar" aria-labelledby="migration-progress-title"><span></span></div>
                    <p class="login-progress-step" id="migration-status"><?= lang('Login.migration_running') ?></p>
                    <p class="login-progress-hint"><?= lang('Login.migration_keep_open') ?></p>
                </div>

                <div id="login-fields" class="login-fields"<?= $mode === 'init' ? ' hidden' : '' ?>>
                    <?php if ($topLabels): ?>
                        <div class="login-field<?= $required ? ' has-error' : '' ?>">
                            <label class="login-label" for="input-username"><?= lang('Login.username') ?></label>
                            <div class="login-control">
                                <i class="bi bi-person" aria-hidden="true"></i>
                                <input id="input-username" name="username" type="text" dir="auto" autocomplete="username" aria-describedby="username-error"<?= $required ? ' aria-invalid="true"' : '' ?><?= $testValue('admin') ?>>
                            </div>
                            <p class="login-field-error" id="username-error"<?= $required ? '' : ' hidden' ?>><i class="bi bi-exclamation-circle" aria-hidden="true"></i> <?= lang('Login.required_username') ?></p>
                        </div>
                        <div class="login-field">
                            <label class="login-label" for="input-password"><?= lang('Login.password') ?></label>
                            <div class="login-control">
                                <i class="bi bi-key" aria-hidden="true"></i>
                                <input id="input-password" name="password" type="password" autocomplete="current-password"<?= $testValue('pointofsale') ?>>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="login-field<?= $required ? ' has-error' : '' ?>">
                            <div class="login-floating">
                                <input id="input-username" name="username" type="text" dir="auto" placeholder=" " autocomplete="username" aria-describedby="username-error"<?= $required ? ' aria-invalid="true"' : '' ?><?= $testValue('admin') ?>>
                                <label for="input-username"><?= lang('Login.username') ?></label>
                            </div>
                            <p class="login-field-error" id="username-error"<?= $required ? '' : ' hidden' ?>><i class="bi bi-exclamation-circle" aria-hidden="true"></i> <?= lang('Login.required_username') ?></p>
                        </div>
                        <div class="login-field">
                            <div class="login-floating">
                                <input id="input-password" name="password" type="password" placeholder=" " autocomplete="current-password"<?= $testValue('pointofsale') ?>>
                                <label for="input-password"><?= lang('Login.password') ?></label>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($gcaptchaEnabled): ?>
                        <script src="https://www.google.com/recaptcha/api.js"></script>
                        <div class="login-captcha g-recaptcha" data-sitekey="<?= esc($config['gcaptcha_site_key']) ?>"></div>
                    <?php endif; ?>
                </div>

                <button id="submit-button" class="btn btn-primary login-submit" name="login-button" type="submit">
                    <i class="bi bi-arrow-repeat login-spin" aria-hidden="true" hidden></i>
                    <span class="login-submit-label"><?= $buttons[$mode] ?></span>
                </button>

                <?= form_close() ?>
            </main>
            <p class="login-foot"><?= BRAND_NAME ?> · <?= BRAND_TAGLINE ?></p>
        </div>

        <aside class="login-brand" aria-label="<?= BRAND_NAME ?>">
            <img class="login-logo" src="<?= assetUrl('img/logo-full-white.webp') ?>" alt="<?= BRAND_NAME ?>">
            <p class="login-tagline"><?= BRAND_TAGLINE ?></p>
        </aside>
    </div>

    <script id="login-state" type="application/json"><?= json_encode([
        'mode'       => $mode,
        'invalid'    => $general !== [],
        'csrfToken'  => csrf_token(),
        'csrfHash'   => csrf_hash(),
        'migrateUrl' => site_url('migrate'),
        'i18n'       => [
            'welcome'                  => lang('Login.welcome', [BRAND_NAME]),
            'go'                       => lang('Login.go'),
            'checking'                 => lang('Login.checking'),
            'initializing'             => lang('Login.initializing'),
            'upgrading'                => lang('Login.upgrading'),
            'initialize'               => lang('Login.initialize'),
            'migrate'                  => lang('Login.upgrade'),
            'migrationInitializing'    => lang('Login.migration_initializing'),
            'migratingDatabase'        => lang('Login.migrating_database'),
            'migrationCompleteMigrate' => lang('Login.migration_complete_migrate') . ' ' . lang('Login.migration_complete_login'),
            'migrationFailed'          => lang('Login.migration_failed'),
            'migrationErrorConnection' => lang('Login.migration_error_connection'),
        ],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <script src="<?= assetUrl('js/jquery.min.js') ?>"></script>
    <script src="<?= assetUrl('js/login.js') ?>"></script>
</body>

</html>
