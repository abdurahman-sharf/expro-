<?php
/**
 * @var object $user_info
 * @var array $allowed_modules
 * @var string $controller_name
 * @var array $config
 */

$pageTitle = navigation_page_title($controller_name ?? '');
?>

<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= text_direction() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= esc(($pageTitle !== '' ? "$pageTitle | " : '') . $config['company'] . ' | ' . BRAND_NAME) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#042359">
    <link rel="icon" href="<?= asset_url('img/favicon.ico') ?>">
    <link rel="apple-touch-icon" href="<?= asset_url('img/apple-touch-icon.png') ?>">
    <link rel="stylesheet" href="<?= asset_url(is_rtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('css/vendor.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_url('css/app.css') ?>">
    <script src="<?= asset_url('js/vendor.min.js') ?>"></script>
    <script src="<?= asset_url('js/app.js') ?>"></script>

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
                <p class="app-clock"><i class="bi bi-clock" aria-hidden="true"></i> <span id="liveclock"><?= date($config['dateformat'] . ' ' . $config['timeformat']) ?></span></p>
            </header>

            <main id="app-main" class="app-main" tabindex="-1">
