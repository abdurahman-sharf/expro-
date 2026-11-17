<?php
/**
 * @var object $user_info
 * @var array  $allowed_modules
 */
$sections = navigationSections($allowed_modules, uri_string(), (int) $user_info->person_id, (string) service('request')->getUri()->getQuery());
$initial  = mb_substr(trim($user_info->first_name . $user_info->last_name) ?: $user_info->username, 0, 1);
$openKey  = '';

foreach ($sections as $section) {
    if ($section['active'] && ! $section['direct']) {
        $openKey = $section['key'];

        break;
    }
}
?>

<aside id="app-sidebar" class="app-sidebar offcanvas offcanvas-start" tabindex="-1" aria-label="<?= lang('Common.main_navigation') ?>">
    <div class="app-sidebar-head">
        <a class="app-brand" href="<?= site_url() ?>" aria-label="<?= BRAND_NAME ?>">
            <img class="app-brand-emblem" src="<?= assetUrl('img/emblem-white.webp') ?>" alt="" width="34" height="34">
            <img class="app-brand-wordmark" src="<?= assetUrl('img/wordmark-white.webp') ?>" alt="<?= BRAND_NAME ?>" height="26">
        </a>
        <button type="button" class="btn-close btn-close-white app-sidebar-close" data-bs-dismiss="offcanvas" data-bs-target="#app-sidebar" aria-label="<?= lang('Common.close') ?>"></button>
    </div>

    <nav class="app-nav" aria-label="<?= lang('Common.main_navigation') ?>" data-nav-open="<?= esc($openKey, 'attr') ?>">
        <?php foreach ($sections as $section): ?>
            <?php $open = $section['key'] === $openKey; ?>
            <?php if ($section['direct']): ?>
                <?php $only = $section['groups'][0]['items'][0]; ?>
                <section class="app-nav-section is-direct<?= $section['active'] ? ' has-active' : '' ?>" data-nav-section="<?= $section['key'] ?>">
                    <h2 class="app-nav-heading">
                        <a class="app-nav-link app-nav-top<?= $only['active'] ? ' active' : '' ?>" href="<?= esc($only['url'], 'attr') ?>" data-nav-id="<?= esc($only['id'], 'attr') ?>"<?= $only['fragment'] !== null ? ' data-nav-fragment="' . esc($only['fragment'], 'attr') . '"' : '' ?><?= $only['label'] !== $section['label'] ? ' title="' . esc($only['label'], 'attr') . '"' : '' ?><?= $only['active'] ? ' aria-current="page"' : '' ?>>
                            <i class="bi <?= $section['icon'] ?> app-nav-icon" aria-hidden="true"></i>
                            <span class="app-nav-text"><?= esc($section['label']) ?></span>
                        </a>
                    </h2>
                </section>
            <?php else: ?>
                <?php $panelId = 'nav-panel-' . $section['key']; ?>
                <section class="app-nav-section<?= $open ? ' is-open' : '' ?><?= $section['active'] ? ' has-active' : '' ?>" data-nav-section="<?= $section['key'] ?>">
                    <h2 class="app-nav-heading">
                        <button type="button" class="app-nav-toggle" id="nav-toggle-<?= $section['key'] ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="<?= $panelId ?>">
                            <i class="bi <?= $section['icon'] ?> app-nav-icon" aria-hidden="true"></i>
                            <span class="app-nav-text"><?= esc($section['label']) ?></span>
                            <i class="bi bi-chevron-down app-nav-caret" aria-hidden="true"></i>
                        </button>
                    </h2>
                    <div class="app-nav-panel" id="<?= $panelId ?>" role="group" aria-labelledby="nav-toggle-<?= $section['key'] ?>"<?= $open ? '' : ' hidden' ?>>
                        <?php foreach ($section['groups'] as $group): ?>
                            <?php if ($group['label'] !== null): ?>
                                <p class="app-nav-subhead"><?= esc($group['label']) ?></p>
                            <?php endif; ?>
                            <ul class="app-nav-list">
                                <?php foreach ($group['items'] as $item): ?>
                                    <li>
                                        <a class="app-nav-link<?= $item['active'] ? ' active' : '' ?>" href="<?= esc($item['url'], 'attr') ?>" data-nav-id="<?= esc($item['id'], 'attr') ?>"<?= $item['fragment'] !== null ? ' data-nav-fragment="' . esc($item['fragment'], 'attr') . '"' : '' ?><?= $item['active'] ? ' aria-current="page"' : '' ?>>
                                            <span class="app-nav-text"><?= esc($item['label']) ?></span>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>
    <script>window.exproNav && window.exproNav.sync();</script>

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
