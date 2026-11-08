<?php
/**
 * @var object                $treasury
 * @var ReconciliationFigures $figures
 * @var string                $asOf
 * @var string                $balanceInput
 * @var string                $error
 * @var bool                  $journalLinks
 * @var int                   $listLimit
 * @var Closure               $amount
 * @var Closure               $date
 * @var Closure               $number
 * @var string                $csvUrl
 */

use App\Libraries\Accounting\Bank\ReconciliationFigures;

$id      = (int) $treasury->treasury_id;
$flags   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$journal = static function (object $line) use ($journalLinks, $number): string {
    $label = esc($number($line));

    return $journalLinks ? '<a href="' . esc(base_url('accounting_journals/view/' . (int) $line->journal_id), 'attr') . '">' . $label . '</a>' : $label;
};
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#bank_close_form').on('submit', function(event) {
            event.preventDefault();

            if (!confirm(<?= json_encode(lang('Accounting_bank.confirm_close'), $flags) ?>)) {
                return;
            }

            $.post(<?= json_encode(base_url('accounting_treasuries/bank/' . $id . '/close'), $flags) ?>, $(this).serialize(), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');

                if (response.success) {
                    setTimeout(function() {
                        window.location.href = <?= json_encode(base_url('accounting_treasuries/bank/' . $id), $flags) ?>;
                    }, 700);
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/bank/' . $id), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.back_account') ?></a>
    <button class="btn btn-outline-secondary btn-sm" type="button" onclick="window.print()"><span class="bi bi-printer" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.print') ?></button>
    <a class="btn btn-outline-secondary btn-sm" id="bank_report_csv" href="<?= esc($csvUrl, 'attr') ?>"><span class="bi bi-filetype-csv" aria-hidden="true">&nbsp;</span><?= lang('Accounting_reports.csv') ?></a>
    <?= view('partial/report_files', ['csvUrl' => $csvUrl, 'id' => 'bank_report_csv']) ?>
</div>

<div class="container-fluid py-3" id="bank_report">
    <h1 class="h4"><?= lang('Accounting_bank.report_title', [esc($treasury->name)]) ?></h1>

    <form method="get" class="row g-2 align-items-end mb-3 print_hide" id="bank_report_form">
        <div class="col-auto">
            <label class="form-label" for="as_of"><?= lang('Accounting_bank.as_of') ?></label>
            <input type="date" class="form-control form-control-sm" name="as_of" id="as_of" value="<?= esc($asOf, 'attr') ?>">
        </div>
        <div class="col-auto">
            <label class="form-label" for="statement_balance"><?= lang('Accounting_bank.statement_balance_on_date') ?></label>
            <input type="text" class="form-control form-control-sm" dir="ltr" name="statement_balance" id="statement_balance" inputmode="decimal" autocomplete="off" value="<?= esc($balanceInput, 'attr') ?>">
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-outline-primary btn-sm"><?= lang('Accounting_bank.show') ?></button></div>
    </form>

    <?php if ($error !== ''): ?>
        <p class="alert alert-danger" id="bank_report_error"><?= esc($error) ?></p>
    <?php endif; ?>

    <?php if ($figures->closedThrough !== null): ?>
        <p class="small text-body-secondary"><span class="bi bi-lock-fill" aria-hidden="true"></span> <?= lang('Accounting_bank.closed_up_to', [esc($date($figures->closedThrough))]) ?></p>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <h2 class="h5"><?= lang('Accounting_bank.side_books') ?></h2>
            <table class="table table-sm" id="bank_report_books">
                <tbody>
                    <tr><th scope="row"><?= lang('Accounting_bank.book_balance') ?></th><td class="text-end"><?= $amount($figures->bookBalance) ?></td></tr>
                    <tr><th scope="row"><?= lang('Accounting_bank.unmatched_statement') ?> (<?= (int) $figures->unmatchedStatement['count'] ?>)</th><td class="text-end"><?= $amount($figures->unmatchedStatement['total']) ?></td></tr>
                    <tr class="table-light"><th scope="row"><?= lang('Accounting_bank.adjusted_book') ?></th><td class="text-end fw-bold"><?= $amount($figures->adjustedBook) ?></td></tr>
                </tbody>
            </table>
        </div>
        <div class="col-lg-6">
            <h2 class="h5"><?= lang('Accounting_bank.side_bank') ?></h2>
            <table class="table table-sm" id="bank_report_bank">
                <tbody>
                    <tr><th scope="row"><?= lang('Accounting_bank.statement_balance') ?></th><td class="text-end"><?= $figures->statementBalance === null ? '<span class="text-body-secondary">' . esc(lang('Accounting_bank.not_entered')) . '</span>' : $amount($figures->statementBalance) ?></td></tr>
                    <tr><th scope="row"><?= lang('Accounting_bank.outstanding_books') ?> (<?= (int) $figures->outstandingBook['count'] ?>)</th><td class="text-end"><?= $amount($figures->outstandingBook['total']) ?></td></tr>
                    <tr class="table-light"><th scope="row"><?= lang('Accounting_bank.adjusted_bank') ?></th><td class="text-end fw-bold"><?= $figures->adjustedBank === null ? '' : $amount($figures->adjustedBank) ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <p id="bank_report_difference" class="fs-6">
        <?= lang('Accounting_bank.difference') ?>:
        <?php if ($figures->difference === null): ?>
            <span class="text-body-secondary"><?= lang('Accounting_bank.not_computed') ?></span>
        <?php elseif ($figures->reconciled()): ?>
            <span class="badge text-bg-success"><?= lang('Accounting_bank.reconciled') ?></span>
        <?php else: ?>
            <span class="badge text-bg-danger"><?= $amount($figures->difference) ?></span>
            <span class="small text-body-secondary"><?= lang('Accounting_bank.difference_hint') ?></span>
        <?php endif; ?>
    </p>

    <dl class="row small text-body-secondary">
        <dt class="col-sm-4"><?= lang('Accounting_bank.statement_lines_total') ?></dt>
        <dd class="col-sm-8"><?= $amount($figures->statementLinesTotal) ?></dd>
        <dt class="col-sm-4"><?= lang('Accounting_bank.carried') ?> (<?= (int) $figures->carriedBook['count'] ?>)</dt>
        <dd class="col-sm-8"><?= $amount($figures->carriedBook['total']) ?> <?= $figures->coverageStart === null ? lang('Accounting_bank.carried_no_statement') : lang('Accounting_bank.carried_before', [esc($date($figures->coverageStart))]) ?></dd>
        <dt class="col-sm-4"><?= lang('Accounting_bank.matches_made') ?></dt>
        <dd class="col-sm-8"><?= (int) $figures->matchesMade ?></dd>
    </dl>

    <?php if ($figures->reconciled()): ?>
        <form id="bank_close_form" class="print_hide mb-4">
            <input type="hidden" name="through" value="<?= esc($asOf, 'attr') ?>">
            <input type="hidden" name="statement_balance" value="<?= esc((string) $figures->statementBalance, 'attr') ?>">
            <button type="submit" class="btn btn-primary btn-sm" id="bank_close_submit"><span class="bi bi-lock" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.close_reconciliation', [esc($date($asOf))]) ?></button>
            <span class="small text-body-secondary"><?= lang('Accounting_bank.close_note') ?></span>
        </form>
    <?php endif; ?>

    <h2 class="h5"><?= lang('Accounting_bank.unmatched_statement') ?></h2>
    <?php if ($figures->statementLines === []): ?>
        <p class="text-body-secondary" id="bank_report_no_statement_items"><?= lang('Accounting_bank.none') ?></p>
    <?php else: ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm acc-lines" id="bank_report_statement_items">
                <thead><tr><th scope="col"><?= lang('Accounting_bank.date') ?></th><th scope="col"><?= lang('Accounting_bank.description') ?></th><th scope="col"><?= lang('Accounting_bank.reference') ?></th><th scope="col"><?= lang('Accounting_bank.amount') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($figures->statementLines as $line): ?>
                        <tr><td><?= esc($date((string) $line->line_date)) ?></td><td><?= esc($line->description) ?></td><td><bdi dir="ltr"><?= esc($line->reference) ?></bdi></td><td><?= $amount((string) $line->amount) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="h5"><?= lang('Accounting_bank.outstanding_books') ?></h2>
    <?php if ($figures->bookLines === []): ?>
        <p class="text-body-secondary" id="bank_report_no_book_items"><?= lang('Accounting_bank.none') ?></p>
    <?php else: ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm acc-lines" id="bank_report_book_items">
                <thead><tr><th scope="col"><?= lang('Accounting_bank.date') ?></th><th scope="col"><?= lang('Accounting_bank.journal') ?></th><th scope="col"><?= lang('Accounting_bank.description') ?></th><th scope="col"><?= lang('Accounting_bank.amount') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($figures->bookLines as $line): ?>
                        <tr><td><?= esc($date((string) $line->journal_date)) ?></td><td><?= $journal($line) ?></td><td><?= esc($line->description) ?></td><td><?= $amount((string) $line->amount) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($figures->carriedLines !== []): ?>
        <h2 class="h5"><?= lang('Accounting_bank.carried') ?></h2>
        <div class="table-responsive mb-4">
            <table class="table table-sm acc-lines" id="bank_report_carried_items">
                <thead><tr><th scope="col"><?= lang('Accounting_bank.date') ?></th><th scope="col"><?= lang('Accounting_bank.journal') ?></th><th scope="col"><?= lang('Accounting_bank.description') ?></th><th scope="col"><?= lang('Accounting_bank.amount') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($figures->carriedLines as $line): ?>
                        <tr><td><?= esc($date((string) $line->journal_date)) ?></td><td><?= $journal($line) ?></td><td><?= esc($line->description) ?></td><td><?= $amount((string) $line->amount) ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (max($figures->unmatchedStatement['count'], $figures->outstandingBook['count'], $figures->carriedBook['count']) > $listLimit): ?>
        <p class="small text-body-secondary"><?= lang('Accounting_bank.list_limit', [$listLimit]) ?></p>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
