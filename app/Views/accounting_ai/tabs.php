<?php
/**
 * @var 'findings'|'proposals'|'settings' $active
 */
$tabs = [
    'proposals' => ['accounting_ai', 'Accounting_ai.tab_proposals'],
    'findings'  => ['accounting_ai/findings', 'Accounting_ai.tab_findings'],
    'settings'  => ['accounting_ai/settings', 'Accounting_ai.tab_settings'],
];
?>
<ul class="nav nav-pills acc-tabs print_hide" aria-label="<?= esc(lang('Accounting_ai.sections'), 'attr') ?>">
    <?php foreach ($tabs as $key => [$path, $label]): ?>
        <li class="nav-item">
            <a class="nav-link<?= $key === $active ? ' active' : '' ?>" href="<?= esc(base_url($path), 'attr') ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= lang($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
