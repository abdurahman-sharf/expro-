<?php
/**
 * @var 'assets'|'categories'|'depreciation' $active
 */
$tabs = [
    'assets'       => ['accounting_assets', 'Accounting_assets.tab_assets'],
    'categories'   => ['accounting_assets/categories', 'Accounting_assets.tab_categories'],
    'depreciation' => ['accounting_assets/depreciation', 'Accounting_assets.tab_depreciation'],
];
?>
<ul class="nav nav-pills acc-tabs print_hide" aria-label="<?= esc(lang('Accounting_assets.sections'), 'attr') ?>">
    <?php foreach ($tabs as $key => [$path, $label]): ?>
        <li class="nav-item">
            <a class="nav-link<?= $key === $active ? ' active' : '' ?>" href="<?= esc(base_url($path), 'attr') ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= lang($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
