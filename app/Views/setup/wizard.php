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

<body class="login-page claim-page setup-page">
    <div class="login-layout">
        <div class="login-side">
            <?php if ($waiting): ?>
                <main class="login-card">
                    <div class="claim-head">
                        <span class="claim-eyebrow"><i class="bi bi-hourglass-split" aria-hidden="true"></i> <?= esc(lang('Setup.title')) ?></span>
                        <h1 class="login-heading"><?= esc(lang('Setup.title')) ?></h1>
                    </div>
                    <div class="login-alert is-info" role="status"><i class="bi bi-person-gear" aria-hidden="true"></i><p><?= esc(lang('Setup.waiting_for_owner')) ?></p></div>
                    <a class="btn btn-outline-secondary setup-logout" href="<?= site_url('home/logout') ?>"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> <?= esc(lang('Login.logout')) ?></a>
                </main>
            <?php else: ?>
                <?php
                $allSteps    = [...$steps, 'finish'];
                $position    = array_search($step, $allSteps, true);
                $previous    = $position > 0 ? $allSteps[$position - 1] : null;
                $stepNumber  = $position + 1;
                $problemStep = static fn (string $problem): string => match (true) {
                    str_starts_with($problem, 'language') => 'language',
                    str_starts_with($problem, 'company')  => 'company',
                    str_starts_with($problem, 'branch'), str_starts_with($problem, 'location') => 'branch',
                    str_starts_with($problem, 'tax') => 'tax',
                    default                          => 'accounting',
                };
                $stepUrl = static fn (string $name): string => site_url('setup') . '?step=' . $name;
                $actions = static fn (?string $back): string => '<div class="setup-actions">'
                        . ($back !== null ? '<a class="btn btn-outline-secondary" href="' . esc($stepUrl($back), 'attr') . '"><i class="bi bi-arrow-right setup-flip" aria-hidden="true"></i> ' . esc(lang('Accounting_activation.back')) . '</a>' : '<span></span>')
                        . '<button class="btn btn-primary setup-submit" type="submit"><i class="bi bi-arrow-repeat login-spin" aria-hidden="true" hidden></i> <span class="login-submit-label">' . esc(lang('Setup.save_and_continue')) . '</span> <i class="bi bi-arrow-left setup-flip" aria-hidden="true"></i></button>'
                        . '</div>';
                $control = static fn (string $icon, string $input): string => '<div class="login-control"><i class="bi ' . $icon . '" aria-hidden="true"></i>' . $input . '</div>';
                ?>
                <main class="login-card setup-card" id="setup_card">
                    <div class="claim-head">
                        <span class="claim-eyebrow"><i class="bi bi-rocket-takeoff" aria-hidden="true"></i> <?= esc(lang('Setup.progress_of', [$stepNumber, count($allSteps)])) ?></span>
                        <h1 class="login-heading"><?= esc(lang('Setup.title')) ?></h1>
                        <p class="claim-intro"><?= esc(lang('Setup.intro')) ?></p>
                    </div>

                    <ol class="acc-wizard-steps setup-steps" id="setup_steps" style="--steps: <?= count($allSteps) ?>">
                        <?php foreach ($allSteps as $index => $name): ?>
                            <?php
                            $isCurrent = $name === $step;
                            $isDone    = ! $isCurrent && ($progress[$name] ?? false);
                            $label     = $name === 'finish' ? lang('Setup.finish_title') : lang('Setup.step_' . $name);
                            ?>
                            <li class="<?= $isCurrent ? 'is-current' : ($isDone ? 'is-done' : '') ?>">
                                <?php if ($isDone || $isCurrent): ?>
                                    <a href="<?= esc($stepUrl($name), 'attr') ?>"<?= $isCurrent ? ' aria-current="step"' : '' ?>><span class="setup-step-label"><?= esc($label) ?></span></a>
                                <?php else: ?>
                                    <span class="setup-step-label"><?= esc($label) ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <div class="setup-progress" aria-hidden="true"><span style="width: <?= round($stepNumber / count($allSteps) * 100) ?>%"></span></div>

                    <div class="login-alert is-danger" id="setup_message" role="alert" hidden><i class="bi bi-x-circle" aria-hidden="true"></i><p></p></div>

                    <?php if ($step === 'language'): ?>
                        <?php $selectedLanguage = array_key_exists($values['language'], $languages) ? $values['language'] : 'ar-EG:arabic'; ?>
                        <?= form_open('setup/language', ['id' => 'form_language', 'class' => 'claim-form', 'data-step' => 'language', 'autocomplete' => 'off', 'novalidate' => 'novalidate']) ?>
                        <section class="claim-section">
                            <h2 class="claim-step"><span class="claim-step-number" aria-hidden="true"><?= $stepNumber ?></span> <?= esc(lang('Setup.language_title')) ?></h2>
                            <p class="setup-help"><?= esc(lang('Setup.language_help')) ?></p>
                            <div class="claim-pair">
                                <div class="login-field">
                                    <label class="login-label" for="language"><?= esc(lang('Setup.language')) ?></label>
                                    <?= $control('bi-translate', '<select id="language" name="language">' . implode('', array_map(static fn (string $key, string $label): string => '<option value="' . esc($key, 'attr') . '"' . ($key === $selectedLanguage ? ' selected' : '') . '>' . esc($label) . '</option>', array_keys($languages), $languages)) . '</select>') ?>
                                </div>
                                <div class="login-field">
                                    <label class="login-label" for="country"><?= esc(lang('Setup.country')) ?></label>
                                    <div class="login-control"><i class="bi bi-globe2" aria-hidden="true"></i>
                                        <select id="country" name="country" aria-describedby="country_help">
                                            <option value=""><?= esc(lang('Setup.country_other')) ?></option>
                                            <?php foreach ($regions as $code => $region): ?>
                                                <option value="<?= esc($code, 'attr') ?>" data-currency="<?= esc($region['currency'], 'attr') ?>" data-symbol="<?= esc($region['symbol'], 'attr') ?>" data-timezone="<?= esc($region['timezone'], 'attr') ?>" data-decimals="<?= (int) $region['decimals'] ?>"<?= $code === $values['country'] ? ' selected' : '' ?>><?= esc($region['name']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <p class="setup-autofill" id="country_help"><i class="bi bi-magic" aria-hidden="true"></i> <?= esc(lang('Setup.country_help')) ?></p>
                        </section>
                        <section class="claim-section">
                            <h2 class="claim-step"><span class="claim-step-number is-sub" aria-hidden="true"><i class="bi bi-cash-coin"></i></span> <?= esc(lang('Setup.currency_title')) ?></h2>
                            <div class="claim-pair setup-triple">
                                <div class="login-field" data-autofill>
                                    <label class="login-label" for="currency_code"><?= esc(lang('Setup.currency_code')) ?></label>
                                    <?= $control('bi-currency-exchange', '<input id="currency_code" name="currency_code" type="text" dir="ltr" maxlength="3" value="' . esc($values['currency_code'], 'attr') . '" aria-describedby="currency_help" required>') ?>
                                </div>
                                <div class="login-field" data-autofill>
                                    <label class="login-label" for="currency_symbol"><?= esc(lang('Setup.currency_symbol')) ?></label>
                                    <?= $control('bi-tag', '<input id="currency_symbol" name="currency_symbol" type="text" maxlength="10" value="' . esc($values['currency_symbol'], 'attr') . '">') ?>
                                </div>
                                <div class="login-field" data-autofill>
                                    <label class="login-label" for="currency_decimals"><?= esc(lang('Setup.currency_decimals')) ?></label>
                                    <?= $control('bi-123', '<input id="currency_decimals" name="currency_decimals" type="number" dir="ltr" min="0" max="4" value="' . esc($values['currency_decimals'], 'attr') . '">') ?>
                                </div>
                            </div>
                            <div class="login-alert is-warning setup-note" id="currency_help"><i class="bi bi-lock" aria-hidden="true"></i><p><?= esc(lang('Setup.currency_help')) ?></p></div>
                            <div class="login-field" data-autofill>
                                <label class="login-label" for="timezone"><?= esc(lang('Setup.timezone')) ?></label>
                                <div class="login-control"><i class="bi bi-clock" aria-hidden="true"></i>
                                    <select id="timezone" name="timezone" dir="ltr">
                                        <?php foreach ($timezones as $timezone): ?>
                                            <option value="<?= esc($timezone, 'attr') ?>"<?= $timezone === $values['timezone'] ? ' selected' : '' ?>><?= esc($timezone) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </section>
                        <?= $actions($previous) ?>
                        <?= form_close() ?>
                    <?php elseif ($step === 'company'): ?>
                        <?= form_open('setup/company', ['id' => 'form_company', 'class' => 'claim-form', 'data-step' => 'company', 'autocomplete' => 'off', 'novalidate' => 'novalidate']) ?>
                        <section class="claim-section">
                            <h2 class="claim-step"><span class="claim-step-number" aria-hidden="true"><?= $stepNumber ?></span> <?= esc(lang('Setup.company_title')) ?></h2>
                            <div class="login-field">
                                <label class="login-label" for="company"><?= esc(lang('Setup.company')) ?></label>
                                <?= $control('bi-buildings', '<input id="company" name="company" type="text" maxlength="255" value="' . esc($values['company'], 'attr') . '" required>') ?>
                            </div>
                            <div class="login-field">
                                <label class="login-label" for="address"><?= esc(lang('Setup.address')) ?> <span class="setup-optional"><?= esc(lang('Setup.optional')) ?></span></label>
                                <?= $control('bi-geo-alt', '<input id="address" name="address" type="text" maxlength="255" value="' . esc($values['address'], 'attr') . '">') ?>
                            </div>
                            <div class="claim-pair">
                                <div class="login-field">
                                    <label class="login-label" for="phone"><?= esc(lang('Setup.phone')) ?> <span class="setup-optional"><?= esc(lang('Setup.optional')) ?></span></label>
                                    <?= $control('bi-telephone', '<input id="phone" name="phone" type="tel" dir="ltr" maxlength="64" value="' . esc($values['phone'], 'attr') . '">') ?>
                                </div>
                                <div class="login-field">
                                    <label class="login-label" for="email"><?= esc(lang('Setup.email')) ?> <span class="setup-optional"><?= esc(lang('Setup.optional')) ?></span></label>
                                    <?= $control('bi-envelope', '<input id="email" name="email" type="email" dir="ltr" maxlength="255" value="' . esc($values['email'], 'attr') . '">') ?>
                                </div>
                            </div>
                            <div class="login-field">
                                <label class="login-label" for="tax_id"><?= esc(lang('Setup.tax_id')) ?> <span class="setup-optional"><?= esc(lang('Setup.optional')) ?></span></label>
                                <?= $control('bi-upc', '<input id="tax_id" name="tax_id" type="text" dir="ltr" maxlength="64" value="' . esc($values['tax_id'], 'attr') . '" aria-describedby="tax_id_help">') ?>
                                <p class="login-help" id="tax_id_help"><?= esc(lang('Setup.tax_id_help')) ?></p>
                            </div>
                        </section>
                        <?= $actions($previous) ?>
                        <?= form_close() ?>
                    <?php elseif ($step === 'branch'): ?>
                        <?= form_open('setup/branch', ['id' => 'form_branch', 'class' => 'claim-form', 'data-step' => 'branch', 'autocomplete' => 'off', 'novalidate' => 'novalidate']) ?>
                        <section class="claim-section">
                            <h2 class="claim-step"><span class="claim-step-number" aria-hidden="true"><?= $stepNumber ?></span> <?= esc(lang('Setup.branch_title')) ?></h2>
                            <div class="login-alert is-info setup-note"><i class="bi bi-info-circle" aria-hidden="true"></i><p><?= esc(lang('Setup.branch_help')) ?></p></div>
                            <div class="login-field">
                                <label class="login-label" for="branch_name"><?= esc(lang('Setup.branch_name')) ?></label>
                                <?= $control('bi-shop', '<input id="branch_name" name="branch_name" type="text" maxlength="100" value="' . esc($values['branch_name'], 'attr') . '" required>') ?>
                            </div>
                            <div class="login-field">
                                <label class="login-label" for="location_name"><?= esc(lang('Setup.location_name')) ?></label>
                                <?= $control('bi-box-seam', '<input id="location_name" name="location_name" type="text" maxlength="100" value="' . esc($values['location_name'], 'attr') . '" aria-describedby="location_help" required>') ?>
                                <p class="login-help" id="location_help"><?= esc(lang('Setup.location_help')) ?></p>
                            </div>
                        </section>
                        <?= $actions($previous) ?>
                        <?= form_close() ?>
                    <?php elseif ($step === 'tax'): ?>
                        <?= form_open('setup/tax', ['id' => 'form_tax', 'class' => 'claim-form', 'data-step' => 'tax', 'autocomplete' => 'off', 'novalidate' => 'novalidate']) ?>
                        <section class="claim-section">
                            <h2 class="claim-step"><span class="claim-step-number" aria-hidden="true"><?= $stepNumber ?></span> <?= esc(lang('Setup.tax_title')) ?></h2>
                            <p class="setup-help"><?= esc(lang('Setup.tax_help')) ?></p>
                            <div class="claim-pair">
                                <div class="login-field">
                                    <label class="login-label" for="tax_name"><?= esc(lang('Setup.tax_name')) ?></label>
                                    <?= $control('bi-receipt', '<input id="tax_name" name="tax_name" type="text" maxlength="100" value="' . esc($values['tax_name'], 'attr') . '">') ?>
                                </div>
                                <div class="login-field">
                                    <label class="login-label" for="tax_rate"><?= esc(lang('Setup.tax_rate')) ?></label>
                                    <?= $control('bi-percent', '<input id="tax_rate" name="tax_rate" type="text" inputmode="decimal" dir="ltr" maxlength="10" value="' . esc($values['tax_rate'], 'attr') . '">') ?>
                                </div>
                            </div>
                            <label class="setup-switch" for="tax_included">
                                <input type="checkbox" role="switch" id="tax_included" name="tax_included" value="1"<?= $values['tax_included'] === '1' ? ' checked' : '' ?> aria-describedby="tax_included_help">
                                <span><b><?= esc(lang('Setup.tax_included')) ?></b><small id="tax_included_help"><?= esc(lang('Setup.tax_included_help')) ?></small></span>
                            </label>
                        </section>
                        <?= $actions($previous) ?>
                        <?= form_close() ?>
                    <?php elseif ($step === 'accounting' && $activation !== null): ?>
                        <section class="claim-section">
                            <h2 class="claim-step"><span class="claim-step-number" aria-hidden="true"><?= $stepNumber ?></span> <?= esc(lang('Setup.accounting_title')) ?></h2>
                            <p class="setup-help"><?= esc(lang('Setup.accounting_help')) ?></p>
                            <?= view('accounting_settings/activation_panels', $activation + ['embedded' => true]) ?>
                        </section>
                    <?php else: ?>
                        <section class="claim-section">
                            <h2 class="claim-step"><span class="claim-step-number" aria-hidden="true"><?= $stepNumber ?></span> <?= esc(lang('Setup.finish_title')) ?></h2>
                            <?php if ($problems !== []): ?>
                                <div class="login-alert is-warning"><i class="bi bi-list-check" aria-hidden="true"></i><p><?= esc(lang('Setup.problems_title')) ?></p></div>
                                <ul class="setup-checklist">
                                    <?php foreach ($problems as $problem): ?>
                                        <?php $target = $problemStep($problem); ?>
                                        <li>
                                            <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
                                            <span><?= esc(lang('Setup.problem_' . $problem)) ?></span>
                                            <a class="btn btn-outline-secondary btn-sm" href="<?= esc($stepUrl($target), 'attr') ?>"><?= esc(lang('Setup.step_' . $target)) ?> <i class="bi bi-arrow-left setup-flip" aria-hidden="true"></i></a>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="setup-done">
                                    <span class="setup-done-icon" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                                    <p><?= esc(lang('Setup.finish_ready')) ?></p>
                                </div>
                            <?php endif; ?>
                        </section>
                        <?= form_open('setup/finish', ['id' => 'form_finish', 'class' => 'claim-form', 'data-step' => 'finish']) ?>
                        <div class="setup-actions">
                            <?php if ($previous !== null): ?>
                                <a class="btn btn-outline-secondary" href="<?= esc($stepUrl($previous), 'attr') ?>"><i class="bi bi-arrow-right setup-flip" aria-hidden="true"></i> <?= esc(lang('Accounting_activation.back')) ?></a>
                            <?php endif; ?>
                            <button class="btn btn-success setup-submit" type="submit"<?= $problems !== [] ? ' disabled' : '' ?>><i class="bi bi-arrow-repeat login-spin" aria-hidden="true" hidden></i> <span class="login-submit-label"><?= esc(lang('Setup.finish_button')) ?></span> <i class="bi bi-rocket-takeoff" aria-hidden="true"></i></button>
                        </div>
                        <?= form_close() ?>
                    <?php endif; ?>

                    <a class="setup-logout-link" href="<?= site_url('home/logout') ?>"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> <?= esc(lang('Login.logout')) ?></a>
                </main>

                <script>
                    $.ajaxPrefilter(function(options) {
                        if ((options.type || '').toUpperCase() === 'POST') {
                            const token = $.param({<?= json_encode(config('Security')->tokenName, $jsonFlags) ?>: <?= json_encode(csrf_hash(), $jsonFlags) ?>});

                            options.data = options.data ? options.data + '&' + token : token;
                        }
                    });

                    $(function() {
                        const fallback = <?= json_encode(lang('Setup.request_failed'), $jsonFlags) ?>;
                        const show = function(text) {
                            $('#setup_message').prop('hidden', false).find('p').text(text);
                            $('#setup_message')[0].scrollIntoView({block: 'nearest'});
                        };

                        const busy = function(button, on) {
                            button.prop('disabled', on).attr('aria-busy', on ? 'true' : 'false');
                            button.find('.login-spin').prop('hidden', !on);
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
                            $('[data-autofill]').removeClass('is-filled');
                            window.requestAnimationFrame(function() {
                                $('[data-autofill]').addClass('is-filled');
                            });
                        });

                        $('#currency_code').on('input', function() {
                            this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '');
                        });

                        $('form[data-step]').on('submit', function(event) {
                            event.preventDefault();

                            const form = $(this);
                            const button = form.find('button[type=submit]');

                            busy(button, true);
                            $('#setup_message').prop('hidden', true);

                            $.post(this.action, form.serialize(), null, 'json')
                                .done(function(response) {
                                    if (response.success) {
                                        window.location.href = response.next && response.next.indexOf('http') === 0 ? response.next : <?= json_encode(site_url('setup'), $jsonFlags) ?>;

                                        return;
                                    }

                                    busy(button, false);
                                    show(response.message);
                                })
                                .fail(function(request) {
                                    busy(button, false);
                                    show(request.responseJSON && request.responseJSON.message ? request.responseJSON.message : fallback);
                                });
                        });
                    });
                </script>
            <?php endif; ?>
            <p class="login-foot"><?= BRAND_NAME ?> · <?= BRAND_TAGLINE ?></p>
        </div>

        <aside class="login-brand" aria-label="<?= BRAND_NAME ?>">
            <img class="login-logo" src="<?= assetUrl('img/logo-full-white.webp') ?>" alt="<?= BRAND_NAME ?>">
            <p class="login-tagline"><?= BRAND_TAGLINE ?></p>
        </aside>
    </div>
</body>

</html>
