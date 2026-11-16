<?php
/**
 * @var array  $allowed_modules
 * @var object $user_info
 * @var string $first_name
 * @var ?array $sales
 * @var ?array $top_items
 * @var ?array $low_stock
 * @var ?float $expenses_month
 * @var array  $config
 */
$currency                                                        = ['prefix' => '', 'suffix' => ''];
$currency[is_right_side_currency_symbol() ? 'suffix' : 'prefix'] = $config['currency_symbol'];

$delta = static function (?float $value): string {
    if ($value === null) {
        return '';
    }

    $up = $value >= 0;

    return '<span class="kpi-delta ' . ($up ? 'is-up' : 'is-down') . '"><span class="bi ' . ($up ? 'bi-arrow-up-short' : 'bi-arrow-down-short') . '" aria-hidden="true"></span>'
        . '<bdi dir="ltr">' . number_format(abs($value), 1) . '%</bdi> ' . lang('Common.dashboard_vs_previous') . '</span>';
};
?>

<?= view('partial/header') ?>

<?php if ($sales !== null): ?>
    <script src="<?= assetUrl('js/chart.min.js') ?>"></script>
<?php endif; ?>

<h2 class="dash-welcome"><?= lang('Common.welcome') ?> <?= esc($first_name) ?></h2>

<?php if ($sales !== null || $low_stock !== null || $expenses_month !== null): ?>
    <section class="report-kpis dash-kpis" aria-label="<?= lang('Common.dashboard') ?>">
        <?php if ($sales !== null): ?>
            <div class="report-kpi">
                <span class="report-kpi-label"><?= lang('Common.dashboard_today') ?></span>
                <bdi dir="ltr" class="report-kpi-value"><?= to_currency($sales['today']['total']) ?></bdi>
                <span class="kpi-note"><bdi dir="ltr"><?= $sales['today']['count'] ?></bdi> <?= lang('Common.dashboard_sales_count') ?></span>
                <?= $delta($sales['today']['delta']) ?>
            </div>
            <div class="report-kpi">
                <span class="report-kpi-label"><?= lang('Common.dashboard_week') ?></span>
                <bdi dir="ltr" class="report-kpi-value"><?= to_currency($sales['week']['total']) ?></bdi>
                <?= $delta($sales['week']['delta']) ?>
            </div>
            <div class="report-kpi">
                <span class="report-kpi-label"><?= lang('Common.dashboard_month') ?></span>
                <bdi dir="ltr" class="report-kpi-value"><?= to_currency($sales['month']['total']) ?></bdi>
                <?= $delta($sales['month']['delta']) ?>
            </div>
            <div class="report-kpi">
                <span class="report-kpi-label"><?= lang('Common.dashboard_average_sale') ?></span>
                <bdi dir="ltr" class="report-kpi-value"><?= to_currency($sales['today']['average']) ?></bdi>
            </div>
        <?php endif; ?>
        <?php if ($expenses_month !== null): ?>
            <div class="report-kpi">
                <span class="report-kpi-label"><?= lang('Common.dashboard_expenses_month') ?></span>
                <bdi dir="ltr" class="report-kpi-value"><?= to_currency($expenses_month) ?></bdi>
            </div>
        <?php endif; ?>
        <?php if ($low_stock !== null): ?>
            <a class="report-kpi kpi-link" href="<?= site_url('reports/inventory_low') ?>">
                <span class="report-kpi-label"><?= lang('Common.dashboard_low_stock') ?></span>
                <bdi dir="ltr" class="report-kpi-value<?= $low_stock['count'] > 0 ? ' text-danger' : '' ?>"><?= $low_stock['count'] ?></bdi>
            </a>
        <?php endif; ?>
    </section>

    <div class="dash-panels">
        <?php if ($sales !== null): ?>
            <section class="card chart-card dash-trend">
                <h3 class="dash-card-title"><?= lang('Common.dashboard_trend') ?></h3>
                <div class="chart-box" data-kind="bar">
                    <?php $spec = ['kind' => 'bar', 'labels' => $sales['series']['labels'], 'values' => $sales['series']['values'], 'name' => lang('Common.dashboard_trend'), 'xTitle' => '', 'yTitle' => '', 'currency' => $currency]; ?>
                    <canvas id="dashboard_trend" role="img" aria-label="<?= lang('Common.dashboard_trend') ?>" data-chart="<?= esc(json_encode($spec, JSON_UNESCAPED_UNICODE), 'attr') ?>"></canvas>
                </div>
            </section>
        <?php endif; ?>

        <div class="dash-side">
            <?php if ($top_items !== null): ?>
                <section class="card dash-card">
                    <h3 class="dash-card-title"><?= lang('Common.dashboard_top_items') ?></h3>
                    <?php if ($top_items === []): ?>
                        <p class="text-muted mb-0">&mdash;</p>
                    <?php else: ?>
                        <ol class="dash-list">
                            <?php foreach ($top_items as $item): ?>
                                <li>
                                    <span class="dash-list-name"><?= esc($item['name']) ?></span>
                                    <bdi dir="ltr" class="dash-list-value"><?= to_quantity_decimals($item['quantity']) ?> &middot; <?= to_currency($item['total']) ?></bdi>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($low_stock !== null): ?>
                <section class="card dash-card">
                    <h3 class="dash-card-title"><?= lang('Common.dashboard_low_stock') ?></h3>
                    <?php if ($low_stock['rows'] === []): ?>
                        <p class="text-success mb-0"><span class="bi bi-check-circle" aria-hidden="true"></span> <?= lang('Common.dashboard_stock_ok') ?></p>
                    <?php else: ?>
                        <ul class="dash-list">
                            <?php foreach ($low_stock['rows'] as $row): ?>
                                <li>
                                    <span class="dash-list-name"><?= esc($row['name']) ?></span>
                                    <bdi dir="ltr" class="dash-list-value text-danger"><?= to_quantity_decimals($row['quantity']) ?> / <?= to_quantity_decimals($row['reorder_level']) ?></bdi>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <a class="btn btn-outline-secondary btn-sm mt-2" href="<?= site_url('reports/inventory_low') ?>"><?= lang('Reports.show_report') ?></a>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php foreach (navigationGroups($allowed_modules, 'home', (int) $user_info->person_id) as $groupIndex => $group): ?>
    <?php $items = array_filter($group['items'], static fn (array $item): bool => $item['id'] !== 'home'); ?>
    <?php if ($items !== []): ?>
        <section class="mb-4" aria-labelledby="home-group-<?= $groupIndex ?>">
            <h3 class="h6 text-secondary mb-3" id="home-group-<?= $groupIndex ?>"><?= esc($group['label']) ?></h3>
            <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-xl-3 row-cols-xxl-4">
                <?php foreach ($items as $item): ?>
                    <div class="col">
                        <a class="home-tile" href="<?= $item['url'] ?>">
                            <span class="home-tile-icon"><i class="bi <?= $item['icon'] ?>" aria-hidden="true"></i></span>
                            <span class="home-tile-text">
                                <span class="home-tile-title"><?= esc($item['label']) ?></span>
                                <span class="home-tile-desc"><?= esc(lang('Module.' . $item['id'] . '_desc')) ?></span>
                            </span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endforeach; ?>

<?= view('partial/footer') ?>
