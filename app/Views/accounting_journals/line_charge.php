<?php
/**
 * What a journal line is charged to: one cost center, or a distribution over several by percentage.
 * The rows are drawn by the script of distribution_script.
 *
 * @var string             $name     The prefix of the field names, such as lines[3].
 * @var LineAnalysis|null  $analysis What the line carries now.
 * @var list<object>       $centers  The cost centers that may be chosen.
 * @var array<int, string> $labels   The words that name every cost center, switched off ones included.
 * @var string|null        $amount   The amount of the line when it cannot change, in plain text.
 */

use App\Libraries\Accounting\LineAnalysis;

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$selected  = $analysis?->costCenterId;
$rows      = array_map(static fn (array $row): array => ['id' => $row['cost_center_id'], 'percent' => rtrim(rtrim($row['percent'], '0'), '.')], $analysis?->distribution ?? []);
$options   = '<option value="">' . esc(lang('Accounting_journals.no_cost_center')) . '</option>';
$listed    = false;

foreach ($centers as $center) {
    $isSelected = $selected === (int) $center->cost_center_id;
    $listed     = $listed || $isSelected;
    $options .= '<option value="' . (int) $center->cost_center_id . '"' . ($isSelected ? ' selected' : '') . '>' . esc($center->code . ' - ' . $center->name) . '</option>';
}

if ($selected !== null && ! $listed) {
    $options .= '<option value="' . $selected . '" selected>' . esc($labels[$selected] ?? (string) $selected) . '</option>';
}
?>
<div class="acc-dist" data-name="<?= esc($name, 'attr') ?>" data-rows="<?= esc(json_encode($rows, $jsonFlags), 'attr') ?>"<?= $amount === null ? '' : ' data-amount="' . esc($amount, 'attr') . '"' ?>>
    <div class="acc-dist-single">
        <select name="<?= esc($name . '[cost_center_id]', 'attr') ?>" class="form-select form-select-sm acc-dist-select" aria-label="<?= esc(lang('Accounting_journals.cost_center'), 'attr') ?>"><?= $options ?></select>
        <button type="button" class="btn btn-outline-secondary btn-sm acc-dist-toggle print_hide" aria-expanded="false" title="<?= esc(lang('Accounting_journals.distribute'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.distribute'), 'attr') ?>"><span class="bi bi-diagram-3" aria-hidden="true"></span></button>
    </div>
    <div class="acc-dist-panel" hidden></div>
</div>
