<?php

use App\Libraries\Portal\PortalPasswords;

/**
 * @var string|null $error
 * @var bool        $forced
 */
$error ??= null;
$forced ??= false;
?>
<?= $this->extend('portal/layout') ?>

<?= $this->section('content') ?>
<div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-4">
        <form method="post" action="<?= esc(site_url('portal/password'), 'attr') ?>" class="card card-body shadow-sm" id="portal_password_form" autocomplete="off">
            <?= csrf_field() ?>
            <h1 class="h4 mb-3"><?= esc(lang('Portal.change_title')) ?></h1>
            <?php if ($forced) : ?>
                <div class="alert alert-warning" role="alert" id="portal_forced"><?= esc(lang('Portal.change_required')) ?></div>
            <?php endif; ?>
            <?php if ($error !== null) : ?>
                <div class="alert alert-danger" role="alert" id="portal_error"><?= esc($error) ?></div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label" for="current_password"><?= esc(lang('Portal.current_password')) ?></label>
                <input class="form-control" type="password" name="current_password" id="current_password" maxlength="200" dir="ltr" autocomplete="current-password" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="new_password"><?= esc(lang('Portal.new_password')) ?></label>
                <input class="form-control" type="password" name="new_password" id="new_password" maxlength="200" dir="ltr" autocomplete="new-password" required>
                <div class="form-text"><?= esc(lang('Portal.password_hint', [PortalPasswords::MIN_LENGTH, PortalPasswords::MAX_BYTES])) ?></div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="confirm_password"><?= esc(lang('Portal.confirm_password')) ?></label>
                <input class="form-control" type="password" name="confirm_password" id="confirm_password" maxlength="200" dir="ltr" autocomplete="new-password" required>
            </div>
            <button type="submit" class="btn btn-primary"><?= esc(lang('Portal.save')) ?></button>
        </form>
    </div>
</div>
<?= $this->endSection() ?>
