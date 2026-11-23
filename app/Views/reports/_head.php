<?php

use App\Libraries\Accounting\ReportScopeKind;

/**
 * @var string $title
 * @var string $subtitle
 * @var bool   $sensitiveToggle
 */
$scope                 = reportScope();
$scopeLabel            = $scope->label();
[$dates, $foreignNote] = array_pad(explode("\n", (string) ($subtitle ?? ''), 2), 2, '');
?>

<header class="page-head report-head">
    <div class="page-head-start">
        <p class="page-crumbs"><a href="<?= site_url('reports') ?>"><?= lang('Module.reports') ?></a></p>
        <h1 class="page-title report-title"><?= esc($title) ?></h1>
        <?php if ($dates !== ''): ?>
            <p class="report-subtitle">
                <span class="bi bi-calendar3" aria-hidden="true"></span>
                <bdi dir="ltr"><?= esc($dates) ?></bdi>
            </p>
        <?php endif; ?>
    </div>
    <div class="page-head-actions report-actions print_hide">
        <?php if (! empty($sensitiveToggle)): ?>
            <button type="button" id="toggleCostProfitButton" class="btn btn-outline-secondary" aria-pressed="false">
                <span class="bi bi-eye" aria-hidden="true"></span>
                <?= lang('Reports.toggle_cost_and_profit') ?>
            </button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <span class="bi bi-printer" aria-hidden="true"></span>
            <?= lang('Common.print') ?>
        </button>
    </div>
</header>

<?php if ($foreignNote !== ''): ?>
    <p class="alert alert-warning" id="report_foreign_currency" role="note"><span class="bi bi-exclamation-triangle" aria-hidden="true"></span> <?= esc($foreignNote) ?></p>
<?php endif; ?>

<?php if ($scopeLabel !== null): ?>
    <?php if ($scope->kind === ReportScopeKind::Partial): ?>
        <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scopeLabel) ?></p>
    <?php else: ?>
        <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scopeLabel) ?></p>
    <?php endif; ?>
<?php endif; ?>
