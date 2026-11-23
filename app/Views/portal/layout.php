<?php

use Config\OSPOS;

/**
 * @var string|null $title
 * @var bool|null   $signedIn
 */
$settings = config(OSPOS::class)->settings;
$signedIn ??= false;
$menu = [
    'portal/statement'    => 'Portal.menu_statement',
    'portal/invoices'     => 'Portal.menu_invoices',
    'portal/credit_notes' => 'Portal.menu_credit_notes',
    'portal/payments'     => 'Portal.menu_payments',
];
$current = trim((string) service('request')->getUri()->getRoutePath(), '/');
?>
<!doctype html>
<html lang="<?= esc(current_language_code(), 'attr') ?>" dir="<?= esc(textDirection(), 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0755cc">
    <title><?= esc(($title ?? lang('Portal.title')) . ' | ' . ($settings['company'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= esc(assetUrl(isRtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css'), 'attr') ?>">
    <link rel="stylesheet" href="<?= esc(assetUrl('css/vendor.min.css'), 'attr') ?>">
    <link rel="stylesheet" href="<?= esc(assetUrl('css/app.css'), 'attr') ?>">
    <link rel="stylesheet" href="<?= esc(assetUrl('css/portal.css'), 'attr') ?>">
</head>
<body class="portal<?= $signedIn ? '' : ' portal-guest' ?>">
<header class="portal-head portal-chrome">
    <div class="portal-head-row">
        <span class="portal-brand">
            <span class="portal-brand-mark" aria-hidden="true"><?= esc(mb_substr((string) ($settings['company'] ?? ''), 0, 1)) ?></span>
            <span class="portal-brand-text">
                <b><?= esc($settings['company'] ?? '') ?></b>
                <small><?= esc(lang('Portal.title')) ?></small>
            </span>
        </span>
        <?php if ($signedIn) : ?>
            <div class="portal-account">
                <a class="btn btn-ghost" title="<?= esc(lang('Portal.change_title'), 'attr') ?>" href="<?= esc(site_url('portal/password'), 'attr') ?>"<?= $current === 'portal/password' ? ' aria-current="page"' : '' ?>><span class="bi bi-key" aria-hidden="true"></span> <span class="portal-account-label"><?= esc(lang('Portal.change_title')) ?></span></a>
                <form method="post" action="<?= esc(site_url('portal/logout'), 'attr') ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-secondary" title="<?= esc(lang('Portal.sign_out'), 'attr') ?>"><span class="bi bi-box-arrow-right" aria-hidden="true"></span> <span class="portal-account-label"><?= esc(lang('Portal.sign_out')) ?></span></button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($signedIn) : ?>
        <nav class="portal-nav" aria-label="<?= esc(lang('Portal.title'), 'attr') ?>">
            <?php foreach ($menu as $path => $label) : ?>
                <a href="<?= esc(site_url($path), 'attr') ?>"<?= str_starts_with($current, rtrim($path, 's')) || ($current === 'portal' && $path === 'portal/statement') ? ' aria-current="page"' : '' ?>><?= esc(lang($label)) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</header>
<main class="portal-main">
    <?= $this->renderSection('content') ?>
</main>
<footer class="portal-foot portal-chrome"><?= esc($settings['company'] ?? '') ?> &middot; <?= esc(lang('Portal.title')) ?></footer>
</body>
</html>
