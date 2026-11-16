<?php
/**
 * @var object               $account
 * @var array<string, mixed> $ctx
 * @var bool                 $showParent
 */

use App\Libraries\Accounting\ChartRowActions;

$showParent ??= false;

$id          = (int) $account->account_id;
$children    = $ctx['nodes'][$id] ?? [];
$entityCount = (int) $account->entity_child_count;
$hasKids     = ! $showParent && ($children !== [] || $entityCount > 0);
$isOpen      = $hasKids && isset($ctx['open'][$id]);
$active      = (int) $account->active === 1;
$isGroup     = (int) $account->is_group === 1;
$parent      = $account->parent_id === null ? null : ($ctx['by_id'][(int) $account->parent_id] ?? null);
$actions     = ChartRowActions::for($account, $ctx['can_manage'], $ctx['can_ledger'], $parent === null || (int) $parent->active === 1);
$rowClasses  = 'acc-row' . ($ctx['selected_id'] === $id ? ' is-selected' : '') . ($active ? '' : ' is-inactive');
$total       = (int) $account->child_count + $entityCount;
?>
<li class="acc-node<?= $ctx['highlight_id'] === $id ? ' is-new' : '' ?>" data-account-id="<?= $id ?>" data-type="<?= esc($account->account_type, 'attr') ?>">
    <div class="<?= $rowClasses ?>"<?= $ctx['selected_id'] === $id ? ' aria-current="true"' : '' ?>>
        <?php if ($hasKids): ?>
            <button type="button" class="acc-caret" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-label="<?= esc(lang('Accounting_chart.toggle_branch'), 'attr') ?>"><span class="bi bi-chevron-down" aria-hidden="true"></span></button>
        <?php else: ?>
            <span class="acc-caret-space" aria-hidden="true"></span>
        <?php endif; ?>
        <span class="acc-icon type-<?= esc($account->account_type, 'attr') ?>" title="<?= esc(lang('Accounting.account_type_' . $account->account_type), 'attr') ?>"><span class="bi <?= $isGroup ? 'bi-folder2' : 'bi-file-earmark-text' ?>" aria-hidden="true"></span></span>
        <bdi class="acc-code" dir="ltr"><?= esc($account->code) ?></bdi>
        <span class="acc-name"><?= esc($account->name) ?></span>
        <?php if ($showParent && $parent !== null): ?>
            <small class="acc-parent"><?= esc($parent->name) ?></small>
        <?php endif; ?>
        <?php if ($hasKids && $total > 0): ?>
            <span class="acc-count" title="<?= esc(lang('Accounting_chart.children_count'), 'attr') ?>"><?= $total ?></span>
        <?php else: ?>
            <span class="acc-count-space" aria-hidden="true"></span>
        <?php endif; ?>
        <span class="acc-chips">
            <span class="acc-chip <?= $active ? 'is-on' : 'is-off' ?>"><?= esc(lang('Accounting.state_' . ($active ? 'active' : 'inactive'))) ?></span>
            <?php if ((int) $account->is_system === 1): ?>
                <span class="acc-chip is-system"><?= esc(lang('Accounting.state_system')) ?></span>
            <?php endif; ?>
        </span>
        <span class="acc-balance"><bdi dir="ltr"><?= esc(to_currency_no_money($ctx['balances'][$id]['balance'] ?? '0.0000')) ?></bdi></span>
        <?php if ($actions !== []): ?>
            <button type="button" class="btn btn-sm acc-more" aria-expanded="false" title="<?= esc(lang('Accounting_chart.more_actions'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_chart.more_actions'), 'attr') ?>"><span class="bi bi-three-dots" aria-hidden="true"></span></button>
            <span class="acc-actions"><?= view('accounting_chart/actions', ['account' => $account, 'actions' => $actions]) ?></span>
        <?php endif; ?>
    </div>
    <?php if ($hasKids): ?>
        <ul class="acc-children"<?= $isOpen ? '' : ' hidden' ?>>
            <?php foreach ($children as $child): ?>
                <?= view('accounting_chart/node', ['account' => $child, 'ctx' => $ctx]) ?>
            <?php endforeach; ?>
            <?php if ($entityCount > 0): ?>
                <li class="acc-linked">
                    <button type="button" class="btn btn-sm btn-outline-secondary acc-load" data-branch="<?= esc("accounting_chart/branch/{$id}?offset=0", 'attr') ?>"><span class="bi bi-people" aria-hidden="true"></span> <?= esc(lang('Accounting_chart.show_linked', [$entityCount])) ?></button>
                </li>
            <?php endif; ?>
        </ul>
    <?php endif; ?>
</li>
