<?php
/**
 * @var object $person_info
 */
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= BRAND_NAME . ' | ' . esc(lang('Employees.must_change_password_title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= assetUrl('img/favicon.ico') ?>">
    <link rel="stylesheet" href="<?= assetUrl(isRtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/vendor.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/login.css') ?>">
</head>

<body class="login-page">
    <div class="login-layout">
        <section class="login-brand">
            <img class="login-logo" src="<?= assetUrl('img/logo-full-white.webp') ?>" alt="<?= BRAND_NAME ?>">
            <p class="login-tagline"><?= BRAND_TAGLINE ?></p>
        </section>

        <main class="login-panel">
            <?= form_open('home/save', ['id' => 'change-form', 'class' => 'login-card', 'autocomplete' => 'off']) ?>
            <h1 class="login-heading"><?= esc(lang('Employees.must_change_password_title')) ?></h1>
            <div class="alert alert-warning" role="alert"><?= esc(lang('Employees.must_change_password')) ?></div>
            <div id="change-error" class="alert alert-danger d-none" role="alert"></div>

            <div class="login-fields">
                <input type="hidden" name="username" value="<?= esc($person_info->username, 'attr') ?>">
                <div class="form-floating">
                    <input class="form-control" id="current-password" name="current_password" type="password" placeholder="<?= esc(lang('Employees.current_password'), 'attr') ?>" autocomplete="current-password" required>
                    <label for="current-password"><?= esc(lang('Employees.current_password')) ?></label>
                </div>
                <div class="form-floating">
                    <input class="form-control" id="new-password" name="password" type="password" placeholder="<?= esc(lang('Employees.password'), 'attr') ?>" autocomplete="new-password" minlength="8" required>
                    <label for="new-password"><?= esc(lang('Employees.password')) ?></label>
                </div>
                <div class="form-floating">
                    <input class="form-control" id="repeat-password" name="repeat_password" type="password" placeholder="<?= esc(lang('Employees.repeat_password'), 'attr') ?>" autocomplete="new-password" minlength="8" required>
                    <label for="repeat-password"><?= esc(lang('Employees.repeat_password')) ?></label>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button class="btn btn-lg btn-primary" type="submit"><?= esc(lang('Common.submit')) ?></button>
                <a class="btn btn-link" href="<?= site_url('home/logout') ?>"><?= esc(lang('Login.logout')) ?></a>
            </div>
            <?= form_close() ?>
        </main>
    </div>

    <script src="<?= assetUrl('js/jquery.min.js') ?>"></script>
    <script>
        $('#change-form').on('submit', function(event) {
            event.preventDefault();
            const $error = $('#change-error');

            if ($('#new-password').val() !== $('#repeat-password').val()) {
                $error.text(<?= json_encode(lang('Employees.password_must_match')) ?>).removeClass('d-none');
                return;
            }

            $.post(this.action, $(this).serialize(), null, 'json')
                .done(function(response) {
                    if (response.success) {
                        window.location.href = <?= json_encode(site_url('home')) ?>;
                    } else {
                        $error.text(response.message).removeClass('d-none');
                    }
                })
                .fail(function() {
                    $error.text(<?= json_encode(lang('Employees.unsuccessful_change_password')) ?>).removeClass('d-none');
                });
        });
    </script>
</body>

</html>
