<?php

use App\Libraries\Accounting\ChequeList;

/**
 * @var string                    $direction
 * @var array<string, mixed>      $filters
 * @var array<string,string>      $statuses
 * @var array<string, mixed>      $report
 * @var callable(object): string  $number
 * @var callable(string): string  $amount
 * @var callable(?string): string $date
 * @var callable(string): string  $status
 * @var string                    $csvUrl
 */
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h5 class="me-auto mb-0"><?= lang('Accounting_cheques.report_' . $direction) ?></h5>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_cheques/report/' . ($direction === 'received' ? 'paid' : 'received')), 'attr') ?>"><?= lang('Accounting_cheques.report_' . ($direction === 'received' ? 'paid' : 'received')) ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_cheques?direction=' . $direction), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_cheques.back') ?></a>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()"><span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?></button>
    <a class="btn btn-outline-secondary btn-sm" id="cheque_report_csv" href="<?= esc($csvUrl, 'attr') ?>"><span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?></a>
    <?= view('partial/report_files', ['csvUrl' => $csvUrl, 'id' => 'cheque_report_csv']) ?>
</div>

<form method="get" class="acc-filters print_hide px-2" role="search" aria-label="<?= esc(lang('Accounting_cheques.filters'), 'attr') ?>">
    <div class="acc-filter-row">
        <div class="acc-filter">
            <label class="form-label" for="status"><?= lang('Accounting_cheques.status') ?></label>
            <select name="status" id="status" class="form-select form-select-sm">
                <option value=""><?= lang('Accounting_cheques.all_statuses') ?></option>
                <?php foreach ($statuses as $value => $label): ?>
                    <option value="<?= esc($value, 'attr') ?>"<?= ($filters['status'] ?? '') === $value ? ' selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach (['date_from' => 'date_from', 'date_to' => 'date_to', 'due_from' => 'due_from', 'due_to' => 'due_to'] as $name => $label): ?>
            <div class="acc-filter">
                <label class="form-label" for="<?= $name ?>"><?= lang('Accounting_cheques.' . $label) ?></label>
                <input type="date" name="<?= $name ?>" id="<?= $name ?>" class="form-control form-control-sm" value="<?= esc((string) ($filters[$name] ?? ''), 'attr') ?>">
            </div>
        <?php endforeach; ?>
        <div class="acc-filter acc-filter-actions">
            <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Common.search') ?></button>
        </div>
    </div>
</form>

<?php if ($report['truncated']): ?>
    <p class="alert alert-warning mx-2" role="alert"><?= lang('Accounting_cheques.report_truncated', [ChequeList::REPORT_LIMIT]) ?></p>
<?php endif; ?>

<div class="table-responsive px-2">
    <table class="table table-sm acc-lines" id="cheque_report">
        <thead><tr>
            <th><?= lang('Accounting_cheques.number') ?></th>
            <th><?= lang('Accounting_cheques.cheque_number') ?></th>
            <th><?= lang('Accounting_cheques.drawer_name') ?></th>
            <th><?= lang($direction === 'received' ? 'Accounting_cheques.customer' : 'Accounting_cheques.supplier') ?></th>
            <th><?= lang('Accounting_cheques.bank') ?></th>
            <th><?= lang('Accounting_cheques.issue_date') ?></th>
            <th><?= lang('Accounting_cheques.due_date') ?></th>
            <th><?= lang('Accounting_cheques.status') ?></th>
            <th><?= lang('Accounting_cheques.amount') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($report['rows'] as $cheque): ?>
            <tr>
                <td><?= anchor('accounting_cheques/view/' . (int) $cheque->cheque_id, '<bdi dir="ltr">' . esc($number($cheque)) . '</bdi>') ?></td>
                <td><bdi dir="ltr"><?= esc($cheque->cheque_number) ?></bdi></td>
                <td><?= esc($cheque->drawer_name) ?></td>
                <td><?= esc($cheque->party_name) ?></td>
                <td><?= esc($cheque->treasury_name) ?></td>
                <td><?= esc($date($cheque->issue_date)) ?></td>
                <td><?= esc($date($cheque->due_date)) ?></td>
                <td><?= esc($status($cheque->status)) ?></td>
                <td><?= $amount((string) $cheque->amount) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if ($report['rows'] === []): ?>
            <tr><td colspan="9" class="text-center text-body-secondary"><?= lang('Accounting_cheques.empty') ?></td></tr>
        <?php endif; ?>
        </tbody>
        <tfoot>
            <?php foreach ($report['totals'] as $key => $total): ?>
                <tr><th colspan="8"><?= esc($status($key)) ?></th><th id="total_<?= esc($key, 'attr') ?>"><?= $amount($total) ?></th></tr>
            <?php endforeach; ?>
            <tr class="fw-bold"><th colspan="8"><?= lang('Accounting_cheques.total') ?></th><th id="total_all"><?= $amount($report['total']) ?></th></tr>
        </tfoot>
    </table>
</div>

<h6 class="px-2 mt-3"><?= lang('Accounting_cheques.ties_title') ?></h6>
<p class="small text-body-secondary px-2"><?= lang('Accounting_cheques.ties_note') ?></p>
<div class="table-responsive px-2">
    <table class="table table-sm acc-lines" id="cheque_ties">
        <thead><tr>
            <th><?= lang('Accounting_cheques.tie_account') ?></th>
            <th><?= lang('Accounting_cheques.tie_ledger') ?></th>
            <th><?= lang('Accounting_cheques.tie_cheques') ?></th>
            <th><?= lang('Accounting_cheques.tie_result') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($report['ties'] as $tie): ?>
            <tr>
                <td><?= $tie['account'] === null ? esc(lang('Accounting_cheques.tie_no_account')) : esc($tie['account']->code . ' - ' . $tie['account']->name) ?></td>
                <td><?= $amount($tie['ledger']) ?></td>
                <td><?= $amount($tie['cheques']) ?></td>
                <td><span class="badge <?= $tie['agrees'] ? 'text-bg-success' : 'text-bg-danger' ?>"><?= lang($tie['agrees'] ? 'Accounting_cheques.tie_agrees' : 'Accounting_cheques.tie_differs') ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?= view('partial/footer') ?>
