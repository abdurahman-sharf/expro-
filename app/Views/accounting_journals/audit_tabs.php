<?php
/**
 * @var 'failures'|'log' $active
 */
$tabs = [
    'log'      => ['accounting_journals/audit', 'Accounting_journals.audit_tab_log'],
    'failures' => ['accounting_journals/failures', 'Accounting_journals.audit_tab_failures'],
];
?>
<ul class="nav nav-pills acc-tabs print_hide" aria-label="<?= esc(lang('Accounting_journals.audit_sections'), 'attr') ?>">
    <?php foreach ($tabs as $key => [$path, $label]): ?>
        <li class="nav-item">
            <a class="nav-link<?= $key === $active ? ' active' : '' ?>" href="<?= esc(base_url($path), 'attr') ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= lang($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>
