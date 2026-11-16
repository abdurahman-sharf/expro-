<?php
/**
 * @var list<object>         $rows
 * @var array<string, mixed> $ctx
 * @var bool                 $limited
 */
?>
<?php if ($rows === []): ?>
    <p class="acc-empty text-body-secondary"><?= lang('Accounting_chart.no_results') ?></p>
<?php else: ?>
    <ul class="acc-tree acc-results">
        <?php foreach ($rows as $row): ?>
            <?= view('accounting_chart/node', ['account' => $row, 'ctx' => $ctx, 'showParent' => true]) ?>
        <?php endforeach; ?>
    </ul>
    <?php if ($limited): ?>
        <p class="acc-empty text-body-secondary small"><?= lang('Accounting_chart.results_limited') ?></p>
    <?php endif; ?>
<?php endif; ?>
