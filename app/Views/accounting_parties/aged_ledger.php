<?php
/**
 * @var string           $group
 * @var string           $base
 * @var string           $party
 * @var Closure          $statement_url
 * @var AgedLedgerTable  $table
 * @var PartyReportQuery $query
 * @var string|null      $error
 * @var Closure          $amount
 * @var Closure          $date
 */

use App\Libraries\Accounting\AgedLedgerTable;
use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\PartyReportQuery;

$ranges = AgingBuckets::ranges($table->days);
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url($base . '/balances'), 'attr') ?>">
        <span class="bi bi-table" aria-hidden="true">&nbsp;</span><?= lang($group . '.balances_title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url($base), 'attr') ?>">
        <span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang($group . '.back') ?>
    </a>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url($base . '/aged_ledger'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_to"><?= lang($group . '.aged_to') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($query->to, 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_period"><?= lang($group . '.aged_period') ?></label>
            <input type="number" id="f_period" name="period" class="form-control form-control-sm" min="1" max="<?= AgingBuckets::MAX_PERIOD ?>" step="1" value="<?= (int) $table->days ?>">
        </div>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($base . '/aged_ledger'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
</form>

<div class="container-fluid py-3" id="aged_ledger">
    <h1 class="h4"><?= lang($group . '.aged_title') ?></h1>

    <p class="text-body-secondary mb-2" id="report_period"><?= esc(lang($group . '.aged_as_of', [$date($table->asOf)])) ?></p>
    <p class="small text-body-secondary mb-3"><?= lang($group . '.aged_note') ?></p>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm" id="aged_ledger_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang($group . '.' . $party) ?></th>
                    <?php foreach ($ranges as $index => $range): ?>
                        <th scope="col"><?= $index === 0 ? lang($group . '.age_today') : esc(lang($group . '.bucket_days', [$range])) ?></th>
                    <?php endforeach; ?>
                    <th scope="col"><?= lang($group . '.aged_total') ?></th>
                    <th scope="col"><?= lang($group . '.aged_credit') ?></th>
                    <th scope="col"><?= lang($group . '.balance') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($table->rows as $row): ?>
                    <tr>
                        <td><a href="<?= esc($statement_url($row->partyId), 'attr') ?>"><?= esc($row->name) ?></a></td>
                        <?php foreach ($row->buckets as $value): ?>
                            <td><?= $amount($value) ?></td>
                        <?php endforeach; ?>
                        <td><?= $amount($row->total()) ?></td>
                        <td><?= $amount($row->credit) ?></td>
                        <td><?= $amount($row->balance()) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($table->rows === []): ?>
                    <tr><td colspan="10"><?= lang($group . '.aged_empty') ?></td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($table->rows !== []): ?>
                <tfoot>
                    <tr class="fw-bold" id="aged_totals">
                        <th scope="row"><?= lang($group . '.totals') ?></th>
                        <?php foreach (array_keys($ranges) as $index): ?>
                            <td><?= $amount($table->bucket($index)) ?></td>
                        <?php endforeach; ?>
                        <td><?= $amount($table->total()) ?></td>
                        <td><?= $amount($table->credit()) ?></td>
                        <td><?= $amount($table->balance()) ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?= view('partial/footer') ?>
