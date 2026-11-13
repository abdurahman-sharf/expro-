<?php

use App\Libraries\Integrations\PaymentMethod;
use App\Libraries\Integrations\PaymentMethods;

/**
 * @var list<PaymentMethod>                                               $methods
 * @var array<string, array{account: string, treasury: string|null}|null> $routes
 * @var bool                                                              $custom
 * @var bool                                                              $canRoute
 */
?>

<?= view('partial/header') ?>
<?= view('integrations/ajax') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Integrations.payment_methods') ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('integrations'), 'attr') ?>"><?= lang('Integrations.back') ?></a>
</div>

<p class="text-body-secondary"><?= lang('Integrations.payment_methods_help') ?></p>

<?php if (! $custom): ?>
    <div class="alert alert-warning" id="payment_methods_off" role="status"><?= lang('Integrations.payment_methods_off') ?></div>
<?php endif; ?>

<?= form_open('integrations/payment_methods', ['id' => 'payment_methods_form', 'class' => 'app-form integration-ajax']) ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle" id="payment_methods_table">
            <thead>
                <tr>
                    <th><?= lang('Integrations.method') ?></th>
                    <th><?= lang('Integrations.method_name') ?></th>
                    <th><?= lang('Integrations.method_enabled') ?></th>
                    <th><?= lang('Integrations.method_routed_to') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($methods as $method): ?>
                    <?php $code = $method->type->value; ?>
                    <tr data-method="<?= esc($code, 'attr') ?>">
                        <td><?= esc($method->defaultName) ?></td>
                        <td>
                            <input type="text" class="form-control form-control-sm" name="name[<?= esc($code, 'attr') ?>]" maxlength="<?= PaymentMethods::MAX_NAME ?>" value="<?= esc($method->customName ?? '', 'attr') ?>" placeholder="<?= esc($method->defaultName, 'attr') ?>">
                        </td>
                        <td>
                            <?php if ($method->mayBeSwitchedOff): ?>
                                <input type="hidden" name="enabled[<?= esc($code, 'attr') ?>]" value="0">
                                <input type="checkbox" class="form-check-input" name="enabled[<?= esc($code, 'attr') ?>]" value="1"<?= $method->enabled ? ' checked' : '' ?> aria-label="<?= esc(lang('Integrations.method_enabled'), 'attr') ?>">
                            <?php else: ?>
                                <input type="hidden" name="enabled[<?= esc($code, 'attr') ?>]" value="1">
                                <input type="checkbox" class="form-check-input" checked disabled aria-label="<?= esc(lang('Integrations.method_always_on'), 'attr') ?>">
                                <span class="small text-body-secondary"><?= lang('Integrations.method_always_on') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if (($routes[$code] ?? null) === null): ?>
                                <span class="text-body-secondary"><?= lang('Integrations.not_routed') ?></span>
                            <?php else: ?>
                                <bdi><?= esc($routes[$code]['account']) ?></bdi>
                                <?php if ($routes[$code]['treasury'] !== null): ?>
                                    <br><?= lang('Integrations.treasury') ?>: <?= esc($routes[$code]['treasury']) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="small text-body-secondary"><?= lang('Integrations.payment_methods_routing_note') ?>
        <?php if ($canRoute): ?>
            <a href="<?= esc(base_url('accounting_routing'), 'attr') ?>"><?= lang('Integrations.open_routing') ?></a>
        <?php endif; ?>
    </p>
    <button type="submit" class="btn btn-primary btn-sm" id="payment_methods_submit"><?= lang('Common.submit') ?></button>
<?= form_close() ?>

<?= view('partial/footer') ?>
