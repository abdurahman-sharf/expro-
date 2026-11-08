<?php
/**
 * @var AccountLedgerBlocksReport $blocks
 * @var string|null               $scopeLabel
 * @var ReportScopeKind           $scopeKind
 * @var bool                      $journalsUrl
 * @var bool                      $showEmployee
 * @var list<object>              $branches
 * @var Closure                   $amount
 * @var Closure                   $date
 * @var Closure                   $source
 * @var Closure                   $number
 */

use App\Libraries\Accounting\AccountLedgerBlocksReport;
use App\Libraries\Accounting\ReportScopeKind;

$side = static fn (string $value, Closure $amount): string => bccomp($value, '0', 4) === 0 ? '' : $amount($value);

$journal = static function (array $row) use ($journalsUrl, $number): string {
    $text = esc($number($row['series'], $row['number']));

    return $journalsUrl
        ? '<a href="' . esc(base_url('accounting_journals/view/' . (int) $row['journal_id']), 'attr') . '">' . $text . '</a>'
        : $text;
};

$kind = static function (array $row) use ($source): string {
    $label = $source((string) $row['source_type']);

    return $row['reverses_journal_id'] === null ? $label : $label . ' (' . lang('Accounting.source_reversal') . ')';
};
?>
<h1 class="h4"><?= lang('Accounting_reports.ledger') ?>: <bdi dir="ltr"><?= esc($blocks->code) ?></bdi> <?= esc($blocks->name) ?></h1>

<p class="text-body-secondary mb-2" id="report_period">
    <?= $blocks->period->from === null
        ? lang('Accounting_reports.period_to', [$date($blocks->period->to)])
        : lang('Accounting_reports.period_between', [$date($blocks->period->from), $date($blocks->period->to)]) ?>
</p>

<?php if ($scopeLabel !== null): ?>
    <?php if ($scopeKind === ReportScopeKind::Partial): ?>
        <p class="alert alert-warning" id="report_scope" role="note"><?= esc($scopeLabel) ?></p>
    <?php else: ?>
        <p class="acc-scope-note text-secondary small" id="report_scope" role="note"><span class="bi bi-info-circle" aria-hidden="true"></span> <?= esc($scopeLabel) ?></p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($blocks->scope->isEmpty()): ?>
    <p class="alert alert-warning" id="report_no_branches"><?= lang('Accounting_reports.no_branches') ?></p>
<?php endif; ?>

<?php if ($blocks->employeeId !== null): ?>
    <p class="small text-body-secondary" id="ledger_employee_note" role="note"><?= lang('Accounting_reports.ledger_employee_note') ?></p>
<?php endif; ?>

<?php if ($blocks->blocks === []): ?>
    <p class="text-body-secondary" id="ledger_nothing_matches"><?= lang('Accounting_reports.ledger_nothing_matches') ?></p>
<?php endif; ?>

<?php foreach ($blocks->blocks as $block): ?>
    <section class="mb-4" data-account="<?= (int) $block->accountId ?>" aria-label="<?= esc($block->code . ' ' . $block->name, 'attr') ?>">
        <h2 class="h5"><?= esc($block->name) ?> <bdi dir="ltr"><?= esc($block->code) ?></bdi></h2>
        <div class="table-responsive">
            <table class="table table-sm acc-lines" data-block="<?= esc($block->code, 'attr') ?>">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_reports.ledger_date') ?></th>
                        <th scope="col"><?= lang('Accounting_reports.ledger_journal') ?></th>
                        <th scope="col"><?= lang('Accounting_reports.ledger_source') ?></th>
                        <?php if ($showEmployee): ?>
                            <th scope="col"><?= lang('Accounting_reports.ledger_employee') ?></th>
                        <?php endif; ?>
                        <th scope="col"><?= lang('Accounting_reports.ledger_description') ?></th>
                        <?php if ($branches !== []): ?>
                            <th scope="col"><?= lang('Accounting_reports.ledger_branch') ?></th>
                        <?php endif; ?>
                        <th scope="col" class="text-end"><?= lang('Accounting_reports.debit') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_reports.credit') ?></th>
                        <th scope="col" class="text-end"><?= lang('Accounting_reports.ledger_balance') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="fw-bold" data-row="opening">
                        <td colspan="<?= 3 + ($showEmployee ? 1 : 0) + 1 + ($branches !== [] ? 1 : 0) ?>"><?= lang('Accounting_reports.ledger_opening') ?></td>
                        <td></td>
                        <td></td>
                        <td class="text-end"><?= $amount($block->opening) ?></td>
                    </tr>
                    <?php foreach ($block->rows as $row): ?>
                        <tr>
                            <td><?= esc($date($row['date'])) ?></td>
                            <td><?= $journal($row) ?></td>
                            <td><?= esc($kind($row)) ?></td>
                            <?php if ($showEmployee): ?>
                                <td><?= esc($row['employee']) ?></td>
                            <?php endif; ?>
                            <td><?= esc($row['description']) ?></td>
                            <?php if ($branches !== []): ?>
                                <td><?= esc($row['branch_code']) ?></td>
                            <?php endif; ?>
                            <td class="text-end"><?= $side($row['debit'], $amount) ?></td>
                            <td class="text-end"><?= $side($row['credit'], $amount) ?></td>
                            <td class="text-end"><?= $amount($row['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="fw-bold" data-row="total">
                        <th scope="row" colspan="<?= 3 + ($showEmployee ? 1 : 0) + 1 + ($branches !== [] ? 1 : 0) ?>"><?= lang('Accounting_reports.totals') ?></th>
                        <td class="text-end" data-cell="debit"><?= $amount($block->debit) ?></td>
                        <td class="text-end" data-cell="credit"><?= $amount($block->credit) ?></td>
                        <td class="text-end" data-cell="closing"><?= $amount($block->closing) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
<?php endforeach; ?>

<?php if ($blocks->blocks !== []): ?>
    <table class="table table-sm" id="ledger_grand_total">
        <tbody>
            <tr class="fw-bold">
                <th scope="row"><?= lang('Accounting_reports.ledger_grand_total') ?></th>
                <td class="text-end" data-cell="debit"><?= $amount($blocks->debit) ?></td>
                <td class="text-end" data-cell="credit"><?= $amount($blocks->credit) ?></td>
                <td class="text-end" data-cell="net"><?= $amount($blocks->net()) ?></td>
            </tr>
        </tbody>
    </table>
    <p class="small text-body-secondary"><?= lang('Accounting_reports.ledger_total_net') ?></p>
<?php endif; ?>
