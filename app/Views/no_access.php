<?php
/**
 * @var string      $module_name
 * @var string|null $permission_id
 * @var object|null $user_info
 */
$inShell = isset($user_info);
?>
<section class="empty-state no-access" role="alert">
    <span class="empty-state-art" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
    <h2 class="empty-state-title"><?= esc(lang('Error.no_permission_module')) ?></h2>
    <p class="empty-state-text"><?= esc(lang('Error.no_access_text', [(string) $module_name])) ?></p>
    <?php if (! empty($permission_id)): ?>
        <p class="empty-state-meta"><bdi dir="ltr"><?= esc(lang('Error.no_access_permission', [(string) $permission_id])) ?></bdi></p>
    <?php endif; ?>
    <?php if ($inShell): ?>
        <div class="empty-state-actions">
            <a class="btn btn-primary" href="<?= site_url('home') ?>"><i class="bi bi-house" aria-hidden="true"></i> <?= esc(lang('Error.back_home')) ?></a>
            <button type="button" class="btn btn-outline-secondary" onclick="history.back()"><?= esc(lang('Error.go_back')) ?></button>
        </div>
    <?php endif; ?>
</section>
<script>document.getElementById('submit')?.setAttribute('disabled', 'disabled');</script>
