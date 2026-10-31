<?php
/**
 * @var list<array<string, mixed>> $accounts
 */
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <p class="mb-0 me-auto text-body-secondary"><?= lang('Accounting_bank.note') ?></p>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries'), 'attr') ?>">
        <span class="bi bi-list-ul" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.treasuries') ?>
    </a>
</div>

<div class="container-fluid py-3" id="bank_accounts">
    <h1 class="h4"><?= lang('Accounting_bank.title') ?></h1>

    <?php if ($accounts === []): ?>
        <p class="text-body-secondary" id="bank_accounts_empty"><?= lang('Accounting_bank.empty') ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="bank_accounts_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_bank.account') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.bank') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.branch') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.book_balance') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.unmatched_lines') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.closed_through') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Accounting_bank.open') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <?php $treasury = $account['treasury']; ?>
                        <tr data-treasury-id="<?= (int) $treasury->treasury_id ?>">
                            <td>
                                <?= esc($treasury->name) ?>
                                <?php if ($account['lacks_details']): ?>
                                    <div><span class="badge text-bg-warning bank-details-missing" title="<?= esc(lang('Accounting_treasuries.bank_details_missing_hint'), 'attr') ?>"><?= lang('Accounting_treasuries.bank_details_missing') ?></span></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= esc($account['bank']) ?>
                                <?php if (trim((string) ($treasury->account_number ?? '')) !== ''): ?>
                                    <div class="small text-body-secondary"><bdi dir="ltr"><?= esc($treasury->account_number) ?></bdi></div>
                                <?php endif; ?>
                            </td>
                            <td><?= esc($treasury->branch_code) ?></td>
                            <td><bdi dir="ltr"><?= esc($account['balance']) ?></bdi></td>
                            <td><?= (int) $account['unmatched'] ?></td>
                            <td><?= esc($account['closed_through']) ?></td>
                            <td class="text-end"><a class="btn btn-outline-primary btn-sm" href="<?= esc(base_url('accounting_treasuries/bank/' . (int) $treasury->treasury_id), 'attr') ?>"><?= lang('Accounting_bank.open') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
