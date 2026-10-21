<?php
/**
 * @var object $user_info
 * @var array $allowed_modules
 * @var string $controller_name
 * @var array $config
 * @var string|null $pageTitleOverride
 */

$pageTitle = $pageTitleOverride ?? navigationPageTitle($controller_name ?? '');
?>

<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(($pageTitle !== '' ? "$pageTitle | " : '') . $config['company'] . ' | ' . BRAND_NAME) ?></title>
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
                <button type="button" class="btn btn-icon app-menu-toggle" data-bs-toggle="offcanvas" data-bs-target="#app-sidebar" aria-controls="app-sidebar" aria-label="<?= lang('Common.open_menu') ?>">
                    <i class="bi bi-list" aria-hidden="true"></i>
                </button>
                <h1 class="app-title"><?= esc($pageTitle) ?></h1>
                <p class="app-company"><?= esc($config['company']) ?></p>
                <button type="button" id="global_search_open" class="btn btn-outline-secondary app-search-open" aria-haspopup="dialog" aria-keyshortcuts="Control+K Meta+K" title="<?= lang('Common.global_search') ?> (Ctrl K)">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <span class="app-search-label"><?= lang('Common.global_search') ?></span>
                    <kbd class="app-search-kbd" dir="ltr">Ctrl K</kbd>
                </button>
                <p class="app-clock"><i class="bi bi-clock" aria-hidden="true"></i> <span id="liveclock"><?= date($config['dateformat'] . ' ' . $config['timeformat']) ?></span></p>
            </header>

            <main id="app-main" class="app-main" tabindex="-1">
