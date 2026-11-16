<?php
/**
 * @var list<object>         $rows
 * @var array<string, mixed> $ctx
 * @var int                  $parent_id
 * @var int|null             $next
 * @var int                  $remaining
 */
?>
<?php foreach ($rows as $row): ?>
    <?= view('accounting_chart/node', ['account' => $row, 'ctx' => $ctx]) ?>
<?php endforeach; ?>
<?php if ($next !== null): ?>
    <li class="acc-linked">
        <button type="button" class="btn btn-sm btn-outline-secondary acc-load" data-branch="<?= esc("accounting_chart/branch/{$parent_id}?offset={$next}", 'attr') ?>"><span class="bi bi-chevron-double-down" aria-hidden="true"></span> <?= esc(lang('Accounting_chart.show_more', [$remaining])) ?></button>
    </li>
<?php endif; ?>
