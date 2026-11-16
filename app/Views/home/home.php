<?php
/**
 * @var array  $allowed_modules
 * @var object $user_info
 * @var list<array<string, mixed>> $shortcuts
 * @var string $first_name
 * @var ?array $sales
 * @var ?array $top_items
 * @var ?array $low_stock
 * @var ?float $expenses_month
 * @var array  $config
 */
use App\Libraries\Navigation\QuickActions;

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

<section class="home-shortcuts" id="home_shortcuts" aria-labelledby="home_shortcuts_title" data-person="<?= (int) $user_info->person_id ?>" data-max="<?= QuickActions::MAX_PINNED ?>">
    <div class="home-shortcuts-head">
        <h3 class="home-shortcuts-title" id="home_shortcuts_title"><?= lang('Common.shortcuts_title') ?></h3>
        <div class="home-shortcuts-actions">
            <a class="btn btn-sm home-shortcuts-support" href="<?= esc(QuickActions::supportUrl(lang('Common.shortcut_support_message')), 'attr') ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-whatsapp" aria-hidden="true"></i> <?= lang('Common.shortcut_support') ?>
            </a>
            <?php if ($shortcuts !== []): ?>
                <button type="button" class="btn btn-sm home-shortcuts-customize" id="home_shortcuts_customize" data-bs-toggle="modal" data-bs-target="#home_shortcuts_modal">
                    <i class="bi bi-sliders" aria-hidden="true"></i> <?= lang('Common.shortcuts_customize') ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <ul class="home-shortcuts-grid" id="home_shortcuts_grid">
        <?php foreach ($shortcuts as $order => $shortcut): ?>
            <?php $label = lang($shortcut['label']); ?>
            <li data-shortcut-id="<?= esc($shortcut['id'], 'attr') ?>" data-order="<?= $order ?>" data-default="<?= (int) ($shortcut['default'] ?? 0) ?>"<?= isset($shortcut['default']) ? '' : ' hidden' ?>>
                <?php if ($shortcut['kind'] === QuickActions::PAGE): ?>
                    <a class="home-shortcut" href="<?= esc(site_url($shortcut['url']), 'attr') ?>">
                <?php elseif ($shortcut['kind'] === QuickActions::DIALOG): ?>
                    <button type="button" class="home-shortcut modal-dlg" data-href="<?= esc($shortcut['url'], 'attr') ?>" data-btn-submit="<?= lang('Common.submit') ?>" title="<?= esc($label, 'attr') ?>">
                <?php else: ?>
                    <button type="button" class="home-shortcut" data-home-quote="<?= esc(site_url('sales/changeMode'), 'attr') ?>" data-home-register="<?= esc(site_url($shortcut['url']), 'attr') ?>">
                <?php endif; ?>
                    <span class="home-shortcut-icon tone-<?= esc($shortcut['tone'], 'attr') ?>"><i class="bi <?= esc($shortcut['icon'], 'attr') ?>" aria-hidden="true"></i></span>
                    <span class="home-shortcut-label"><?= esc($label) ?></span>
                <?= $shortcut['kind'] === QuickActions::PAGE ? '</a>' : '</button>' ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php if ($shortcuts !== []): ?>
    <div class="modal fade" id="home_shortcuts_modal" tabindex="-1" aria-labelledby="home_shortcuts_modal_title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 class="modal-title fs-5" id="home_shortcuts_modal_title"><?= lang('Common.shortcuts_customize_title') ?></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= lang('Common.close') ?>"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary small"><?= lang('Common.shortcuts_customize_hint', [QuickActions::MAX_PINNED]) ?></p>
                    <ul class="list-group home-shortcuts-chooser" id="home_shortcuts_chooser" data-up="<?= esc(lang('Common.shortcuts_move_up'), 'attr') ?>" data-down="<?= esc(lang('Common.shortcuts_move_down'), 'attr') ?>"></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary me-auto" id="home_shortcuts_reset"><?= lang('Common.shortcuts_reset') ?></button>
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= lang('Common.close') ?></button>
                    <button type="button" class="btn btn-primary" id="home_shortcuts_save"><?= lang('Common.shortcuts_save') ?></button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

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
