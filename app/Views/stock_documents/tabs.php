<?php
/**
 * @var string $active     permits, stocktakes or requests
 * @var bool   $requestsOn Whether the requests tab is offered
 */
$tabs = [
    'permits'    => ['stock_documents/permits', 'Stock_documents.tab_permits'],
    'stocktakes' => ['stock_documents/stocktakes', 'Stock_documents.tab_stocktakes'],
];

if ($requestsOn) {
    $tabs['requests'] = ['stock_documents/requests', 'Stock_documents.tab_requests'];
}
?>
<nav aria-label="<?= esc(lang('Stock_documents.title'), 'attr') ?>">
    <ul class="nav nav-tabs mb-3" id="stock_tabs">
        <?php foreach ($tabs as $key => [$url, $label]): ?>
            <li class="nav-item">
                <a class="nav-link<?= $active === $key ? ' active' : '' ?>" id="stock_tab_<?= esc($key, 'attr') ?>"<?= $active === $key ? ' aria-current="page"' : '' ?> href="<?= esc(base_url($url), 'attr') ?>"><?= lang($label) ?></a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
