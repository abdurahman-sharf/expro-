<?php
/**
 * @var array{payment_methods: bool, second_language: bool, second_language_print: bool, second_language_code: string, api: bool, notifications: bool} $options
 * @var list<string>                                                                                                                                   $locales
 * @var bool                                                                                                                                           $canManage
 * @var bool                                                                                                                                           $canKeys
 * @var bool                                                                                                                                           $canRoute
 */
$languageChoices = ['' => lang('Integrations.no_language')];

foreach ($locales as $locale) {
    $languageChoices[$locale] = $locale;
}
?>

<?= view('partial/header') ?>
<?= view('integrations/ajax') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Integrations.title') ?></h1>
</div>

<p class="text-body-secondary"><?= lang('Integrations.intro') ?></p>

<div class="row g-3 mb-3">
    <?php if ($canManage): ?>
        <div class="col-12 col-md-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h6"><?= lang('Integrations.payment_methods') ?></h2>
                <p class="small text-body-secondary"><?= lang('Integrations.payment_methods_note') ?></p>
                <a class="btn btn-outline-primary btn-sm" id="link_payment_methods" href="<?= esc(base_url('integrations/payment_methods'), 'attr') ?>"><?= lang('Integrations.open') ?></a>
            </div></div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h6"><?= lang('Integrations.second_language') ?></h2>
                <p class="small text-body-secondary"><?= lang('Integrations.second_language_note') ?></p>
                <a class="btn btn-outline-primary btn-sm" id="link_translations" href="<?= esc(base_url('integrations/translations'), 'attr') ?>"><?= lang('Integrations.open') ?></a>
            </div></div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h6"><?= lang('Integrations.notification_rules') ?></h2>
                <p class="small text-body-secondary"><?= lang('Integrations.notification_rules_note') ?></p>
                <a class="btn btn-outline-primary btn-sm" id="link_rules" href="<?= esc(base_url('integrations/rules'), 'attr') ?>"><?= lang('Integrations.open') ?></a>
            </div></div>
        </div>
    <?php endif; ?>
    <?php if ($canKeys): ?>
        <div class="col-12 col-md-6">
            <div class="card h-100"><div class="card-body">
                <h2 class="h6"><?= lang('Integrations.api_keys') ?></h2>
                <p class="small text-body-secondary"><?= lang('Integrations.api_keys_note') ?></p>
                <a class="btn btn-outline-primary btn-sm" id="link_api_keys" href="<?= esc(base_url('api_keys'), 'attr') ?>"><?= lang('Integrations.open') ?></a>
            </div></div>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage || $canKeys): ?>
    <?= form_open('integrations/options', ['id' => 'integration_options_form', 'class' => 'app-form card integration-ajax mb-3']) ?>
        <div class="card-body">
            <h2 class="h6"><?= lang('Integrations.switches') ?></h2>
            <p class="small text-body-secondary"><?= lang('Integrations.switches_note') ?></p>
            <div class="form-grid">
                <?php if ($canManage): ?>
                    <?php foreach (['payment_methods' => 'switch_payment_methods', 'second_language' => 'switch_second_language', 'second_language_print' => 'switch_second_language_print', 'notifications' => 'switch_notifications'] as $flag => $label): ?>
                        <div class="field">
                            <label class="form-check-label" for="opt_<?= esc($flag, 'attr') ?>"><?= lang("Integrations.{$label}") ?></label>
                            <div class="field-control">
                                <input type="hidden" name="<?= esc($flag, 'attr') ?>" value="0">
                                <input type="checkbox" class="form-check-input" id="opt_<?= esc($flag, 'attr') ?>" name="<?= esc($flag, 'attr') ?>" value="1"<?= $options[$flag] ? ' checked' : '' ?>>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="field">
                        <label class="form-label" for="opt_second_language_code"><?= lang('Integrations.second_language_code') ?></label>
                        <div class="field-control">
                            <?= form_dropdown('second_language_code', $languageChoices, $options['second_language_code'], ['class' => 'form-select form-select-sm', 'id' => 'opt_second_language_code']) ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($canKeys): ?>
                    <div class="field">
                        <label class="form-check-label" for="opt_api"><?= lang('Integrations.switch_api') ?></label>
                        <div class="field-control">
                            <input type="hidden" name="api" value="0">
                            <input type="checkbox" class="form-check-input" id="opt_api" name="api" value="1"<?= $options['api'] ? ' checked' : '' ?>>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" id="integration_options_submit"><?= lang('Common.submit') ?></button>
        </div>
    <?= form_close() ?>
<?php endif; ?>

<div class="card mb-3" id="integration_not_built"><div class="card-body">
    <h2 class="h6"><?= lang('Integrations.not_built') ?></h2>
    <ul class="small mb-0">
        <li><?= lang('Integrations.not_built_gateways') ?></li>
        <li><?= lang('Integrations.not_built_outbound') ?></li>
        <li><?= lang('Integrations.not_built_webhooks') ?></li>
        <li><?= lang('Integrations.not_built_apps') ?></li>
    </ul>
</div></div>

<?= view('partial/footer') ?>
