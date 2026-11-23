<?php
/**
 * @var string|null $error
 */
$error ??= null;
?>
<?= $this->extend('portal/layout') ?>

<?= $this->section('content') ?>
<div class="portal-auth">
    <div class="portal-auth-card">
        <form method="post" action="<?= esc(site_url('portal/login'), 'attr') ?>" id="portal_login_form" autocomplete="off">
            <?= csrf_field() ?>
            <span class="portal-auth-art" aria-hidden="true"><span class="bi bi-person-lock"></span></span>
            <h1 class="portal-auth-title"><?= esc(lang('Portal.login_title')) ?></h1>
            <?php if ($error !== null) : ?>
                <div class="alert alert-danger" role="alert" id="portal_error"><?= esc($error) ?></div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="username"><?= esc(lang('Portal.username')) ?></label>
                <input class="form-control" type="text" name="username" id="username" maxlength="50" dir="ltr" autocapitalize="none" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password"><?= esc(lang('Portal.password')) ?></label>
                <input class="form-control" type="password" name="password" id="password" maxlength="200" dir="ltr" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100"><?= esc(lang('Portal.sign_in')) ?></button>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
