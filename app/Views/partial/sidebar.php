<?php
/**
 * @var object $user_info
 * @var array  $allowed_modules
 */
$sections = navigationSections($allowed_modules, uri_string(), (int) $user_info->person_id, (string) service('request')->getUri()->getQuery());
$initial  = mb_substr(trim($user_info->first_name . $user_info->last_name) ?: $user_info->username, 0, 1);
?>

<aside id="app-sidebar" class="app-sidebar offcanvas offcanvas-start" tabindex="-1" aria-label="<?= lang('Common.main_navigation') ?>">
    <div class="app-sidebar-head">
        <a class="app-brand" href="<?= site_url() ?>" aria-label="<?= BRAND_NAME ?>">
            <img class="app-brand-emblem" src="<?= assetUrl('img/emblem-white.webp') ?>" alt="" width="34" height="34">
            <img class="app-brand-wordmark" src="<?= assetUrl('img/wordmark-white.webp') ?>" alt="<?= BRAND_NAME ?>" height="26">
        </a>
        <button type="button" class="btn-close btn-close-white app-sidebar-close" data-bs-dismiss="offcanvas" data-bs-target="#app-sidebar" aria-label="<?= lang('Common.close') ?>"></button>
    </div>

    <nav class="app-nav" aria-label="<?= lang('Common.main_navigation') ?>">
        <?php foreach ($sections as $section): ?>
            <?php $panelId = 'nav-panel-' . $section['key']; ?>
            <section class="app-nav-section<?= $section['active'] ? ' is-open has-active' : '' ?>" data-nav-section="<?= $section['key'] ?>">
                <h2 class="app-nav-heading">
                    <button type="button" class="app-nav-toggle" aria-expanded="<?= $section['active'] ? 'true' : 'false' ?>" aria-controls="<?= $panelId ?>" title="<?= esc($section['label'], 'attr') ?>">
                        <i class="bi <?= $section['icon'] ?> app-nav-icon" aria-hidden="true"></i>
                        <span class="app-nav-text"><?= esc($section['label']) ?></span>
                        <i class="bi bi-chevron-down app-nav-caret" aria-hidden="true"></i>
                    </button>
                </h2>
                <div class="app-nav-panel" id="<?= $panelId ?>" role="group" aria-label="<?= esc($section['label'], 'attr') ?>">
                    <p class="app-nav-flyout-title"><?= esc($section['label']) ?></p>
                    <?php foreach ($section['groups'] as $group): ?>
                        <?php if ($group['label'] !== null): ?>
                            <p class="app-nav-subhead"><?= esc($group['label']) ?></p>
                        <?php endif; ?>
                        <ul class="app-nav-list">
                            <?php foreach ($group['items'] as $item): ?>
                                <li>
                                    <a class="app-nav-link<?= $item['active'] ? ' active' : '' ?>" href="<?= $item['url'] ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                                        <span class="app-nav-text"><?= esc($item['label']) ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </nav>

    <div class="app-sidebar-tools">
        <button type="button" class="app-sidebar-collapse" aria-pressed="false" data-label-collapse="<?= esc(lang('Common.collapse_menu'), 'attr') ?>" data-label-expand="<?= esc(lang('Common.expand_menu'), 'attr') ?>" title="<?= esc(lang('Common.collapse_menu'), 'attr') ?>">
            <i class="bi <?= isRtl() ? 'bi-layout-sidebar-inset-reverse' : 'bi-layout-sidebar-inset' ?>" aria-hidden="true"></i>
            <span class="app-nav-text"><?= lang('Common.collapse_menu') ?></span>
        </button>
    </div>

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
