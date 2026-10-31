<?php
/**
 * @var string $active
 * @var bool   $company_scope
 */
$tabs = [
    'balances'     => ['accounting_receivables', 'Accounting_receivables.balances_tab'],
    'payments'     => ['accounting_receivables/payments', 'Accounting_receivables.payments_tab'],
    'credit_notes' => ['accounting_receivables/credit_notes', 'Accounting_receivables.credit_notes_tab'],
];
?>
<nav class="print_hide mb-2" aria-label="<?= esc(lang('Accounting_receivables.documents'), 'attr') ?>">
    <ul class="nav nav-pills nav-fill-sm">
        <?php foreach ($tabs as $key => [$path, $label]): ?>
            <?php if ($company_scope || $key === 'payments'): ?>
                <li class="nav-item">
                    <a class="nav-link py-1<?= $active === $key ? ' active' : '' ?>" href="<?= esc(base_url($path), 'attr') ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>><?= lang($label) ?></a>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
    </ul>
</nav>
