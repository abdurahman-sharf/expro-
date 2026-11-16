<?php
/**
 * @var 'backfill'|'closings'|'opening'|'opening_import'|'year_end'|'years' $active
 * @var list<string>|null                                                   $allowed_modules
 */

use App\Libraries\Navigation\SidebarMenu;

helper('navigation');

$tabs = [
    'years'          => ['accounting_periods', 'Accounting_periods.tab_years', 'accounting_periods'],
    'closings'       => ['accounting_periods/closings', 'Accounting_periods.tab_closings', 'closings'],
    'opening'        => ['accounting_periods/opening', 'Accounting_periods.tab_opening', 'opening'],
    'opening_import' => ['accounting_periods/opening_import', 'Accounting_opening_import.tab', null],
    'year_end'       => ['accounting_periods/year_end', 'Accounting_periods.tab_year_end', 'year_end'],
    'backfill'       => ['accounting_periods/backfill', 'Accounting_periods.tab_backfill', 'backfill'],
];

[$held, $permissions] = navigationGrants((int) session('person_id'));
$modules              = array_map(static fn (object $module): string => $module->module_id, $allowed_modules ?? []);
$opens                = static fn (?string $pageId): bool => $pageId === null || SidebarMenu::opens(SidebarMenu::page($pageId), $modules, $held, $permissions);
?>
<ul class="nav nav-pills acc-tabs print_hide" aria-label="<?= esc(lang('Accounting_periods.sections'), 'attr') ?>">
    <?php foreach ($tabs as $key => [$path, $label, $pageId]): ?>
        <?php if ($opens($pageId)): ?>
            <li class="nav-item">
                <a class="nav-link<?= $key === $active ? ' active' : '' ?>" href="<?= esc(base_url($path), 'attr') ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= lang($label) ?></a>
            </li>
        <?php endif; ?>
    <?php endforeach; ?>
</ul>
