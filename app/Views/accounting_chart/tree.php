<?php
/**
 * @var array<int|string, list<object>> $nodes
 * @var array<int, true>                $open
 * @var int|null                        $selected_id
 */
$renderNodes = static function (array $accounts) use (&$renderNodes, $nodes, $open, $selected_id): void {
    foreach ($accounts as $account) {
        $id       = (int) $account->account_id;
        $children = $nodes[$id] ?? [];
        $current  = $selected_id === $id;
        $classes  = 'acc-tree-link' . ($current ? ' active' : '') . ((int) $account->active === 0 ? ' is-inactive' : '');
        $link     = '<a class="' . $classes . '" href="' . esc(base_url("accounting_chart?parent={$id}"), 'attr') . '"' . ($current ? ' aria-current="page"' : '') . '>'
            . '<bdi class="acc-code" dir="ltr">' . esc($account->code) . '</bdi> ' . esc($account->name) . '</a>';

        $isOpen = isset($open[$id]);

        echo '<li><div class="acc-tree-row">';

        if ($children === []) {
            echo '<span class="acc-tree-caret-space" aria-hidden="true"></span>';
        } else {
            echo '<button type="button" class="acc-tree-caret" aria-expanded="' . ($isOpen ? 'true' : 'false') . '" aria-label="' . esc(lang('Accounting_chart.toggle_branch'), 'attr') . '"><span class="bi bi-chevron-down" aria-hidden="true"></span></button>';
        }

        echo $link . '</div>';

        if ($children !== []) {
            echo '<ul class="acc-tree"' . ($isOpen ? '' : ' hidden') . '>';
            $renderNodes($children);
            echo '</ul>';
        }

        echo '</li>';
    }
};
?>
<nav aria-label="<?= lang('Accounting_chart.tree') ?>">
    <ul class="acc-tree">
        <?php $renderNodes($nodes[0] ?? []); ?>
    </ul>
</nav>
