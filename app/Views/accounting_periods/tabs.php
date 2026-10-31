<?php
/**
 * @var 'years'|'closings'|'opening'|'year_end' $active
 */

$tabs = [
    'years'    => ['accounting_periods', 'Accounting_periods.tab_years'],
    'closings' => ['accounting_periods/closings', 'Accounting_periods.tab_closings'],
    'opening'  => ['accounting_periods/opening', 'Accounting_periods.tab_opening'],
    'year_end' => ['accounting_periods/year_end', 'Accounting_periods.tab_year_end'],
];
?>
<ul class="nav nav-pills acc-tabs print_hide" aria-label="<?= esc(lang('Accounting_periods.sections'), 'attr') ?>">
    <?php foreach ($tabs as $key => [$path, $label]): ?>
        <li class="nav-item">
            <a class="nav-link<?= $key === $active ? ' active' : '' ?>" href="<?= esc(base_url($path), 'attr') ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= lang($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
