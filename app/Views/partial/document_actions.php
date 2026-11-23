<?php
/**
 * @var list<array{id: string, label: string, icon: string, class?: string, href?: string, target?: string, onclick?: string, group?: string}> $actions
 */
$more    = array_values(array_filter($actions, static fn (array $action): bool => ($action['group'] ?? '') === 'more'));
$actions = array_values(array_filter($actions, static fn (array $action): bool => ($action['group'] ?? '') !== 'more'));
$link    = static fn (array $action, string $class): string => '<a id="' . esc($action['id'], 'attr') . '" class="' . $class . '" href="' . site_url($action['href']) . '"'
    . (isset($action['target']) ? ' target="' . esc($action['target'], 'attr') . '" rel="noopener"' : '') . '>'
    . '<span class="bi ' . esc($action['icon'], 'attr') . '" aria-hidden="true"></span> ' . esc($action['label']) . '</a>';
?>

<div class="print_hide document-actions" id="control_buttons">
    <?php foreach ($actions as $index => $action): ?>
        <?php if ($index === 2 && $more !== []): ?>
            <div class="dropdown">
                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="bi bi-files" aria-hidden="true"></span>
                    <?= lang('Documents.more_documents') ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php foreach ($more as $item): ?>
                        <li><?= $link($item, 'dropdown-item') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php $attributes = 'id="' . esc($action['id'], 'attr') . '" class="btn ' . esc($action['class'] ?? 'btn-outline-secondary', 'attr') . '"'; ?>
        <?php if (isset($action['href'])): ?>
            <?= $link($action, 'btn ' . esc($action['class'] ?? 'btn-outline-secondary', 'attr')) ?>
        <?php else: ?>
            <button type="button" <?= $attributes ?><?= isset($action['onclick']) ? ' onclick="' . esc($action['onclick'], 'attr') . '"' : '' ?>>
                <span class="bi <?= esc($action['icon'], 'attr') ?>" aria-hidden="true"></span>
                <?= esc($action['label']) ?>
            </button>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
