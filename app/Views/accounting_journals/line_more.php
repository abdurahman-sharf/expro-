<?php
/**
 * The tags and the tax note of a journal line, folded away until someone wants them. Both are notes: the
 * tax posts nothing and enters no tax report.
 *
 * @var string                                 $name     The prefix of the field names, such as lines[3].
 * @var LineAnalysis|null                      $analysis What the line carries now.
 * @var array{line_tags: bool, line_tax: bool} $settings
 */

use App\Libraries\Accounting\LineAnalysis;

if (! $settings['line_tags'] && ! $settings['line_tax']) {
    return;
}

$tax = $analysis?->tax;
?>
<details class="acc-line-more"<?= $analysis !== null && ($analysis->tags !== [] || $tax !== null) ? ' open' : '' ?>>
    <summary><?= lang('Accounting_journals.more_details') ?></summary>
    <?php if ($settings['line_tags']): ?>
        <label class="form-label small mb-0"><?= lang('Accounting_journals.tags') ?></label>
        <input type="text" name="<?= esc($name . '[tags]', 'attr') ?>" class="form-control form-control-sm" maxlength="400" value="<?= esc(implode(', ', $analysis?->tags ?? []), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.tags'), 'attr') ?>">
        <span class="form-text"><?= lang('Accounting_journals.tags_hint') ?></span>
    <?php endif; ?>
    <?php if ($settings['line_tax']): ?>
        <label class="form-label small mb-0"><?= lang('Accounting_journals.tax_note') ?></label>
        <div class="acc-line-tax">
            <input type="text" name="<?= esc($name . '[tax_code]', 'attr') ?>" class="form-control form-control-sm" maxlength="16" value="<?= esc((string) ($tax['code'] ?? ''), 'attr') ?>" placeholder="<?= esc(lang('Accounting_journals.tax_code'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.tax_code'), 'attr') ?>">
            <input type="text" name="<?= esc($name . '[tax_percent]', 'attr') ?>" class="form-control form-control-sm ltr-input acc-tax-percent" dir="ltr" inputmode="decimal" value="<?= esc($tax === null ? '' : rtrim(rtrim($tax['percent'], '0'), '.'), 'attr') ?>" placeholder="<?= esc(lang('Accounting_journals.tax_percent'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.tax_percent'), 'attr') ?>">
            <label class="form-check">
                <input type="hidden" name="<?= esc($name . '[tax_included]', 'attr') ?>" value="0">
                <input type="checkbox" class="form-check-input" name="<?= esc($name . '[tax_included]', 'attr') ?>" value="1"<?= $tax !== null && $tax['included'] ? ' checked' : '' ?>>
                <?= lang('Accounting_journals.tax_included') ?>
            </label>
        </div>
        <span class="form-text"><?= lang('Accounting_journals.tax_note_hint') ?></span>
    <?php endif; ?>
</details>
