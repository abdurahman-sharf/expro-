<?php
/**
 * @var object       $treasury
 * @var string       $bank
 * @var bool         $lacks_details
 * @var string       $balance
 * @var list<object> $statements
 * @var list<object> $closings
 * @var int|null     $latest
 * @var int          $window
 * @var int          $window_min
 * @var int          $window_max
 * @var Closure      $amount
 * @var Closure      $date
 * @var Closure      $when
 */
$id   = (int) $treasury->treasury_id;
$base = base_url('accounting_treasuries/bank/' . $id);
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const done = function(response) {
            toast_support.show(response.message, response.success ? 'success' : 'danger');

            if (response.success) {
                setTimeout(function() {
                    window.location.reload();
                }, 700);
            }
        };

        $('.bank-statement-delete').on('click', function() {
            if (confirm(<?= json_encode(lang('Accounting_bank.confirm_delete_statement'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)) {
                $.post('accounting_treasuries/bank/statement_delete/' + $(this).data('id'), {}, done, 'json');
            }
        });

        $('.bank-reopen').on('click', function() {
            const reason = prompt(<?= json_encode(lang('Accounting_bank.reopen_reason_prompt'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);

            if (reason !== null) {
                $.post('accounting_treasuries/bank/reopen/' + $(this).data('id'), {reason: reason}, done, 'json');
            }
        });

        $('#bank_window_form').on('submit', function(event) {
            event.preventDefault();
            $.post('accounting_treasuries/bank/window', $(this).serialize(), done, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/bank'), 'attr') ?>"><span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.back') ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/statement/' . $id), 'attr') ?>"><span class="bi bi-journal-text" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.treasury_statement') ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc($base . '/lines', 'attr') ?>"><?= lang('Accounting_bank.lines') ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc($base . '/matches', 'attr') ?>"><?= lang('Accounting_bank.matches') ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc($base . '/suggestions', 'attr') ?>"><?= lang('Accounting_bank.suggestions') ?></a>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc($base . '/report', 'attr') ?>"><?= lang('Accounting_bank.report') ?></a>
    <a class="btn btn-primary btn-sm" id="bank_import_link" href="<?= esc($base . '/import', 'attr') ?>"><span class="bi bi-upload" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.import') ?></a>
</div>

<div class="container-fluid py-3" id="bank_account">
    <h1 class="h4"><?= esc($treasury->name) ?></h1>

    <dl class="row mb-4">
        <dt class="col-sm-3"><?= lang('Accounting_bank.bank') ?></dt>
        <dd class="col-sm-9">
            <?= esc($bank) ?>
            <?php if (trim((string) ($treasury->account_number ?? '')) !== ''): ?>
                · <bdi dir="ltr"><?= esc($treasury->account_number) ?></bdi>
            <?php endif; ?>
            <?php if ($lacks_details): ?>
                <span class="badge text-bg-warning bank-details-missing"><?= lang('Accounting_treasuries.bank_details_missing') ?></span>
            <?php endif; ?>
        </dd>
        <dt class="col-sm-3"><?= lang('Accounting_bank.branch') ?></dt>
        <dd class="col-sm-9"><?= esc($treasury->branch_code) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_bank.book_balance') ?></dt>
        <dd class="col-sm-9"><?= $amount($balance) ?></dd>
    </dl>

    <h2 class="h5"><?= lang('Accounting_bank.statements') ?></h2>
    <?php if ($statements === []): ?>
        <p class="text-body-secondary" id="bank_no_statements"><?= lang('Accounting_bank.statements_none') ?></p>
    <?php else: ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle" id="bank_statements">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_bank.file') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.period') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.lines_imported') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.lines_known') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.imported_by') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Common.delete') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statements as $statement): ?>
                        <tr>
                            <td><?= esc($statement->file_name) ?></td>
                            <td><?= esc($date((string) $statement->date_from)) ?> - <?= esc($date((string) $statement->date_to)) ?></td>
                            <td><?= (int) $statement->line_count ?></td>
                            <td><?= (int) $statement->duplicate_count ?></td>
                            <td><?= esc((string) $statement->imported_by_name) ?> <span class="small text-body-secondary"><?= esc($when((string) $statement->imported_at)) ?></span></td>
                            <td class="text-end"><button type="button" class="btn btn-link p-0 text-danger bank-statement-delete" data-id="<?= (int) $statement->statement_id ?>" title="<?= esc(lang('Common.delete'), 'attr') ?>" aria-label="<?= esc(lang('Common.delete'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="h5"><?= lang('Accounting_bank.closings') ?></h2>
    <?php if ($closings === []): ?>
        <p class="text-body-secondary" id="bank_no_closings"><?= lang('Accounting_bank.closings_none') ?></p>
    <?php else: ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle" id="bank_closings">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_bank.closed_through') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.statement_balance') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.book_balance') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.matches_held') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.status') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Accounting_bank.reopen') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($closings as $closing): ?>
                        <tr>
                            <td><?= esc($date((string) $closing->closed_through)) ?></td>
                            <td><?= $amount((string) $closing->statement_balance) ?></td>
                            <td><?= $amount((string) $closing->book_balance) ?></td>
                            <td><?= (int) $closing->match_count ?></td>
                            <td>
                                <?php if ($closing->status === 'closed'): ?>
                                    <span class="badge text-bg-success"><?= lang('Accounting_bank.status_closed') ?></span>
                                    <?php if ($closing->drifted): ?>
                                        <span class="badge text-bg-warning" title="<?= esc(lang('Accounting_bank.drifted_hint'), 'attr') ?>"><?= lang('Accounting_bank.drifted') ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge text-bg-secondary" title="<?= esc((string) $closing->reopen_reason, 'attr') ?>"><?= lang('Accounting_bank.status_reopened') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($latest !== null && (int) $closing->reconciliation_id === $latest): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm bank-reopen" data-id="<?= (int) $closing->reconciliation_id ?>"><?= lang('Accounting_bank.reopen') ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h2 class="h5"><?= lang('Accounting_bank.window_title') ?></h2>
    <form id="bank_window_form" class="row g-2 align-items-end mb-2">
        <div class="col-auto">
            <label class="form-label" for="bank_window_days"><?= lang('Accounting_bank.window_days') ?></label>
            <input type="number" class="form-control form-control-sm" id="bank_window_days" name="days" min="<?= (int) $window_min ?>" max="<?= (int) $window_max ?>" value="<?= (int) $window ?>">
        </div>
        <div class="col-auto"><button type="submit" class="btn btn-outline-primary btn-sm"><?= lang('Common.submit') ?></button></div>
    </form>
    <p class="small text-body-secondary"><?= lang('Accounting_bank.window_note') ?></p>
</div>

<?= view('partial/footer') ?>
