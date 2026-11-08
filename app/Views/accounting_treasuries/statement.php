<?php
/**
 * @var object                     $treasury
 * @var string                     $balance
 * @var string|null                $foreign_balance
 * @var list<array<string, mixed>> $rows
 * @var int                        $total_rows
 * @var list<array<string, mixed>> $crossings
 * @var string                     $lowest
 * @var bool                       $journal_links
 * @var Closure                    $amount
 * @var array<int, int>            $matched
 * @var Closure                    $date
 * @var int                        $page
 * @var int                        $pages
 * @var string|null                $before
 * @var array<string, string>      $filters
 * @var string                     $order
 * @var string|null                $error
 * @var string                     $csv_url
 * @var Closure                    $page_url
 */
$negative = bccomp($balance, '0', 4) < 0;

$source = static function (array $row): string {
    $label = lang('Accounting.source_' . $row['source_type']);

    if (str_starts_with($label, 'Accounting.')) {
        $label = (string) $row['source_type'];
    }

    return $row['reverses_journal_id'] === null ? $label : $label . ' (' . lang('Accounting.source_reversal') . ')';
};

$journal = static function (array $row) use ($journal_links, $matched): string {
    $number = esc((string) $row['number']);

    $label = $journal_links
        ? '<a href="' . esc(base_url('accounting_journals/view/' . (int) $row['journal_id']), 'attr') . '">' . $number . '</a>'
        : $number;

    return isset($matched[(int) $row['line_id']])
        ? $label . ' <span class="badge text-bg-success bank-matched" title="' . esc(lang('Accounting_treasuries.bank_matched_hint'), 'attr') . '">' . esc(lang('Accounting_treasuries.bank_matched')) . '</span>'
        : $label;
};
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries'), 'attr') ?>">
        <span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.back') ?>
    </a>
    <button type="button" class="btn btn-outline-secondary btn-sm float-end" onclick="window.print()">
        <span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Common.print') ?>
    </button>
    <a class="btn btn-outline-secondary btn-sm float-end" id="statement_csv" href="<?= esc($csv_url, 'attr') ?>">
        <span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_treasuries.statement_csv') ?>
    </a>
    <?= view('partial/report_files', ['csvUrl' => $csv_url, 'id' => 'statement_csv']) ?>
</div>

<div class="container-fluid py-3" id="treasury_statement">
    <h1 class="h4"><?= esc($treasury->name) ?></h1>

    <dl class="row mb-4">
        <dt class="col-sm-3"><?= lang('Accounting_treasuries.branch') ?></dt>
        <dd class="col-sm-9"><?= esc($treasury->branch_code) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_treasuries.balance') ?></dt>
        <dd class="col-sm-9">
            <?= $amount($balance) ?>
            <?php if ($negative): ?>
                <span class="badge text-bg-danger" id="treasury_negative"><?= lang('Accounting_treasuries.negative') ?></span>
            <?php endif; ?>
            <?php if (($foreign_balance ?? null) !== null): ?>
                <div class="small text-body-secondary" id="treasury_foreign_balance"><?= $foreign_balance ?></div>
            <?php endif; ?>
        </dd>
        <?php if ($lowest !== '0.0000' && bccomp($lowest, '0', 4) < 0): ?>
            <dt class="col-sm-3"><?= lang('Accounting_treasuries.lowest') ?></dt>
            <dd class="col-sm-9"><?= $amount($lowest) ?></dd>
        <?php endif; ?>
    </dl>

    <?php if ($negative): ?>
        <p class="alert alert-danger" id="treasury_negative_note"><?= lang('Accounting_treasuries.negative_note') ?></p>
    <?php endif; ?>

    <h2 class="h5"><?= lang('Accounting_treasuries.crossings') ?></h2>
    <?php if ($crossings === []): ?>
        <p class="text-body-secondary" id="treasury_no_crossings"><?= lang('Accounting_treasuries.crossings_none') ?></p>
    <?php else: ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm acc-lines" id="treasury_crossings">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_treasuries.date') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.journal') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.source') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.document') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.money_out') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.balance') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($crossings as $row): ?>
                        <tr>
                            <td><?= esc($date((string) $row['journal_date'])) ?></td>
                            <td><?= $journal($row) ?></td>
                            <td><?= esc($source($row)) ?></td>
                            <td><bdi dir="ltr">#<?= (int) $row['source_id'] ?></bdi></td>
                            <td><?= $amount((string) $row['credit']) ?></td>
                            <td><?= $amount((string) $row['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="h5"><?= lang('Accounting_treasuries.movements') ?></h2>

    <form id="statement_filters" class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_treasuries/statement/' . (int) $treasury->treasury_id), 'attr') ?>" role="search" aria-label="<?= esc(lang('Accounting_reports.filters'), 'attr') ?>">
        <div class="acc-filter-row">
            <div class="acc-filter">
                <label class="form-label" for="f_from"><?= lang('Accounting_treasuries.statement_from') ?></label>
                <input type="date" id="f_from" name="from" class="form-control form-control-sm" value="<?= esc($filters['from'] ?? '', 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_to"><?= lang('Accounting_treasuries.statement_to') ?></label>
                <input type="date" id="f_to" name="to" class="form-control form-control-sm" value="<?= esc($filters['to'] ?? '', 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_amount_from"><?= lang('Accounting_treasuries.statement_amount_from') ?></label>
                <input type="text" inputmode="decimal" id="f_amount_from" name="amount_from" class="form-control form-control-sm" value="<?= esc($filters['amount_from'] ?? '', 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_amount_to"><?= lang('Accounting_treasuries.statement_amount_to') ?></label>
                <input type="text" inputmode="decimal" id="f_amount_to" name="amount_to" class="form-control form-control-sm" value="<?= esc($filters['amount_to'] ?? '', 'attr') ?>">
            </div>
            <div class="acc-filter">
                <label class="form-label" for="f_order"><?= lang('Accounting_treasuries.statement_order') ?></label>
                <select id="f_order" name="order" class="form-select form-select-sm">
                    <option value="desc"<?= $order === 'desc' ? ' selected' : '' ?>><?= lang('Accounting_treasuries.statement_order_desc') ?></option>
                    <option value="asc"<?= $order === 'asc' ? ' selected' : '' ?>><?= lang('Accounting_treasuries.statement_order_asc') ?></option>
                </select>
            </div>
            <div class="acc-filter acc-filter-actions">
                <button type="submit" class="btn btn-primary btn-sm"><span class="bi bi-search" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.apply') ?></button>
                <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/statement/' . (int) $treasury->treasury_id), 'attr') ?>"><?= lang('Accounting_reports.reset') ?></a>
            </div>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <p class="alert alert-danger" id="statement_error" role="alert"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($rows === []): ?>
        <p class="text-body-secondary" id="treasury_no_movements"><?= lang('Accounting_treasuries.movements_none') ?></p>
    <?php else: ?>
        <p class="small text-body-secondary" id="statement_count"><?= esc(lang('Accounting_treasuries.statement_count', [$total_rows])) ?></p>
        <div class="table-responsive mb-4">
            <table class="table table-sm acc-lines" id="treasury_movements">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_treasuries.date') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.journal') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.source') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.document') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.money_in') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.money_out') ?></th>
                        <th scope="col"><?= lang('Accounting_treasuries.balance') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr<?= bccomp((string) $row['balance'], '0', 4) < 0 ? ' class="table-danger"' : '' ?>>
                            <td><?= esc($date((string) $row['journal_date'])) ?></td>
                            <td><?= $journal($row) ?></td>
                            <td><?= esc($source($row)) ?></td>
                            <td><bdi dir="ltr">#<?= (int) $row['source_id'] ?></bdi></td>
                            <td><?= bccomp((string) $row['debit'], '0', 4) === 0 ? '' : $amount((string) $row['debit']) ?></td>
                            <td><?= bccomp((string) $row['credit'], '0', 4) === 0 ? '' : $amount((string) $row['credit']) ?></td>
                            <td><?= $amount((string) $row['balance']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($before !== null): ?>
            <p class="fw-semibold" id="statement_before"><?= lang('Accounting_treasuries.statement_before') ?>: <?= $amount($before) ?></p>
        <?php endif; ?>
        <?php if ($pages > 1): ?>
            <nav class="print_hide d-flex gap-2 align-items-center" aria-label="<?= esc(lang('Accounting_treasuries.statement_pages'), 'attr') ?>" id="statement_pages">
                <?php if ($page > 1): ?>
                    <a class="btn btn-outline-secondary btn-sm" rel="prev" href="<?= esc($page_url($page - 1), 'attr') ?>"><?= lang('Accounting_treasuries.statement_previous') ?></a>
                <?php endif; ?>
                <span class="small"><?= esc(lang('Accounting_treasuries.statement_page', [$page, $pages])) ?></span>
                <?php if ($page < $pages): ?>
                    <a class="btn btn-outline-secondary btn-sm" rel="next" href="<?= esc($page_url($page + 1), 'attr') ?>"><?= lang('Accounting_treasuries.statement_next') ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
