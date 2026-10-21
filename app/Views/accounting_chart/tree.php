<?php
/**
 * @var array<int|string, list<object>> $nodes
 * @var array<int, true> $open
 * @var int|null $selected_id
 */

$renderNodes = static function (array $accounts) use (&$renderNodes, $nodes, $open, $selected_id): void {
    foreach ($accounts as $account) {
        $id       = (int) $account->account_id;
        $children = $nodes[$id] ?? [];
        $current  = $selected_id === $id;
        $classes  = 'acc-tree-link' . ($current ? ' active' : '') . ((int) $account->active === 0 ? ' is-inactive' : '');
        $link     = '<a class="' . $classes . '" href="' . esc(base_url("accounting_chart?parent={$id}"), 'attr') . '"' . ($current ? ' aria-current="page"' : '') . '>'
            . '<bdi class="acc-code" dir="ltr">' . esc($account->code) . '</bdi> ' . esc($account->name) . '</a>';

        echo '<li>';

        if ($children === []) {
            echo $link;
        } else {
            echo '<details' . (isset($open[$id]) ? ' open' : '') . '><summary>' . $link . '</summary><ul class="acc-tree">';
            $renderNodes($children);
            echo '</ul></details>';
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
