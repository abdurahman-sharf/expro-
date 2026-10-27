<?php
/**
 * @var array{level: int, display: StatementDisplay, entities: bool, branches: list<int>} $filters
 * @var list<int>                                                                         $levels
 * @var list<object>                                                                      $branches
 * @var bool                                                                              $offerEntities
 */

use App\Libraries\Accounting\StatementDisplay;

?>
<div class="acc-filter">
    <label class="form-label" for="f_level"><?= lang('Accounting_statements.level') ?></label>
    <select id="f_level" name="level" class="form-select form-select-sm">
        <?php foreach ($levels as $level): ?>
            <option value="<?= (int) $level ?>"<?= $level === $filters['level'] ? ' selected' : '' ?>><?= lang('Accounting_statements.level_n', [$level]) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="acc-filter">
    <label class="form-label" for="f_display"><?= lang('Accounting_statements.display') ?></label>
    <select id="f_display" name="display" class="form-select form-select-sm">
        <?php foreach (StatementDisplay::cases() as $option): ?>
            <option value="<?= esc($option->value, 'attr') ?>"<?= $option === $filters['display'] ? ' selected' : '' ?>><?= lang('Accounting_statements.display_' . $option->value) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php if ($offerEntities): ?>
    <fieldset class="acc-filter acc-filter-status">
        <legend class="form-label"><?= lang('Accounting_reports.show') ?></legend>
        <label class="choice"><input type="checkbox" class="form-check-input" id="f_entities" name="entities" value="1"<?= $filters['entities'] ? ' checked' : '' ?>> <?= lang('Accounting_reports.show_entities') ?></label>
    </fieldset>
<?php endif; ?>
<?php if ($branches !== []): ?>
    <fieldset class="acc-filter acc-filter-status" id="f_branches">
        <legend class="form-label"><?= lang('Accounting_reports.branches') ?></legend>
        <?php foreach ($branches as $branch): ?>
            <label class="choice"><input type="checkbox" class="form-check-input" name="branches[]" value="<?= (int) $branch->branch_id ?>"<?= in_array((int) $branch->branch_id, $filters['branches'], true) ? ' checked' : '' ?>> <?= esc($branch->code . ' - ' . $branch->name) ?><?= $branch->active ? '' : ' (' . esc(lang('Accounting_reports.branch_inactive')) . ')' ?></label>
        <?php endforeach; ?>
    </fieldset>
<?php endif; ?>
