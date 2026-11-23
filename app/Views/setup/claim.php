<?php
/**
 * @var string|null           $error
 * @var string|null           $errorKey
 * @var array<string, string> $old
 */
$old ??= [];
$errorKey ??= null;
$value   = static fn (string $name): string => esc($old[$name] ?? '', 'attr');
$closed  = $errorKey === 'closed';
$cleared = $error !== null && ! $closed;
$tone    = match ($errorKey) {
    'throttle' => 'is-warning',
    'busy', 'closed' => 'is-info',
    default => 'is-danger',
};
$icon = match ($errorKey) {
    'throttle' => 'bi-clock-history',
    'busy'     => 'bi-arrow-repeat',
    'closed'   => 'bi-lock',
    default    => 'bi-x-circle',
};
$errorFields = match ($errorKey) {
    'code_wrong'    => ['code'],
    'name_required' => ['first_name', 'last_name'],
    'username_invalid', 'username_taken' => ['username'],
    'email_invalid'     => ['email'],
    'password_policy'   => ['password'],
    'password_mismatch' => ['password_confirm'],
    default             => [],
};
$keptFocus = array_values(array_filter($errorFields, static fn (string $name): bool => ($old[$name] ?? '') !== ''));
$focus     = $keptFocus[0] ?? 'code';
$codeHelp  = str_replace(
    ['writable/setup/owner-code.txt', 'php spark setup:code'],
    ['<code dir="ltr">writable/setup/owner-code.txt</code>', '<code dir="ltr">php spark setup:code</code>'],
    esc(lang('Owner_setup.code_help')),
);

$field = static function (string $name, string $label, string $type, array $attributes = [], ?string $help = null) use ($errorFields, $cleared, $value): string {
    $hasError  = in_array($name, $errorFields, true);
    $secret    = in_array($name, ['code', 'password', 'password_confirm'], true);
    $id        = 'owner-' . str_replace('_', '-', $name);
    $describe  = $help !== null ? ' aria-describedby="' . $id . '-help"' : '';
    $extra     = '';
    $iconClass = ['code' => 'bi-shield-lock', 'first_name' => 'bi-person', 'last_name' => 'bi-person', 'email' => 'bi-envelope', 'username' => 'bi-at', 'password' => 'bi-key', 'password_confirm' => 'bi-key'][$name];

    foreach ($attributes as $attribute => $attributeValue) {
        $extra .= ' ' . $attribute . '="' . esc($attributeValue, 'attr') . '"';
    }

    return '<div class="login-field' . ($hasError ? ' has-error' : '') . '">'
        . '<div class="login-label-row"><label class="login-label" for="' . $id . '">' . esc($label) . '</label>'
        . ($secret && $cleared ? '<span class="claim-cleared" data-cleared-for="' . $id . '"><i class="bi bi-eraser" aria-hidden="true"></i> ' . esc(lang('Owner_setup.cleared_badge')) . '</span>' : '')
        . '</div>'
        . '<div class="login-control"><i class="bi ' . $iconClass . '" aria-hidden="true"></i>'
        . '<input id="' . $id . '" name="' . $name . '" type="' . $type . '"' . ($secret ? '' : ' value="' . $value($name) . '"') . $extra . $describe . ($hasError ? ' aria-invalid="true"' : '') . ' required>'
        . ($hasError ? '<i class="bi bi-exclamation-circle claim-error-icon" aria-hidden="true"></i>' : '')
        . '</div>'
        . ($help !== null ? '<p class="login-help" id="' . $id . '-help">' . esc($help) . '</p>' : '')
        . '</div>';
};
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= BRAND_NAME . ' | ' . esc(lang('Owner_setup.title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0755cc">
    <link rel="icon" href="<?= assetUrl('img/favicon.ico') ?>">
    <link rel="stylesheet" href="<?= assetUrl(isRtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/vendor.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/login.css') ?>">
</head>

<body class="login-page claim-page">
    <div class="login-layout">
        <div class="login-side">
            <main class="login-card claim-card">
                <div class="claim-head">
                    <span class="claim-eyebrow"><i class="bi bi-stars" aria-hidden="true"></i> <?= esc(lang('Owner_setup.eyebrow')) ?></span>
                    <h1 class="login-heading"><?= esc(lang('Owner_setup.title')) ?></h1>
                    <?php if (! $closed): ?>
                        <p class="claim-intro"><?= esc(lang('Owner_setup.intro')) ?></p>
                        <p class="claim-required"><?= esc(lang('Owner_setup.all_required')) ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($error !== null): ?>
                    <div class="login-alert <?= $tone ?>" id="owner-error" role="alert">
                        <i class="bi <?= $icon ?>" aria-hidden="true"></i>
                        <div class="claim-alert-body">
                            <p><?= esc($error) ?></p>
                            <?php if ($cleared): ?>
                                <p class="claim-alert-note"><i class="bi bi-shield-check" aria-hidden="true"></i> <?= esc(lang('Owner_setup.cleared_note')) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (! $closed): ?>
                    <?= form_open('owner_setup', ['id' => 'owner-form', 'class' => 'claim-form', 'autocomplete' => 'off', 'novalidate' => 'novalidate', 'data-focus' => 'owner-' . str_replace('_', '-', $focus), 'data-busy' => lang('Owner_setup.submitting')]) ?>
                    <?= csrf_field() ?>
                    <section class="claim-section" aria-labelledby="claim-step-1">
                        <h2 class="claim-step" id="claim-step-1"><span class="claim-step-number" aria-hidden="true">1</span> <?= esc(lang('Owner_setup.section_verify')) ?></h2>
                        <?= $field('code', lang('Owner_setup.code_label'), 'text', ['dir' => 'ltr', 'autocomplete' => 'off', 'spellcheck' => 'false', 'autocapitalize' => 'off', 'aria-describedby' => 'owner-code-help']) ?>
                        <div class="claim-where" id="owner-code-help">
                            <i class="bi bi-info-circle" aria-hidden="true"></i>
                            <div>
                                <p class="claim-where-title"><?= esc(lang('Owner_setup.code_where')) ?></p>
                                <p><?= $codeHelp ?></p>
                            </div>
                        </div>
                    </section>

                    <section class="claim-section" aria-labelledby="claim-step-2">
                        <h2 class="claim-step" id="claim-step-2"><span class="claim-step-number" aria-hidden="true">2</span> <?= esc(lang('Owner_setup.section_details')) ?></h2>
                        <div class="claim-pair">
                            <?= $field('first_name', lang('Owner_setup.first_name'), 'text', ['maxlength' => '50', 'autocomplete' => 'given-name']) ?>
                            <?= $field('last_name', lang('Owner_setup.last_name'), 'text', ['maxlength' => '50', 'autocomplete' => 'family-name']) ?>
                        </div>
                        <?= $field('email', lang('Owner_setup.email'), 'email', ['dir' => 'ltr', 'autocomplete' => 'email', 'spellcheck' => 'false']) ?>
                    </section>

                    <section class="claim-section" aria-labelledby="claim-step-3">
                        <h2 class="claim-step" id="claim-step-3"><span class="claim-step-number" aria-hidden="true">3</span> <?= esc(lang('Owner_setup.section_sign_in')) ?></h2>
                        <?= $field('username', lang('Owner_setup.username'), 'text', ['dir' => 'ltr', 'maxlength' => '32', 'autocomplete' => 'username', 'spellcheck' => 'false', 'autocapitalize' => 'off'], lang('Owner_setup.username_help')) ?>
                        <div class="claim-pair">
                            <?= $field('password', lang('Owner_setup.password'), 'password', ['minlength' => '8', 'autocomplete' => 'new-password'], lang('Owner_setup.password_help')) ?>
                            <?= $field('password_confirm', lang('Owner_setup.password_confirm'), 'password', ['minlength' => '8', 'autocomplete' => 'new-password']) ?>
                        </div>
                    </section>

                    <button class="btn btn-primary login-submit" id="owner-submit" type="submit">
                        <i class="bi bi-arrow-repeat login-spin" aria-hidden="true" hidden></i>
                        <span class="login-submit-label"><?= esc(lang('Owner_setup.submit')) ?></span>
                    </button>
                    <?= form_close() ?>
                <?php endif; ?>
            </main>
            <p class="login-foot"><?= BRAND_NAME ?> · <?= BRAND_TAGLINE ?></p>
        </div>

        <aside class="login-brand" aria-label="<?= BRAND_NAME ?>">
            <img class="login-logo" src="<?= assetUrl('img/logo-full-white.webp') ?>" alt="<?= BRAND_NAME ?>">
            <p class="login-tagline"><?= BRAND_TAGLINE ?></p>
        </aside>
    </div>

    <script src="<?= assetUrl('js/claim.js') ?>"></script>
</body>

</html>
