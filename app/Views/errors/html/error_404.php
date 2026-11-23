<?php
$rtl  = function_exists('isRtl') ? isRtl() : true;
$base = function_exists('base_url') ? base_url() : '/';
?>
<!doctype html>
<html lang="<?= function_exists('current_language_code') ? current_language_code() : 'ar' ?>" dir="<?= $rtl ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= esc(lang('Error.not_found_title')) ?></title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #e7edf4; color: #172b4d; font-family: "IBM Plex Sans Arabic", "Segoe UI", Tahoma, system-ui, sans-serif; }
        .card { width: min(520px, 100%); padding: 40px 32px; border: 1px solid #dce4ee; border-radius: 8px; background: #fff; box-shadow: 0 2px 6px rgba(23, 43, 77, .08); text-align: center; }
        .brand { display: inline-flex; align-items: center; gap: 8px; margin-bottom: 24px; color: #172b4d; font-weight: 700; text-decoration: none; }
        .brand svg { width: 28px; height: 28px; }
        .art { display: inline-grid; place-items: center; width: 72px; height: 72px; border: 1px solid #dce4ee; border-radius: 999px; background: #f6f9fc; color: #7d8fae; }
        .art svg { width: 30px; height: 30px; }
        .code { margin: 16px 0 0; color: #0755cc; font-size: 13px; font-weight: 600; letter-spacing: .08em; direction: ltr; }
        h1 { margin: 4px 0 8px; font-size: 22px; font-weight: 600; line-height: 32px; }
        p { margin: 0; color: #52637a; font-size: 14px; line-height: 22px; }
        .actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px; margin-top: 24px; }
        .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 16px; border: 1px solid #8493ab; border-radius: 4px; background: #fff; color: #172b4d; font: inherit; font-size: 14px; font-weight: 500; text-decoration: none; cursor: pointer; }
        .btn-primary { border-color: #0755cc; background: #0755cc; color: #fff; }
        .btn:focus-visible { outline: 2px solid #1677ff; outline-offset: 2px; }
        .detail { margin: 20px 0 0; padding: 12px; border: 1px solid #e9eef4; border-radius: 4px; background: #f6f9fc; color: #42526b; font-size: 12px; text-align: start; white-space: normal; direction: ltr; }
    </style>
</head>
<body>
    <main class="card">
        <a class="brand" href="<?= esc($base, 'attr') ?>"><svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="6" fill="#0755cc"/><path d="M8 10h16M16 10v13" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/><rect x="19.5" y="15" width="5" height="5" rx="1" fill="#35c389"/></svg><?= defined('BRAND_NAME') ? BRAND_NAME : 'EXPRO' ?></a>
        <div><span class="art" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/></svg></span></div>
        <p class="code">404</p>
        <h1><?= esc(lang('Error.not_found_title')) ?></h1>
        <p><?= esc(lang('Error.not_found_text')) ?></p>
            <?php if (ENVIRONMENT !== 'production' && isset($message)) : ?>
                <pre class="detail"><?= nl2br(esc($message)) ?></pre>
            <?php endif; ?>
        <div class="actions">
            <a class="btn btn-primary" href="<?= esc($base, 'attr') ?>"><?= esc(lang('Error.back_home')) ?></a>
            <button type="button" class="btn" onclick="history.back()"><?= esc(lang('Error.go_back')) ?></button>
        </div>
    </main>
</body>
</html>
