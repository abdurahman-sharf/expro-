<?php

use App\Libraries\Integrations\NotificationRule;
use App\Libraries\Integrations\NotificationRuleKind;

/**
 * @var list<NotificationRule>     $rules
 * @var list<NotificationRuleKind> $kinds
 * @var bool                       $enabled
 */
$kindChoices = [];

foreach ($kinds as $kind) {
    $kindChoices[$kind->value] = lang($kind->languageKey());
}
?>

<?= view('partial/header') ?>
<?= view('integrations/ajax') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Integrations.notification_rules') ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('integrations'), 'attr') ?>"><?= lang('Integrations.back') ?></a>
</div>

<p class="text-body-secondary"><?= lang('Integrations.rules_help') ?></p>
<div class="alert alert-secondary" id="rules_no_outbound" role="note"><?= lang('Integrations.rules_no_outbound') ?></div>

<?php if (! $enabled): ?>
    <div class="alert alert-warning" id="rules_off" role="status"><?= lang('Integrations.rules_off') ?></div>
<?php endif; ?>

<?= form_open('integrations/rules/save', ['id' => 'rule_form', 'class' => 'app-form card integration-ajax mb-3']) ?>
    <div class="card-body">
        <h2 class="h6"><?= lang('Integrations.rule_new') ?></h2>
        <div class="form-grid">
            <div class="field">
                <label class="form-label" for="rule_kind"><?= lang('Integrations.rule_kind') ?></label>
                <div class="field-control"><?= form_dropdown('kind', $kindChoices, '', ['class' => 'form-select form-select-sm', 'id' => 'rule_kind']) ?></div>
            </div>
            <div class="field">
                <label class="form-label" for="rule_name"><?= lang('Integrations.rule_name') ?></label>
                <div class="field-control"><input type="text" class="form-control form-control-sm" id="rule_name" name="name" maxlength="100"></div>
            </div>
            <div class="field">
                <label class="form-label" for="rule_threshold"><?= lang('Integrations.rule_days') ?></label>
                <div class="field-control">
                    <input type="number" class="form-control form-control-sm" id="rule_threshold" name="threshold" min="0" max="365" value="7">
                    <p class="form-text mb-0"><?= lang('Integrations.rule_days_help') ?></p>
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" id="rule_submit"><?= lang('Common.submit') ?></button>
    </div>
<?= form_close() ?>

<?php if ($rules === []): ?>
    <p class="text-body-secondary" id="rules_empty"><?= lang('Integrations.no_rules') ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle" id="rules_table">
            <thead>
                <tr>
                    <th><?= lang('Integrations.rule_name') ?></th>
                    <th><?= lang('Integrations.rule_kind') ?></th>
                    <th><?= lang('Integrations.rule_days') ?></th>
                    <th><?= lang('Integrations.rule_active') ?></th>
                    <th><span class="visually-hidden"><?= lang('Integrations.actions') ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule): ?>
                    <?php $formId = 'rule_form_' . $rule->id; ?>
                    <tr data-rule="<?= $rule->id ?>">
                        <td>
                            <?= form_open('integrations/rules/save/' . $rule->id, ['id' => $formId, 'class' => 'integration-ajax']) ?><?= form_close() ?>
                            <input type="text" class="form-control form-control-sm" name="name" form="<?= $formId ?>" maxlength="100" value="<?= esc($rule->name, 'attr') ?>" aria-label="<?= esc(lang('Integrations.rule_name'), 'attr') ?>">
                        </td>
                        <td><?= esc(lang($rule->kind->languageKey())) ?></td>
                        <td>
                            <?php if ($rule->kind->takesThreshold()): ?>
                                <input type="number" class="form-control form-control-sm" name="threshold" form="<?= $formId ?>" min="<?= $rule->kind->lowest() ?>" max="<?= $rule->kind->highest() ?>" value="<?= (int) $rule->threshold ?>" aria-label="<?= esc(lang('Integrations.rule_days'), 'attr') ?>">
                            <?php else: ?>
                                <span class="text-body-secondary">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge <?= $rule->active ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= lang($rule->active ? 'Integrations.active' : 'Integrations.inactive') ?></span></td>
                        <td class="text-nowrap">
                            <button type="submit" form="<?= $formId ?>" class="btn btn-outline-primary btn-sm" title="<?= esc(lang('Common.submit'), 'attr') ?>"><span class="bi bi-check-lg" aria-hidden="true"></span></button>
                            <button type="button" class="btn btn-outline-secondary btn-sm integration-post" data-url="<?= esc(base_url('integrations/rules/active/' . $rule->id . '/' . ($rule->active ? 0 : 1)), 'attr') ?>" title="<?= esc(lang($rule->active ? 'Integrations.deactivate' : 'Integrations.activate'), 'attr') ?>"><span class="bi <?= $rule->active ? 'bi-toggle-on' : 'bi-toggle-off' ?>" aria-hidden="true"></span></button>
                            <button type="button" class="btn btn-outline-danger btn-sm integration-post" data-url="<?= esc(base_url('integrations/rules/delete/' . $rule->id), 'attr') ?>" title="<?= esc(lang('Common.delete'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= view('partial/footer') ?>
