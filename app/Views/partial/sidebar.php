<?php
/**
 * @var object $user_info
 * @var array  $allowed_modules
 */

use Config\Services;

$request      = Services::request();
$activeModule = navigationActiveModule($request->getUri()->getSegment(1));
$initial      = mb_substr(trim($user_info->first_name . $user_info->last_name) ?: $user_info->username, 0, 1);
?>

<aside id="app-sidebar" class="app-sidebar offcanvas offcanvas-start" tabindex="-1" aria-label="<?= lang('Common.main_navigation') ?>">
    <div class="app-sidebar-head">
        <a class="app-brand" href="<?= site_url() ?>" aria-label="<?= BRAND_NAME ?>">
            <img class="app-brand-emblem" src="<?= assetUrl('img/emblem-white.webp') ?>" alt="" width="34" height="34">
            <img class="app-brand-wordmark" src="<?= assetUrl('img/wordmark-white.webp') ?>" alt="<?= BRAND_NAME ?>" height="26">
        </a>
        <button type="button" class="btn-close btn-close-white app-sidebar-close" data-bs-dismiss="offcanvas" data-bs-target="#app-sidebar" aria-label="<?= lang('Common.close') ?>"></button>
    </div>

    <nav class="app-nav">
        <?php foreach (navigationGroups($allowed_modules, $activeModule) as $groupIndex => $group): ?>
            <p class="app-nav-group" id="nav-group-<?= $groupIndex ?>"><span><?= esc($group['label']) ?></span></p>
            <ul class="app-nav-list" aria-labelledby="nav-group-<?= $groupIndex ?>">
                <?php foreach ($group['items'] as $item): ?>
                    <li>
                        <a class="app-nav-link<?= $item['active'] ? ' active' : '' ?>" href="<?= $item['url'] ?>" title="<?= esc($item['label'], 'attr') ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                            <i class="bi <?= $item['icon'] ?>" aria-hidden="true"></i>
                            <span class="app-nav-text"><?= esc($item['label']) ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>

    <div class="app-sidebar-foot">
        <?= anchor(
            "home/changePassword/{$user_info->person_id}",
            '<span class="app-avatar" aria-hidden="true">' . esc($initial) . '</span>'
                . '<span class="app-user"><span class="app-user-name">' . esc("{$user_info->first_name} {$user_info->last_name}") . '</span>'
                . '<span class="app-user-sub">' . esc($user_info->username) . '</span></span>',
            ['class' => 'app-user-link modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Employees.change_password')],
        ) ?>
        <a class="app-logout" href="<?= site_url('home/logout') ?>" title="<?= lang('Login.logout') ?>">
            <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
            <span class="visually-hidden"><?= lang('Login.logout') ?></span>
        </a>
    </div>
</aside>
