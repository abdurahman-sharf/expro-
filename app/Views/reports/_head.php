<?php
/**
 * @var string $title
 * @var string $subtitle
 * @var bool   $sensitiveToggle
 */
?>

<header class="report-head">
    <div>
        <h2 class="report-title"><?= esc($title) ?></h2>
        <?php if (($subtitle ?? '') !== ''): ?>
            <p class="report-subtitle">
                <span class="bi bi-calendar3" aria-hidden="true"></span>
                <bdi dir="ltr"><?= esc($subtitle) ?></bdi>
            </p>
        <?php endif; ?>
    </div>
    <div class="report-actions print_hide">
        <?php if (! empty($sensitiveToggle)): ?>
            <button type="button" id="toggleCostProfitButton" class="btn btn-outline-secondary btn-sm" aria-pressed="false">
                <span class="bi bi-eye" aria-hidden="true"></span>
                <?= lang('Reports.toggle_cost_and_profit') ?>
            </button>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= site_url('reports') ?>">
            <span class="bi bi-bar-chart" aria-hidden="true"></span>
            <?= lang('Module.reports') ?>
        </a>
    </div>
</header>
