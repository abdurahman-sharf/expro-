<?php
/**
 * @var bool                                $waiting
 * @var string                              $step
 * @var list<string>                        $steps
 * @var array<string, bool>                 $progress
 * @var array<string, string>               $values
 * @var array<string, string>               $languages
 * @var array<string, array<string, mixed>> $regions
 * @var list<string>                        $timezones
 * @var list<string>                        $problems
 * @var array<string, mixed>|null           $activation
 */
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!doctype html>
<html lang="<?= current_language_code() ?>" dir="<?= textDirection() ?>">

<head>
    <meta charset="utf-8">
    <base href="<?= base_url() ?>">
    <title><?= BRAND_NAME . ' | ' . esc(lang('Setup.title')) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="<?= assetUrl('img/favicon.ico') ?>">
    <link rel="stylesheet" href="<?= assetUrl(isRtl() ? 'css/bootstrap.rtl.min.css' : 'css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/vendor.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/app.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('css/login.css') ?>">
    <script src="<?= assetUrl('js/jquery.min.js') ?>"></script>
</head>

<body class="login-page">
    <div class="login-layout">
        <section class="login-brand">
            <img class="login-logo" src="<?= assetUrl('img/logo-full-white.webp') ?>" alt="<?= BRAND_NAME ?>">
            <p class="login-tagline"><?= BRAND_TAGLINE ?></p>
        </section>

        <main class="login-panel">
            <?php if ($waiting): ?>
                <div class="login-card">
                    <h1 class="login-heading"><?= esc(lang('Setup.title')) ?></h1>
                    <div class="alert alert-warning" role="status"><?= esc(lang('Setup.waiting_for_owner')) ?></div>
                    <a class="btn btn-link" href="<?= site_url('home/logout') ?>"><?= esc(lang('Login.logout')) ?></a>
                </div>
            <?php else: ?>
                <div class="login-card setup-card" id="setup_card">
                    <h1 class="login-heading"><?= esc(lang('Setup.title')) ?></h1>
                    <p class="text-secondary"><?= esc(lang('Setup.intro')) ?></p>

                    <ol class="acc-wizard-steps" id="setup_steps">
                        <?php foreach ($steps as $name): ?>
                            <li class="<?= $name === $step ? 'is-current' : ($progress[$name] ? 'is-done' : '') ?>">
                                <?php if ($progress[$name] || $name === $step): ?>
                                    <a href="<?= esc(site_url('setup') . '?step=' . $name, 'attr') ?>"<?= $name === $step ? ' aria-current="step"' : '' ?>><?= esc(lang('Setup.step_' . $name)) ?></a>
                                <?php else: ?>
                                    <?= esc(lang('Setup.step_' . $name)) ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>

                    <div class="alert d-none" id="setup_message" role="alert"></div>

                    <?php if ($step === 'language'): ?>
                        <?php $selectedLanguage = array_key_exists($values['language'], $languages) ? $values['language'] : 'ar-EG:arabic'; ?>
                        <?= form_open('setup/language', ['id' => 'form_language', 'class' => 'login-fields', 'data-step' => 'language', 'autocomplete' => 'off']) ?>
                        <h2 class="acc-wizard-subtitle"><?= esc(lang('Setup.language_title')) ?></h2>
                        <p class="text-secondary"><?= esc(lang('Setup.language_help')) ?></p>
                        <div class="form-floating">
                            <select class="form-select" id="language" name="language">
                                <?php foreach ($languages as $key => $label): ?>
                                    <option value="<?= esc($key, 'attr') ?>"<?= $key === $selectedLanguage ? ' selected' : '' ?>><?= esc($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="language"><?= esc(lang('Setup.language')) ?></label>
                        </div>
                        <div class="form-floating">
                            <select class="form-select" id="country" name="country">
                                <option value=""><?= esc(lang('Setup.country_other')) ?></option>
                                <?php foreach ($regions as $code => $region): ?>
                                    <option value="<?= esc($code, 'attr') ?>" data-currency="<?= esc($region['currency'], 'attr') ?>" data-symbol="<?= esc($region['symbol'], 'attr') ?>" data-timezone="<?= esc($region['timezone'], 'attr') ?>" data-decimals="<?= (int) $region['decimals'] ?>"<?= $code === $values['country'] ? ' selected' : '' ?>><?= esc($region['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="country"><?= esc(lang('Setup.country')) ?></label>
                        </div>
                        <p class="form-text"><?= esc(lang('Setup.country_help')) ?></p>
                        <div class="form-floating">
                            <input class="form-control" id="currency_code" name="currency_code" type="text" dir="ltr" maxlength="3" value="<?= esc($values['currency_code'], 'attr') ?>" placeholder="<?= esc(lang('Setup.currency_code'), 'attr') ?>" required>
                            <label for="currency_code"><?= esc(lang('Setup.currency_code')) ?></label>
                        </div>
                        <p class="form-text"><?= esc(lang('Setup.currency_help')) ?></p>
                        <div class="form-floating">
                            <input class="form-control" id="currency_symbol" name="currency_symbol" type="text" maxlength="10" value="<?= esc($values['currency_symbol'], 'attr') ?>" placeholder="<?= esc(lang('Setup.currency_symbol'), 'attr') ?>">
                            <label for="currency_symbol"><?= esc(lang('Setup.currency_symbol')) ?></label>
                        </div>
                        <div class="form-floating">
                            <input class="form-control" id="currency_decimals" name="currency_decimals" type="number" min="0" max="4" value="<?= esc($values['currency_decimals'], 'attr') ?>" placeholder="<?= esc(lang('Setup.currency_decimals'), 'attr') ?>">
                            <label for="currency_decimals"><?= esc(lang('Setup.currency_decimals')) ?></label>
                        </div>
                        <div class="form-floating">
                            <select class="form-select" id="timezone" name="timezone">
                                <?php foreach ($timezones as $timezone): ?>
                                    <option value="<?= esc($timezone, 'attr') ?>"<?= $timezone === $values['timezone'] ? ' selected' : '' ?>><?= esc($timezone) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="timezone"><?= esc(lang('Setup.timezone')) ?></label>
                        </div>
                        <button class="btn btn-lg btn-primary" type="submit"><?= esc(lang('Setup.save_and_continue')) ?></button>
                        <?= form_close() ?>
                    <?php elseif ($step === 'company'): ?>
                        <?= form_open('setup/company', ['id' => 'form_company', 'class' => 'login-fields', 'data-step' => 'company', 'autocomplete' => 'off']) ?>
                        <h2 class="acc-wizard-subtitle"><?= esc(lang('Setup.company_title')) ?></h2>
                        <div class="form-floating">
                            <input class="form-control" id="company" name="company" type="text" maxlength="255" value="<?= esc($values['company'], 'attr') ?>" placeholder="<?= esc(lang('Setup.company'), 'attr') ?>" required>
                            <label for="company"><?= esc(lang('Setup.company')) ?></label>
                        </div>
                        <div class="form-floating">
                            <input class="form-control" id="address" name="address" type="text" maxlength="255" value="<?= esc($values['address'], 'attr') ?>" placeholder="<?= esc(lang('Setup.address'), 'attr') ?>">
                            <label for="address"><?= esc(lang('Setup.address')) ?></label>
                        </div>
                        <div class="form-floating">
                            <input class="form-control" id="phone" name="phone" type="tel" dir="ltr" maxlength="64" value="<?= esc($values['phone'], 'attr') ?>" placeholder="<?= esc(lang('Setup.phone'), 'attr') ?>">
                            <label for="phone"><?= esc(lang('Setup.phone')) ?></label>
                        </div>
                        <div class="form-floating">
                            <input class="form-control" id="email" name="email" type="email" dir="ltr" maxlength="255" value="<?= esc($values['email'], 'attr') ?>" placeholder="<?= esc(lang('Setup.email'), 'attr') ?>">
                            <label for="email"><?= esc(lang('Setup.email')) ?></label>
                        </div>
                        <div class="form-floating">
                            <input class="form-control" id="tax_id" name="tax_id" type="text" dir="ltr" maxlength="64" value="<?= esc($values['tax_id'], 'attr') ?>" placeholder="<?= esc(lang('Setup.tax_id'), 'attr') ?>">
                            <label for="tax_id"><?= esc(lang('Setup.tax_id')) ?></label>
                        </div>
                        <p class="form-text"><?= esc(lang('Setup.tax_id_help')) ?></p>
                        <button class="btn btn-lg btn-primary" type="submit"><?= esc(lang('Setup.save_and_continue')) ?></button>
                        <?= form_close() ?>
                    <?php elseif ($step === 'branch'): ?>
                        <?= form_open('setup/branch', ['id' => 'form_branch', 'class' => 'login-fields', 'data-step' => 'branch', 'autocomplete' => 'off']) ?>
                        <h2 class="acc-wizard-subtitle"><?= esc(lang('Setup.branch_title')) ?></h2>
                        <p class="text-secondary"><?= esc(lang('Setup.branch_help')) ?></p>
                        <div class="form-floating">
                            <input class="form-control" id="branch_name" name="branch_name" type="text" maxlength="100" value="<?= esc($values['branch_name'], 'attr') ?>" placeholder="<?= esc(lang('Setup.branch_name'), 'attr') ?>" required>
                            <label for="branch_name"><?= esc(lang('Setup.branch_name')) ?></label>
                        </div>
                        <div class="form-floating">
                            <input class="form-control" id="location_name" name="location_name" type="text" maxlength="100" value="<?= esc($values['location_name'], 'attr') ?>" placeholder="<?= esc(lang('Setup.location_name'), 'attr') ?>" required>
                            <label for="location_name"><?= esc(lang('Setup.location_name')) ?></label>
                        </div>
                        <p class="form-text"><?= esc(lang('Setup.location_help')) ?></p>
                        <button class="btn btn-lg btn-primary" type="submit"><?= esc(lang('Setup.save_and_continue')) ?></button>
                        <?= form_close() ?>
                    <?php elseif ($step === 'tax'): ?>
                        <?= form_open('setup/tax', ['id' => 'form_tax', 'class' => 'login-fields', 'data-step' => 'tax', 'autocomplete' => 'off']) ?>
                        <h2 class="acc-wizard-subtitle"><?= esc(lang('Setup.tax_title')) ?></h2>
                        <p class="text-secondary"><?= esc(lang('Setup.tax_help')) ?></p>
                        <div class="form-floating">
                            <input class="form-control" id="tax_name" name="tax_name" type="text" maxlength="100" value="<?= esc($values['tax_name'], 'attr') ?>" placeholder="<?= esc(lang('Setup.tax_name'), 'attr') ?>">
                            <label for="tax_name"><?= esc(lang('Setup.tax_name')) ?></label>
                        </div>
                        <div class="form-floating">
                            <input class="form-control" id="tax_rate" name="tax_rate" type="text" inputmode="decimal" dir="ltr" maxlength="10" value="<?= esc($values['tax_rate'], 'attr') ?>" placeholder="<?= esc(lang('Setup.tax_rate'), 'attr') ?>">
                            <label for="tax_rate"><?= esc(lang('Setup.tax_rate')) ?></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="tax_included" name="tax_included" value="1"<?= $values['tax_included'] === '1' ? ' checked' : '' ?>>
                            <label class="form-check-label" for="tax_included"><?= esc(lang('Setup.tax_included')) ?></label>
                        </div>
                        <p class="form-text"><?= esc(lang('Setup.tax_included_help')) ?></p>
                        <button class="btn btn-lg btn-primary" type="submit"><?= esc(lang('Setup.save_and_continue')) ?></button>
                        <?= form_close() ?>
                    <?php elseif ($step === 'accounting' && $activation !== null): ?>
                        <h2 class="acc-wizard-subtitle"><?= esc(lang('Setup.accounting_title')) ?></h2>
                        <p class="text-secondary"><?= esc(lang('Setup.accounting_help')) ?></p>
                        <?= view('accounting_settings/activation_panels', $activation + ['embedded' => true]) ?>
                    <?php else: ?>
                        <h2 class="acc-wizard-subtitle"><?= esc(lang('Setup.finish_title')) ?></h2>
                        <?php if ($problems !== []): ?>
                            <div class="alert alert-warning" role="alert">
                                <strong><?= esc(lang('Setup.problems_title')) ?></strong>
                                <ul class="mb-0">
                                    <?php foreach ($problems as $problem): ?>
                                        <li><?= esc(lang('Setup.problem_' . $problem)) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php else: ?>
                            <p class="text-success"><?= esc(lang('Setup.finish_ready')) ?></p>
                        <?php endif; ?>
                        <?= form_open('setup/finish', ['id' => 'form_finish', 'class' => 'login-fields', 'data-step' => 'finish']) ?>
                        <button class="btn btn-lg btn-success" type="submit"<?= $problems !== [] ? ' disabled' : '' ?>><?= esc(lang('Setup.finish_button')) ?></button>
                        <?= form_close() ?>
                    <?php endif; ?>

                    <a class="btn btn-link" href="<?= site_url('home/logout') ?>"><?= esc(lang('Login.logout')) ?></a>
                </div>

                <script>
                    $.ajaxPrefilter(function(options) {
                        if ((options.type || '').toUpperCase() === 'POST') {
                            const token = $.param({<?= json_encode(config('Security')->tokenName, $jsonFlags) ?>: <?= json_encode(csrf_hash(), $jsonFlags) ?>});

                            options.data = options.data ? options.data + '&' + token : token;
                        }
                    });

                    $(function() {
                        const fallback = <?= json_encode(lang('Setup.request_failed'), $jsonFlags) ?>;
                        const show = function(text, kind) {
                            $('#setup_message').removeClass('d-none alert-danger alert-success').addClass('alert-' + kind).text(text);
                        };

                        $('#country').on('change', function() {
                            const chosen = $(this).find('option:selected');

                            if (!chosen.val()) {
                                return;
                            }

                            $('#currency_code').val(chosen.data('currency'));
                            $('#currency_symbol').val(chosen.data('symbol'));
                            $('#currency_decimals').val(chosen.data('decimals'));
                            $('#timezone').val(chosen.data('timezone'));
                        });

                        $('#currency_code').on('input', function() {
                            this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '');
                        });

                        $('form[data-step]').on('submit', function(event) {
                            event.preventDefault();

                            const form = $(this);
                            const button = form.find('button[type=submit]').prop('disabled', true);

                            $.post(this.action, form.serialize(), null, 'json')
                                .done(function(response) {
                                    if (response.success) {
                                        window.location.href = response.next && response.next.indexOf('http') === 0 ? response.next : <?= json_encode(site_url('setup'), $jsonFlags) ?>;

                                        return;
                                    }

                                    button.prop('disabled', false);
                                    show(response.message, 'danger');
                                })
                                .fail(function(request) {
                                    button.prop('disabled', false);
                                    show(request.responseJSON && request.responseJSON.message ? request.responseJSON.message : fallback, 'danger');
                                });
                        });
                    });
                </script>
            <?php endif; ?>
        </main>
    </div>
</body>

</html>
