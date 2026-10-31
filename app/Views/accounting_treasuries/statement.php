<?php
/**
 * @var object       $treasury
 * @var string       $balance
 * @var list<array<string, mixed>> $rows
 * @var int          $total_rows
 * @var list<array<string, mixed>> $crossings
 * @var string       $lowest
 * @var bool         $journal_links
 * @var Closure      $amount
 * @var array<int, int> $matched
 * @var Closure      $date
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
            <table class="table table-sm" id="treasury_crossings">
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
    <?php if ($rows === []): ?>
        <p class="text-body-secondary" id="treasury_no_movements"><?= lang('Accounting_treasuries.movements_none') ?></p>
    <?php else: ?>
        <?php if ($total_rows > count($rows)): ?>
            <p class="small text-body-secondary"><?= lang('Accounting_treasuries.movements_limit', [count($rows), $total_rows]) ?></p>
        <?php endif; ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm" id="treasury_movements">
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
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
