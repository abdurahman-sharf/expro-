<?php
/**
 * @var string $title
 * @var string $subtitle
 * @var string $chart_type
 * @var array  $labels_1
 * @var array  $series_data_1
 * @var array  $summary_data_1
 * @var bool   $show_currency
 * @var string $xaxis_title
 * @var string $yaxis_title
 * @var array  $config
 */
$kind     = basename($chart_type);
$values   = array_map(static fn (mixed $entry): float => (float) (is_array($entry) ? $entry['value'] : $entry), $series_data_1);
$currency = ['prefix' => '', 'suffix' => ''];

if (! empty($show_currency)) {
    $currency[is_right_side_currency_symbol() ? 'suffix' : 'prefix'] = $config['currency_symbol'];
}

$spec = [
    'kind'     => $kind,
    'labels'   => array_values($labels_1),
    'values'   => $values,
    'name'     => $yaxis_title ?? '',
    'xTitle'   => $xaxis_title ?? '',
    'yTitle'   => $yaxis_title ?? '',
    'currency' => $currency,
];
?>

<?= view('partial/header') ?>

<script src="<?= assetUrl('js/chart.min.js') ?>"></script>

<?= view('reports/_head', ['title' => $title, 'subtitle' => $subtitle, 'sensitiveToggle' => true]) ?>

<?= view('reports/_summary', ['summary_data' => $summary_data_1]) ?>

<?php if ($values === []): ?>
    <div class="alert alert-info"><?= lang('Reports.no_data_for_period') ?></div>
<?php else: ?>
    <section class="card chart-card">
        <div class="chart-box" data-kind="<?= esc($kind, 'attr') ?>">
            <canvas id="chart1" role="img" aria-label="<?= esc($title . ' — ' . explode("\n", (string) $subtitle, 2)[0], 'attr') ?>" data-chart="<?= esc(json_encode($spec, JSON_UNESCAPED_UNICODE), 'attr') ?>"></canvas>
        </div>

        <details class="chart-data">
            <summary><?= lang('Reports.chart_data') ?></summary>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead>
                        <tr>
                            <th scope="col"><?= esc($xaxis_title ?? '') ?></th>
                            <th scope="col"><?= esc($yaxis_title ?? '') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($labels_1 as $index => $label): ?>
                            <tr>
                                <td><?= esc($label) ?></td>
                                <td><bdi dir="ltr"><?= ! empty($show_currency) ? to_currency($values[$index]) : esc((string) $values[$index]) ?></bdi></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </details>
    </section>
<?php endif; ?>

<?= view('partial/footer') ?>
