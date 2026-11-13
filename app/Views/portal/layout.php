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
?>
<!doctype html>
<html lang="<?= esc(current_language_code(), 'attr') ?>" dir="<?= esc(textDirection(), 'attr') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc(($title ?? lang('Portal.title')) . ' | ' . ($settings['company'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= esc(assetUrl(isRtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css'), 'attr') ?>">
    <style>
        @media print { .portal-chrome { display: none !important; } }
        .portal-amount { white-space: nowrap; }
    </style>
</head>
<body class="bg-light">
<header class="navbar navbar-expand-md bg-white border-bottom portal-chrome">
    <div class="container flex-wrap gap-2">
        <span class="navbar-brand"><?= esc($settings['company'] ?? '') ?> <span class="text-secondary">&middot; <?= esc(lang('Portal.title')) ?></span></span>
        <?php if ($signedIn) : ?>
            <nav class="d-flex flex-wrap gap-3 align-items-center" aria-label="<?= esc(lang('Portal.title'), 'attr') ?>">
                <?php foreach ($menu as $path => $label) : ?>
                    <a href="<?= esc(site_url($path), 'attr') ?>"><?= esc(lang($label)) ?></a>
                <?php endforeach; ?>
                <a href="<?= esc(site_url('portal/password'), 'attr') ?>"><?= esc(lang('Portal.change_title')) ?></a>
                <form method="post" action="<?= esc(site_url('portal/logout'), 'attr') ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-secondary btn-sm"><?= esc(lang('Portal.sign_out')) ?></button>
                </form>
            </nav>
        <?php endif; ?>
    </div>
</header>
<main class="container py-4">
    <?= $this->renderSection('content') ?>
</main>
</body>
</html>
