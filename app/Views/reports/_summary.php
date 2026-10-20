<?php
/**
 * @var array $summary_data
 */
$sensitive = ['cost', 'profit'];
?>

<?php if (! empty($summary_data)): ?>
    <div id="report_summary" class="report-kpis">
        <?php foreach ($summary_data as $name => $value): ?>
            <div class="report-kpi<?= in_array($name, $sensitive, true) ? ' is-sensitive d-none' : '' ?>">
                <span class="report-kpi-label"><?= lang("Reports.{$name}") ?></span>
                <bdi dir="ltr" class="report-kpi-value"><?= $name === 'total_quantity' ? esc($value) : to_currency($value) ?></bdi>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
