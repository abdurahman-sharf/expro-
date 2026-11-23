<?php
/**
 * @var object $user_info
 * @var array  $allowed_modules
 * @var string $controller_name
 * @var array  $config
 * @var string $page_title
 */
$pageTitle = ($page_title ?? '') !== '' ? $page_title : navigationPageTitle($controller_name ?? '');
?>

<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(($pageTitle !== '' ? "{$pageTitle} | " : '') . $config['company'] . ' | ' . BRAND_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#042359">
    <link rel="icon" href="<?= assetUrl('img/favicon.ico') ?>">
    <link rel="apple-touch-icon" href="<?= assetUrl('img/apple-touch-icon.png') ?>">
    <link rel="stylesheet" href="<?= assetUrl(isRtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/vendor.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/app.css') ?>">
    <script src="<?= assetUrl('js/vendor.min.js') ?>"></script>
    <script src="<?= assetUrl('js/app.js') ?>"></script>

    <?= view('partial/header_js') ?>
    <?= view('partial/lang_lines') ?>
</head>

<body>
    <a class="visually-hidden-focusable app-skip" href="<?= current_url() ?>#app-main"><?= lang('Common.skip_to_content') ?></a>

    <div class="app-shell">
        <?= view('partial/sidebar') ?>

        <div class="app-body">
            <header class="app-topbar">
                <div class="app-topbar-row">
                    <button type="button" class="app-menu-toggle" data-bs-toggle="offcanvas" data-bs-target="#app-sidebar" aria-controls="app-sidebar" aria-label="<?= lang('Common.open_menu') ?>">
                        <i class="bi bi-list" aria-hidden="true"></i>
                    </button>
                    <h1 class="app-title"><?= esc($pageTitle) ?></h1>
                    <span class="app-topbar-spacer"></span>
                    <div class="app-topbar-info">
                        <p class="app-company" title="<?= esc($config['company'], 'attr') ?>"><i class="bi bi-buildings" aria-hidden="true"></i> <span class="app-company-name" dir="auto"><?= esc($config['company']) ?></span></p>
                        <?= view('partial/branch_switcher') ?>
                        <button type="button" id="global_search_open" class="app-search-open" aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K" title="<?= lang('Common.global_search') ?> (Ctrl K)">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <span class="app-search-label"><?= lang('Common.global_search') ?></span>
                            <kbd class="app-search-kbd" dir="ltr">Ctrl K</kbd>
                        </button>
                        <p class="app-clock">
                            <span class="app-clock-day"><span id="liveclock_day"></span> <bdi id="liveclock_date"><?= date($config['dateformat']) ?></bdi></span>
                            <bdi class="app-clock-time" id="liveclock"><?= date($config['timeformat']) ?></bdi>
                        </p>
                    </div>
                    <button type="button" class="app-search-icon" data-global-search aria-haspopup="dialog" aria-label="<?= lang('Common.global_search') ?>">
                        <i class="bi bi-search" aria-hidden="true"></i>
                    </button>
                </div>
                <?php $operationsHold = json_decode((string) ($config['accounting_operations_hold'] ?? ''), true); ?>
                <?php if (is_array($operationsHold) && isset($operationsHold['reason'])): ?>
                    <div class="app-hold" role="alert" id="operations_hold_banner"><span class="bi bi-pause-circle" aria-hidden="true"></span> <?= esc(lang('Accounting_settings.hold_banner', [(string) $operationsHold['reason']])) ?></div>
                <?php endif; ?>
            </header>

            <main id="app-main" class="app-main" tabindex="-1">
