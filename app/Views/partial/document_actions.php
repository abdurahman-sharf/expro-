<?php
/**
 * @var list<array{id: string, label: string, icon: string, class?: string, href?: string, target?: string, action?: string}> $actions
 */
?>

<div class="print_hide document-actions" id="control_buttons">
    <?php foreach ($actions as $action): ?>
        <?php $attributes = 'id="' . esc($action['id'], 'attr') . '" class="btn ' . esc($action['class'] ?? 'btn-outline-secondary', 'attr') . '"'; ?>
        <?php if (isset($action['href'])): ?>
            <a <?= $attributes ?> href="<?= site_url($action['href']) ?>"<?= isset($action['target']) ? ' target="' . esc($action['target'], 'attr') . '" rel="noopener"' : '' ?>>
                <span class="bi <?= esc($action['icon'], 'attr') ?>" aria-hidden="true"></span>
                <?= esc($action['label']) ?>
            </a>
        <?php else: ?>
            <button type="button" <?= $attributes ?><?= isset($action['action']) ? ' data-action="' . esc($action['action'], 'attr') . '"' : '' ?>>
                <span class="bi <?= esc($action['icon'], 'attr') ?>" aria-hidden="true"></span>
                <?= esc($action['label']) ?>
            </button>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
