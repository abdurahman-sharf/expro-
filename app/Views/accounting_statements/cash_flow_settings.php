<?php
/**
 * @var list<CashFlowLineConfig> $lines
 * @var string                   $version
 * @var list<object>             $accounts
 * @var list<CashFlowFormula>    $formulas
 * @var list<CashFlowSection>    $sections
 * @var bool                     $canManage
 * @var int                      $maxCustom
 * @var int                      $titleMax
 * @var Closure                  $defaultFor
 */

use App\Libraries\Accounting\CashFlowFormula;
use App\Libraries\Accounting\CashFlowLineConfig;
use App\Libraries\Accounting\CashFlowSection;

$disabled = $canManage ? '' : ' disabled';
$index    = 0;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const form = $('#cash_flow_settings_form');

        form.on('submit', function(event) {
            event.preventDefault();

            $.post('accounting_statements/cash_flow/settings/save', form.serialize(), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');

                if (response.success) {
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 600);
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url('accounting_statements/cash_flow'), 'attr') ?>">
        <span class="bi bi-cash-stack" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cash_flow.title') ?>
    </a>
</div>

<div class="container-fluid py-3" id="cash_flow_settings">
    <h1 class="h4"><?= lang('Accounting_cash_flow.settings_title') ?></h1>
    <p class="text-body-secondary"><?= lang('Accounting_cash_flow.settings_intro', [$maxCustom]) ?></p>

    <form id="cash_flow_settings_form" method="post" action="<?= esc(base_url('accounting_statements/cash_flow/settings/save'), 'attr') ?>">
        <input type="hidden" name="version" value="<?= esc($version, 'attr') ?>">
        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle" id="cash_flow_lines">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_cash_flow.settings_title_column') ?></th>
                        <th scope="col"><?= lang('Accounting_cash_flow.settings_section') ?></th>
                        <th scope="col"><?= lang('Accounting_cash_flow.settings_accounts') ?></th>
                        <th scope="col"><?= lang('Accounting_cash_flow.settings_formula') ?></th>
                        <th scope="col"><?= lang('Accounting_cash_flow.settings_visible') ?></th>
                        <th scope="col"><?= lang('Accounting_cash_flow.settings_remove') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lines as $line): ?>
                        <?php $name = 'lines[' . $index++ . ']'; ?>
                        <tr data-line="<?= esc($line->key, 'attr') ?>">
                            <td>
                                <input type="hidden" name="<?= $name ?>[key]" value="<?= esc($line->key, 'attr') ?>">
                                <input type="text" class="form-control form-control-sm" name="<?= $name ?>[title]" maxlength="<?= (int) $titleMax ?>" value="<?= esc($line->title, 'attr') ?>" placeholder="<?= esc($line->custom ? '' : $defaultFor($line->key), 'attr') ?>" aria-label="<?= esc(lang('Accounting_cash_flow.settings_title_column'), 'attr') ?>"<?= $disabled ?>>
                            </td>
                            <td>
                                <?php if ($line->custom): ?>
                                    <select class="form-select form-select-sm" name="<?= $name ?>[section]" aria-label="<?= esc(lang('Accounting_cash_flow.settings_section'), 'attr') ?>"<?= $disabled ?>>
                                        <?php foreach ($sections as $section): ?>
                                            <option value="<?= esc($section->value, 'attr') ?>"<?= $section === $line->section ? ' selected' : '' ?>><?= lang('Accounting_cash_flow.section_' . $section->value) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <?= lang('Accounting_cash_flow.section_' . $line->section->value) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($line->key === 'net_income'): ?>
                                    <span class="text-body-secondary"><?= lang('Accounting_cash_flow.settings_net_income_accounts') ?></span>
                                <?php else: ?>
                                    <input type="hidden" name="<?= $name ?>[accounts][]" value="">
                                    <select class="form-select form-select-sm" multiple size="3" name="<?= $name ?>[accounts][]" aria-label="<?= esc(lang('Accounting_cash_flow.settings_accounts'), 'attr') ?>"<?= $disabled ?>>
                                        <?php foreach ($accounts as $account): ?>
                                            <option value="<?= (int) $account->account_id ?>"<?= in_array($account->account_id, $line->accountIds, true) ? ' selected' : '' ?>><?= esc($account->code . ' - ' . $account->name) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (! $line->custom): ?>
                                        <span class="small text-body-secondary"><?= lang('Accounting_cash_flow.settings_default_accounts') ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($line->key === 'net_income'): ?>
                                    <?= lang('Accounting_cash_flow.formula_credit_in_period') ?>
                                <?php else: ?>
                                    <select class="form-select form-select-sm" name="<?= $name ?>[formula]" aria-label="<?= esc(lang('Accounting_cash_flow.settings_formula'), 'attr') ?>"<?= $disabled ?>>
                                        <?php foreach ($formulas as $formula): ?>
                                            <option value="<?= esc($formula->value, 'attr') ?>"<?= $formula === $line->formula ? ' selected' : '' ?>><?= lang('Accounting_cash_flow.formula_' . $formula->value) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td>
                                <input type="hidden" name="<?= $name ?>[visible]" value="0">
                                <input type="checkbox" class="form-check-input" name="<?= $name ?>[visible]" value="1"<?= $line->visible ? ' checked' : '' ?> aria-label="<?= esc(lang('Accounting_cash_flow.settings_visible'), 'attr') ?>"<?= $disabled ?>>
                            </td>
                            <td>
                                <?php if ($line->custom): ?>
                                    <input type="checkbox" class="form-check-input" name="<?= $name ?>[remove]" value="1" aria-label="<?= esc(lang('Accounting_cash_flow.settings_remove'), 'attr') ?>"<?= $disabled ?>>
                                <?php else: ?>
                                    <span class="small text-body-secondary"><?= lang('Accounting_cash_flow.settings_hide_only') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php $name = 'lines[' . $index . ']'; ?>
                    <tr data-line="new">
                        <td>
                            <input type="hidden" name="<?= $name ?>[key]" value="">
                            <input type="text" class="form-control form-control-sm" name="<?= $name ?>[title]" maxlength="<?= (int) $titleMax ?>" placeholder="<?= esc(lang('Accounting_cash_flow.settings_new_line'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_cash_flow.settings_new_line'), 'attr') ?>"<?= $disabled ?>>
                        </td>
                        <td>
                            <select class="form-select form-select-sm" name="<?= $name ?>[section]" aria-label="<?= esc(lang('Accounting_cash_flow.settings_section'), 'attr') ?>"<?= $disabled ?>>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?= esc($section->value, 'attr') ?>"><?= lang('Accounting_cash_flow.section_' . $section->value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="hidden" name="<?= $name ?>[accounts][]" value="">
                            <select class="form-select form-select-sm" multiple size="3" name="<?= $name ?>[accounts][]" aria-label="<?= esc(lang('Accounting_cash_flow.settings_accounts'), 'attr') ?>"<?= $disabled ?>>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?= (int) $account->account_id ?>"><?= esc($account->code . ' - ' . $account->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select class="form-select form-select-sm" name="<?= $name ?>[formula]" aria-label="<?= esc(lang('Accounting_cash_flow.settings_formula'), 'attr') ?>"<?= $disabled ?>>
                                <?php foreach ($formulas as $formula): ?>
                                    <option value="<?= esc($formula->value, 'attr') ?>"><?= lang('Accounting_cash_flow.formula_' . $formula->value) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="hidden" name="<?= $name ?>[visible]" value="0">
                            <input type="checkbox" class="form-check-input" name="<?= $name ?>[visible]" value="1" checked aria-label="<?= esc(lang('Accounting_cash_flow.settings_visible'), 'attr') ?>"<?= $disabled ?>>
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($canManage): ?>
            <button type="submit" class="btn btn-primary btn-sm"><?= lang('Common.submit') ?></button>
        <?php else: ?>
            <p class="form-text mb-0" id="settings_read_only" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_cash_flow.settings_read_only') ?></p>
        <?php endif; ?>
    </form>
</div>

<?= view('partial/footer') ?>
