<?php
$sections = settingsSections();

$panes = [
    'info'      => 'configs/info_config',
    'general'   => 'configs/general_config',
    'receipt'   => 'configs/receipt_config',
    'invoice'   => 'configs/invoice_config',
    'shortcuts' => 'configs/shortcuts_config',
    'stock'     => 'configs/stock_config',
    'barcode'   => 'configs/barcode_config',
    'locale'    => 'configs/locale_config',
    'tax'       => 'configs/tax_config',
    'reward'    => 'configs/reward_config',
    'table'     => 'configs/table_config',
    'system'    => 'configs/system_config',
];
?>

<?= view('partial/header') ?>

<div class="settings-layout">
    <nav class="settings-nav" aria-label="<?= lang('Config.settings_sections') ?>">
        <button type="button" id="settings_nav_toggle" class="btn btn-outline-secondary settings-nav-toggle d-lg-none" data-bs-toggle="collapse" data-bs-target="#settings_nav_list" aria-expanded="false" aria-controls="settings_nav_list">
            <span class="bi bi-list" aria-hidden="true"></span>
            <span id="settings_nav_current"><?= lang($sections[0][1][0][2]) ?></span>
            <span class="bi bi-chevron-down ms-auto" aria-hidden="true"></span>
        </button>
        <div id="settings_nav_list" class="collapse d-lg-block settings-nav-list">
            <div role="tablist" aria-orientation="vertical">
                <?php foreach ($sections as $groupIndex => [$groupLabel, $items]): ?>
                    <div class="settings-group-title" role="presentation"><?= lang($groupLabel) ?></div>
                    <?php foreach ($items as [$id, $icon, $label, $title]): ?>
                        <button type="button" id="settings_tab_<?= $id ?>" class="settings-link<?= $id === 'info' ? ' active' : '' ?>" role="tab" data-bs-toggle="tab" data-bs-target="#<?= $id ?>_tab" aria-controls="<?= $id ?>_tab" aria-selected="<?= $id === 'info' ? 'true' : 'false' ?>" title="<?= esc(lang($title), 'attr') ?>">
                            <span class="bi <?= $icon ?>" aria-hidden="true"></span>
                            <span><?= lang($label) ?></span>
                        </button>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <div class="tab-content settings-content">
        <?php foreach ($panes as $id => $pane): ?>
            <div class="tab-pane fade<?= $id === 'info' ? ' show active' : '' ?>" id="<?= $id ?>_tab" role="tabpanel" aria-labelledby="settings_tab_<?= $id ?>" tabindex="0">
                <?= view($pane) ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?= view('partial/footer') ?>
