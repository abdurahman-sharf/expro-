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

<body class="login-page claim-page">
    <div class="login-layout">
        <div class="login-side">
            <main class="login-card">
                <div class="claim-head">
                    <span class="claim-eyebrow"><i class="bi bi-shield-lock" aria-hidden="true"></i> <?= esc(lang('Employees.password_security')) ?></span>
                    <h1 class="login-heading"><?= esc(lang('Employees.must_change_password_title')) ?></h1>
                    <p class="claim-intro"><?= esc(lang('Employees.must_change_password')) ?></p>
                </div>

                <div id="change-error" class="login-alert is-danger" role="alert" hidden><i class="bi bi-x-circle" aria-hidden="true"></i><p></p></div>

                <?= form_open('home/save', ['id' => 'change-form', 'class' => 'claim-form', 'autocomplete' => 'off', 'novalidate' => 'novalidate']) ?>
                <input type="hidden" name="username" value="<?= esc($person_info->username, 'attr') ?>">
                <div class="login-account">
                    <span class="login-account-avatar" aria-hidden="true"><?= esc(mb_substr(trim($person_info->first_name . $person_info->last_name) ?: $person_info->username, 0, 1)) ?></span>
                    <span><b><?= esc(trim($person_info->first_name . ' ' . $person_info->last_name)) ?></b><small dir="ltr"><?= esc($person_info->username) ?></small></span>
                </div>
                <div class="login-field">
                    <label class="login-label" for="current-password"><?= esc(lang('Employees.current_password')) ?></label>
                    <div class="login-control"><i class="bi bi-key" aria-hidden="true"></i><input id="current-password" name="current_password" type="password" autocomplete="current-password" required></div>
                </div>
                <div class="claim-pair">
                    <div class="login-field">
                        <label class="login-label" for="new-password"><?= esc(lang('Employees.password')) ?></label>
                        <div class="login-control"><i class="bi bi-shield-lock" aria-hidden="true"></i><input id="new-password" name="password" type="password" autocomplete="new-password" minlength="8" aria-describedby="new-password-help" required></div>
                        <p class="login-help" id="new-password-help"><?= esc(lang('Owner_setup.password_help')) ?></p>
                    </div>
                    <div class="login-field" id="repeat-field">
                        <label class="login-label" for="repeat-password"><?= esc(lang('Employees.repeat_password')) ?></label>
                        <div class="login-control"><i class="bi bi-shield-check" aria-hidden="true"></i><input id="repeat-password" name="repeat_password" type="password" autocomplete="new-password" minlength="8" required></div>
                    </div>
                </div>
                <button class="btn btn-primary login-submit" id="change-submit" type="submit">
                    <i class="bi bi-arrow-repeat login-spin" aria-hidden="true" hidden></i>
                    <span class="login-submit-label"><?= esc(lang('Employees.change_password')) ?></span>
                </button>
                <?= form_close() ?>
                <a class="setup-logout-link" href="<?= site_url('home/logout') ?>"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> <?= esc(lang('Login.logout')) ?></a>
            </main>
            <p class="login-foot"><?= BRAND_NAME ?> · <?= BRAND_TAGLINE ?></p>
        </div>

        <aside class="login-brand" aria-label="<?= BRAND_NAME ?>">
            <img class="login-logo" src="<?= assetUrl('img/logo-full-white.webp') ?>" alt="<?= BRAND_NAME ?>">
            <p class="login-tagline"><?= BRAND_TAGLINE ?></p>
        </aside>
    </div>

    <script src="<?= assetUrl('js/jquery.min.js') ?>"></script>
    <script>
        $('#current-password').trigger('focus');

        $('#change-form').on('submit', function(event) {
            event.preventDefault();
            const $error = $('#change-error');
            const $button = $('#change-submit');
            const fail = function(message, field) {
                $error.prop('hidden', false).find('p').text(message);
                $button.prop('disabled', false).find('.login-spin').prop('hidden', true);
                $('.login-field').removeClass('has-error');

                if (field) {
                    $(field).closest('.login-field').addClass('has-error');
                }

                $('#current-password, #new-password, #repeat-password').val('');
                $('#current-password').trigger('focus');
            };

            if ($('#new-password').val() !== $('#repeat-password').val()) {
                fail(<?= json_encode(lang('Employees.password_must_match')) ?>, '#repeat-password');
                return;
            }

            $button.prop('disabled', true).find('.login-spin').prop('hidden', false);

            $.post(this.action, $(this).serialize(), null, 'json')
                .done(function(response) {
                    if (response.success) {
                        window.location.href = <?= json_encode(site_url('home')) ?>;
                    } else {
                        fail(response.message);
                    }
                })
                .fail(function() {
                    fail(<?= json_encode(lang('Employees.unsuccessful_change_password')) ?>);
                });
        });
    </script>
</body>

</html>
