<?php
/**
 * @var array                      $allowed_modules
 * @var object                     $user_info
 * @var list<array<string, mixed>> $shortcuts
 * @var string                     $first_name
 * @var bool                       $activation_banner
 * @var ?array                     $sales
 * @var ?array                     $top_items
 * @var ?array                     $low_stock
 * @var ?float                     $expenses_month
 * @var array                      $config
 */
use App\Libraries\Navigation\QuickActions;

$currency                                                        = ['prefix' => '', 'suffix' => ''];
$currency[is_right_side_currency_symbol() ? 'suffix' : 'prefix'] = $config['currency_symbol'];

$delta = static function (?float $value): string {
    if ($value === null) {
        return '';
    }

    $up      = $value >= 0;
    $percent = number_format(abs($value), 1) . '%';

    return '<div class="dash-kpi-change"><span class="dash-delta ' . ($up ? 'is-up' : 'is-down') . '" aria-label="' . esc(lang($up ? 'Common.dashboard_change_up' : 'Common.dashboard_change_down', [$percent]), 'attr') . '">'
        . '<span class="bi ' . ($up ? 'bi-arrow-up-short' : 'bi-arrow-down-short') . '" aria-hidden="true"></span>'
        . '<bdi dir="ltr" aria-hidden="true">' . ($up ? '+' : '−') . $percent . '</bdi></span>'
        . '<span class="dash-kpi-note">' . lang('Common.dashboard_vs_previous') . '</span></div>';
};

$shortcutHints = [
    QuickActions::PAGE   => lang('Common.shortcut_hint_page'),
    QuickActions::DIALOG => lang('Common.shortcut_hint_dialog'),
    QuickActions::QUOTE  => lang('Common.shortcut_hint_quote'),
];

$moduleGroups = array_values(array_filter(array_map(
    static fn (array $group): array => ['items' => array_values(array_filter($group['items'], static fn (array $item): bool => $item['id'] !== 'home'))] + $group,
    navigationGroups($allowed_modules, 'home', (int) $user_info->person_id),
), static fn (array $group): bool => $group['items'] !== []));
?>

<?= view('partial/header') ?>

<?php if ($sales !== null): ?>
    <script src="<?= assetUrl('js/chart.min.js') ?>"></script>
<?php endif; ?>

<div class="dash">
    <h2 class="dash-welcome"><?= esc(lang('Common.dashboard_welcome', [$first_name])) ?></h2>

    <?php if ($activation_banner): ?>
        <div class="dash-alert" role="status" id="activation_banner">
            <span class="bi bi-exclamation-triangle" aria-hidden="true"></span>
            <p class="dash-alert-text"><?= lang('Accounting_activation.banner_text') ?></p>
            <a class="btn btn-primary" href="<?= esc(site_url('accounting_settings/activate'), 'attr') ?>"><span class="bi bi-play-circle" aria-hidden="true"></span> <?= lang('Accounting_activation.banner_action') ?></a>
        </div>
    <?php endif; ?>

    <section class="dash-card home-shortcuts" id="home_shortcuts" aria-labelledby="home_shortcuts_title" data-person="<?= (int) $user_info->person_id ?>" data-max="<?= QuickActions::MAX_PINNED ?>" data-saved="<?= esc(lang('Common.shortcuts_saved'), 'attr') ?>">
        <div class="home-shortcuts-head">
            <h2 class="dash-card-title" id="home_shortcuts_title"><?= lang('Common.shortcuts_title') ?></h2>
            <a class="btn btn-outline-secondary" href="<?= esc(QuickActions::supportUrl(lang('Common.shortcut_support_message')), 'attr') ?>" target="_blank" rel="noopener noreferrer">
                <i class="bi bi-whatsapp" aria-hidden="true"></i> <?= lang('Common.shortcut_support') ?><span class="visually-hidden"> <?= lang('Common.shortcut_support_new_window') ?></span>
            </a>
            <?php if ($shortcuts !== []): ?>
                <button type="button" class="btn btn-outline-secondary" id="home_shortcuts_customize" aria-haspopup="dialog" data-bs-toggle="modal" data-bs-target="#home_shortcuts_modal">
                    <i class="bi bi-sliders" aria-hidden="true"></i> <?= lang('Common.shortcuts_customize') ?>
                </button>
            <?php endif; ?>
        </div>

        <?php if ($shortcuts === []): ?>
            <p class="home-shortcuts-none"><?= lang('Common.shortcuts_none') ?></p>
        <?php else: ?>
            <ul class="home-shortcuts-grid" id="home_shortcuts_grid" data-converting="<?= esc(lang('Common.shortcut_converting'), 'attr') ?>">
                <?php foreach ($shortcuts as $order => $shortcut): ?>
                    <?php $label = lang($shortcut['label']); ?>
                    <li data-shortcut-id="<?= esc($shortcut['id'], 'attr') ?>" data-order="<?= $order ?>" data-default="<?= (int) ($shortcut['default'] ?? 0) ?>"<?= isset($shortcut['default']) ? '' : ' hidden' ?>>
                        <?php if ($shortcut['kind'] === QuickActions::PAGE): ?>
                            <a class="home-shortcut" href="<?= esc(site_url($shortcut['url']), 'attr') ?>">
                        <?php elseif ($shortcut['kind'] === QuickActions::DIALOG): ?>
                            <button type="button" class="home-shortcut modal-dlg" aria-haspopup="dialog" data-href="<?= esc($shortcut['url'], 'attr') ?>" data-btn-submit="<?= lang('Common.submit') ?>" title="<?= esc($label, 'attr') ?>">
                        <?php else: ?>
                            <button type="button" class="home-shortcut" data-home-quote="<?= esc(site_url('sales/changeMode'), 'attr') ?>" data-home-register="<?= esc(site_url($shortcut['url']), 'attr') ?>">
                        <?php endif; ?>
                            <span class="home-shortcut-icon tone-<?= esc($shortcut['tone'], 'attr') ?>"><i class="bi <?= esc($shortcut['icon'], 'attr') ?>" aria-hidden="true"></i></span>
                            <span class="home-shortcut-text">
                                <span class="home-shortcut-label"><?= esc($label) ?></span>
                                <span class="home-shortcut-hint"><?= $shortcutHints[$shortcut['kind']] ?></span>
                            </span>
                            <span class="home-shortcut-kind" aria-hidden="true"><i class="bi <?= $shortcut['kind'] === QuickActions::DIALOG ? 'bi-window' : 'bi-arrow-up-left' ?>"></i></span>
                        <?= $shortcut['kind'] === QuickActions::PAGE ? '</a>' : '</button>' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
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
                        <div class="home-shortcuts-chooser-head">
                            <h3 class="home-shortcuts-chooser-title"><?= lang('Common.shortcuts_selected') ?></h3>
                            <bdi class="home-shortcuts-count" id="home_shortcuts_count" dir="ltr"></bdi>
                        </div>
                        <p class="home-shortcuts-limit" id="home_shortcuts_limit" role="status" hidden><i class="bi bi-info-circle" aria-hidden="true"></i> <?= lang('Common.shortcuts_limit_reached') ?></p>
                        <p class="home-shortcuts-empty" id="home_shortcuts_empty" hidden><?= lang('Common.shortcuts_none_selected') ?></p>
                        <ul class="list-group home-shortcuts-chooser" id="home_shortcuts_chosen" data-up="<?= esc(lang('Common.shortcuts_move_up'), 'attr') ?>" data-down="<?= esc(lang('Common.shortcuts_move_down'), 'attr') ?>"></ul>
                        <h3 class="home-shortcuts-chooser-title" id="home_shortcuts_others_title"><?= lang('Common.shortcuts_others') ?></h3>
                        <ul class="list-group home-shortcuts-chooser" id="home_shortcuts_others"></ul>
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

    <?php if ($sales !== null): ?>
        <section class="dash-kpis dash-kpis-main" aria-label="<?= lang('Common.dashboard_sales_kpis') ?>">
            <div class="dash-kpi">
                <div class="dash-kpi-head">
                    <h3 class="dash-kpi-label"><?= lang('Common.dashboard_today') ?></h3>
                    <span class="dash-kpi-note"><bdi dir="ltr"><?= $sales['today']['count'] ?></bdi> <?= lang('Common.dashboard_sales_count') ?></span>
                </div>
                <bdi dir="ltr" class="dash-kpi-value"><?= to_currency($sales['today']['total']) ?></bdi>
                <?= $delta($sales['today']['delta']) ?>
            </div>
            <div class="dash-kpi">
                <div class="dash-kpi-head"><h3 class="dash-kpi-label"><?= lang('Common.dashboard_week') ?></h3></div>
                <bdi dir="ltr" class="dash-kpi-value"><?= to_currency($sales['week']['total']) ?></bdi>
                <?= $delta($sales['week']['delta']) ?>
            </div>
            <div class="dash-kpi">
                <div class="dash-kpi-head"><h3 class="dash-kpi-label"><?= lang('Common.dashboard_month') ?></h3></div>
                <bdi dir="ltr" class="dash-kpi-value"><?= to_currency($sales['month']['total']) ?></bdi>
                <?= $delta($sales['month']['delta']) ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($sales !== null || $expenses_month !== null || $low_stock !== null): ?>
        <section class="dash-kpis dash-kpis-more" aria-label="<?= lang('Common.dashboard_more_kpis') ?>">
            <?php if ($sales !== null): ?>
                <div class="dash-kpi is-compact">
                    <h3 class="dash-kpi-label"><?= lang('Common.dashboard_average_sale') ?></h3>
                    <bdi dir="ltr" class="dash-kpi-value"><?= to_currency($sales['today']['average']) ?></bdi>
                </div>
            <?php endif; ?>
            <?php if ($expenses_month !== null): ?>
                <div class="dash-kpi is-compact">
                    <h3 class="dash-kpi-label"><?= lang('Common.dashboard_expenses_month') ?></h3>
                    <bdi dir="ltr" class="dash-kpi-value"><?= to_currency($expenses_month) ?></bdi>
                </div>
            <?php endif; ?>
            <?php if ($low_stock !== null): ?>
                <a class="dash-kpi is-compact is-link<?= $low_stock['count'] > 0 ? ' is-warning' : '' ?>" href="<?= site_url('reports/inventory_low') ?>">
                    <span class="dash-kpi-head">
                        <span class="dash-kpi-label"><?= lang('Common.dashboard_low_stock') ?></span>
                        <span class="bi <?= $low_stock['count'] > 0 ? 'bi-exclamation-triangle' : 'bi-box-seam' ?>" aria-hidden="true"></span>
                    </span>
                    <span class="dash-kpi-row">
                        <bdi dir="ltr" class="dash-kpi-value"><?= $low_stock['count'] ?></bdi>
                        <span class="dash-kpi-unit"><?= lang('Common.dashboard_items_unit') ?></span>
                        <span class="dash-kpi-more"><?= lang('Reports.show_report') ?> <i class="bi bi-chevron-left dash-flip" aria-hidden="true"></i></span>
                    </span>
                </a>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($sales !== null || $top_items !== null || $low_stock !== null): ?>
        <div class="dash-panels">
            <?php if ($sales !== null): ?>
                <section class="dash-card dash-panel dash-trend" aria-labelledby="dashboard_trend_title">
                    <div class="dash-card-head">
                        <h2 class="dash-card-title" id="dashboard_trend_title"><?= lang('Common.dashboard_trend') ?></h2>
                        <span class="dash-card-meta"><?= esc(lang('Common.dashboard_values_in', [$config['currency_symbol']])) ?></span>
                    </div>
                    <div class="chart-box dash-chart" data-kind="bar">
                        <?php $spec = ['kind' => 'bar', 'trend' => true, 'labels' => $sales['series']['labels'], 'values' => $sales['series']['values'], 'name' => lang('Common.dashboard_trend'), 'xTitle' => '', 'yTitle' => '', 'currency' => $currency]; ?>
                        <canvas id="dashboard_trend" role="img" aria-label="<?= lang('Common.dashboard_trend') ?>" data-chart="<?= esc(json_encode($spec, JSON_UNESCAPED_UNICODE), 'attr') ?>"></canvas>
                    </div>
                </section>
            <?php endif; ?>

            <?php if ($top_items !== null): ?>
                <section class="dash-card dash-panel" aria-labelledby="dashboard_top_title">
                    <div class="dash-card-head">
                        <h2 class="dash-card-title" id="dashboard_top_title"><?= lang('Common.dashboard_top_items_title') ?></h2>
                        <span class="dash-card-meta"><?= lang('Common.dashboard_last_30_days') ?></span>
                    </div>
                    <?php if ($top_items === []): ?>
                        <div class="dash-empty">
                            <span class="dash-empty-icon" aria-hidden="true"><i class="bi bi-bar-chart"></i></span>
                            <p><?= lang('Common.dashboard_top_items_empty') ?></p>
                        </div>
                    <?php else: ?>
                        <ol class="dash-list">
                            <?php foreach ($top_items as $rank => $item): ?>
                                <li>
                                    <span class="dash-rank<?= $rank === 0 ? ' is-first' : '' ?>" aria-label="<?= esc(lang('Common.dashboard_rank', [$rank + 1]), 'attr') ?>"><bdi dir="ltr"><?= $rank + 1 ?></bdi></span>
                                    <span class="dash-list-main">
                                        <span class="dash-list-name"><?= esc($item['name']) ?></span>
                                        <span class="dash-list-sub"><?= lang('Common.dashboard_quantity') ?> <bdi dir="ltr"><?= to_quantity_decimals($item['quantity']) ?></bdi></span>
                                    </span>
                                    <bdi dir="ltr" class="dash-list-value"><?= to_currency($item['total']) ?></bdi>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if ($low_stock !== null): ?>
                <section class="dash-card dash-panel" aria-labelledby="dashboard_low_title">
                    <div class="dash-card-head">
                        <h2 class="dash-card-title" id="dashboard_low_title"><?= lang('Common.dashboard_low_stock_title') ?></h2>
                        <?php if ($low_stock['rows'] !== []): ?>
                            <span class="dash-badge"><bdi dir="ltr"><?= $low_stock['count'] ?></bdi> <?= lang('Common.dashboard_items_unit') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($low_stock['rows'] === []): ?>
                        <div class="dash-empty is-ok" role="status">
                            <span class="dash-empty-icon" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
                            <p><?= lang('Common.dashboard_stock_ok') ?></p>
                        </div>
                    <?php else: ?>
                        <div class="dash-list-cols" aria-hidden="true"><span><?= lang('Common.dashboard_item') ?></span><span><?= lang('Common.dashboard_current_reorder') ?></span></div>
                        <ul class="dash-list is-compact">
                            <?php foreach ($low_stock['rows'] as $row): ?>
                                <li>
                                    <span class="dash-list-name"><?= esc($row['name']) ?></span>
                                    <span class="dash-badge"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> <bdi dir="ltr"><?= to_quantity_decimals($row['quantity']) ?> / <?= to_quantity_decimals($row['reorder_level']) ?></bdi></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="dash-card-foot">
                        <a class="btn btn-outline-secondary w-100" href="<?= site_url('reports/inventory_low') ?>"><?= lang('Reports.show_report') ?></a>
                    </div>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($moduleGroups !== []): ?>
        <section class="dash-modules" aria-labelledby="dashboard_modules_title">
            <h2 class="dash-card-title" id="dashboard_modules_title"><?= lang('Common.dashboard_modules') ?></h2>
            <div class="dash-modules-grid">
                <?php foreach ($moduleGroups as $groupIndex => $group): ?>
                    <section class="dash-card dash-module-group" aria-labelledby="home-group-<?= $groupIndex ?>">
                        <h3 class="dash-module-head" id="home-group-<?= $groupIndex ?>">
                            <i class="bi <?= esc($group['icon'], 'attr') ?>" aria-hidden="true"></i>
                            <span class="dash-module-title"><?= esc($group['label']) ?></span>
                            <bdi class="dash-module-count" dir="ltr"><?= count($group['items']) ?></bdi>
                        </h3>
                        <ul class="dash-module-list">
                            <?php foreach ($group['items'] as $item): ?>
                                <li>
                                    <a class="home-tile" href="<?= esc($item['url'], 'attr') ?>">
                                        <span class="home-tile-icon"><i class="bi <?= esc($item['icon'], 'attr') ?>" aria-hidden="true"></i></span>
                                        <span class="home-tile-text">
                                            <span class="home-tile-title"><?= esc($item['label']) ?></span>
                                            <span class="home-tile-desc"><?= esc(lang('Module.' . $item['id'] . '_desc')) ?></span>
                                        </span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
