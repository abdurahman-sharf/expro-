<?php
/**
 * @var string|null           $error
 * @var array<string, string> $old
 */
$old ??= [];
$value = static fn (string $name): string => esc($old[$name] ?? '', 'attr');
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= BRAND_NAME . ' | ' . esc(lang('Owner_setup.title')) ?></title>
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
            <?= form_open('owner_setup', ['id' => 'owner-form', 'class' => 'login-card', 'autocomplete' => 'off']) ?>
            <h1 class="login-heading"><?= esc(lang('Owner_setup.title')) ?></h1>
            <p class="text-secondary"><?= esc(lang('Owner_setup.intro')) ?></p>

            <?php if ($error !== null): ?>
                <div class="alert alert-danger" id="owner-error" role="alert"><?= esc($error) ?></div>
            <?php endif; ?>

            <div class="login-fields">
                <div class="form-floating">
                    <input class="form-control" id="owner-code" name="code" type="text" dir="ltr" autocomplete="off" spellcheck="false" placeholder="<?= esc(lang('Owner_setup.code_label'), 'attr') ?>" required>
                    <label for="owner-code"><?= esc(lang('Owner_setup.code_label')) ?></label>
                </div>
                <p class="form-text"><?= esc(lang('Owner_setup.code_help')) ?></p>

                <div class="form-floating">
                    <input class="form-control" id="owner-first" name="first_name" type="text" maxlength="50" autocomplete="given-name" value="<?= $value('first_name') ?>" placeholder="<?= esc(lang('Owner_setup.first_name'), 'attr') ?>" required>
                    <label for="owner-first"><?= esc(lang('Owner_setup.first_name')) ?></label>
                </div>
                <div class="form-floating">
                    <input class="form-control" id="owner-last" name="last_name" type="text" maxlength="50" autocomplete="family-name" value="<?= $value('last_name') ?>" placeholder="<?= esc(lang('Owner_setup.last_name'), 'attr') ?>" required>
                    <label for="owner-last"><?= esc(lang('Owner_setup.last_name')) ?></label>
                </div>
                <div class="form-floating">
                    <input class="form-control" id="owner-username" name="username" type="text" dir="ltr" maxlength="32" autocomplete="username" value="<?= $value('username') ?>" placeholder="<?= esc(lang('Owner_setup.username'), 'attr') ?>" required>
                    <label for="owner-username"><?= esc(lang('Owner_setup.username')) ?></label>
                </div>
                <p class="form-text"><?= esc(lang('Owner_setup.username_help')) ?></p>
                <div class="form-floating">
                    <input class="form-control" id="owner-email" name="email" type="email" dir="ltr" autocomplete="email" value="<?= $value('email') ?>" placeholder="<?= esc(lang('Owner_setup.email'), 'attr') ?>" required>
                    <label for="owner-email"><?= esc(lang('Owner_setup.email')) ?></label>
                </div>
                <div class="form-floating">
                    <input class="form-control" id="owner-password" name="password" type="password" autocomplete="new-password" minlength="8" placeholder="<?= esc(lang('Owner_setup.password'), 'attr') ?>" required>
                    <label for="owner-password"><?= esc(lang('Owner_setup.password')) ?></label>
                </div>
                <div class="form-floating">
                    <input class="form-control" id="owner-password-confirm" name="password_confirm" type="password" autocomplete="new-password" minlength="8" placeholder="<?= esc(lang('Owner_setup.password_confirm'), 'attr') ?>" required>
                    <label for="owner-password-confirm"><?= esc(lang('Owner_setup.password_confirm')) ?></label>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button class="btn btn-lg btn-primary" type="submit"><?= esc(lang('Owner_setup.submit')) ?></button>
            </div>
            <?= form_close() ?>
        </main>
    </div>
</body>

</html>
