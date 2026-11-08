<?php
/**
 * @var string           $group
 * @var string           $base
 * @var string           $party
 * @var Closure          $statement_url
 * @var string           $csv_url
 * @var BalanceTable     $table
 * @var PartyReportQuery $query
 * @var string|null      $error
 * @var Closure          $amount
 * @var Closure          $date
 */

use App\Libraries\Accounting\BalanceTable;
use App\Libraries\Accounting\PartyReportQuery;

$showOther = $table->hasOther();
$columns   = $showOther ? 9 : 8;
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" id="balances_csv" href="<?= esc($csv_url, 'attr') ?>">
        <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?>
    </a>
    <?= view('partial/report_files', ['csvUrl' => $csv_url, 'id' => 'balances_csv']) ?>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url($base . '/aged_ledger'), 'attr') ?>">
        <span class="bi bi-hourglass-split" aria-hidden="true">&nbsp;</span><?= lang($group . '.aged_title') ?>
    </a>
    <a class="btn btn-outline-secondary btn-sm float-end" href="<?= esc(base_url($base), 'attr') ?>">
        <span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang($group . '.back') ?>
    </a>
</div>

<form id="report_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url($base . '/balances'), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="f_from"><?= lang($group . '.balances_from') ?></label>
            <input type="date" id="f_from" name="from" class="form-control form-control-sm" value="<?= esc($query->from, 'attr') ?>">
        </div>
        <div class="acc-filter">
            <label class="form-label" for="f_to"><?= lang($group . '.balances_to') ?></label>
            <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($query->to, 'attr') ?>">
        </div>
        <fieldset class="acc-filter acc-filter-status">
            <legend class="form-label"><?= lang('Accounting_reports.show') ?></legend>
            <label class="choice"><input type="checkbox" class="form-check-input" id="f_empty" name="empty" value="1"<?= $query->showEmpty ? ' checked' : '' ?>> <?= lang($group . '.balances_show_empty') ?></label>
        </fieldset>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($base . '/balances'), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
        </div>
    </div>
</form>

<div class="container-fluid py-3" id="party_balances">
    <h1 class="h4"><?= lang($group . '.balances_title') ?></h1>

    <p class="text-body-secondary mb-2" id="report_period">
        <?= $table->from === ''
            ? esc(lang($group . '.balances_period_to', [$date($table->to)]))
            : esc(lang($group . '.balances_period', [$date($table->from), $date($table->to)])) ?>
    </p>
    <p class="small text-body-secondary mb-3"><?= lang($group . '.balances_note') ?></p>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="report_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <div class="table-responsive">
        <table class="table table-sm acc-lines" id="balances_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang($group . '.' . $party) ?></th>
                    <th scope="col"><?= lang($group . '.col_before') ?></th>
                    <th scope="col"><?= lang($group . '.col_sales') ?></th>
                    <th scope="col"><?= lang($group . '.col_returns') ?></th>
                    <th scope="col"><?= lang($group . '.col_net') ?></th>
                    <th scope="col"><?= lang($group . '.col_payments') ?></th>
                    <th scope="col"><?= lang($group . '.col_settlements') ?></th>
                    <?php if ($showOther): ?>
                        <th scope="col"><?= lang($group . '.col_other') ?></th>
                    <?php endif; ?>
                    <th scope="col"><?= lang($group . '.balance') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($table->rows as $row): ?>
                    <tr>
                        <td><a href="<?= esc($statement_url($row->partyId), 'attr') ?>"><?= esc($row->name) ?></a></td>
                        <td><?= $amount($row->before) ?></td>
                        <td><?= $amount($row->sales) ?></td>
                        <td><?= $amount($row->returns) ?></td>
                        <td><?= $amount($row->net()) ?></td>
                        <td><?= $amount($row->payments) ?></td>
                        <td><?= $amount($row->settlements) ?></td>
                        <?php if ($showOther): ?>
                            <td><?= $amount($row->other) ?></td>
                        <?php endif; ?>
                        <td><?= $amount($row->balance) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($table->rows === []): ?>
                    <tr><td colspan="<?= $columns ?>"><?= lang($group . '.balances_empty') ?></td></tr>
                <?php endif; ?>
            </tbody>
            <?php if ($table->rows !== []): ?>
                <tfoot>
                    <tr class="fw-bold" id="balances_totals">
                        <th scope="row"><?= lang($group . '.totals') ?></th>
                        <td><?= $amount($table->total('before')) ?></td>
                        <td><?= $amount($table->total('sales')) ?></td>
                        <td><?= $amount($table->total('returns')) ?></td>
                        <td><?= $amount($table->total('net_sales')) ?></td>
                        <td><?= $amount($table->total('payments')) ?></td>
                        <td><?= $amount($table->total('settlements')) ?></td>
                        <?php if ($showOther): ?>
                            <td><?= $amount($table->total('other')) ?></td>
                        <?php endif; ?>
                        <td><?= $amount($table->total('balance')) ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?= view('partial/footer') ?>
