<?php
/**
 * @var object       $treasury
 * @var list<object> $matches
 * @var bool         $journal_links
 * @var Closure      $amount
 * @var Closure      $date
 * @var Closure      $number
 */

$id    = (int) $treasury->treasury_id;
$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('.bank-unmatch').on('click', function() {
            if (!confirm(<?= json_encode(lang('Accounting_bank.confirm_unmatch'), $flags) ?>)) {
                return;
            }

            $.post(<?= json_encode(base_url('accounting_treasuries/bank/' . $id . '/unmatch/'), $flags) ?> + $(this).data('match'), {}, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');

                if (response.success) {
                    setTimeout(function() {
                        window.location.reload();
                    }, 700);
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/bank/' . $id), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.back_account') ?></a>
</div>

<div class="container-fluid py-3" id="bank_matches">
    <h1 class="h4"><?= lang('Accounting_bank.matches_title', [esc($treasury->name)]) ?></h1>
    <p class="small text-body-secondary"><?= lang('Accounting_bank.matches_note') ?></p>

    <?php if ($matches === []): ?>
        <p class="text-body-secondary" id="bank_matches_empty"><?= lang('Accounting_bank.matches_none') ?></p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="bank_matches_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_bank.statement_line') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.amount') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.journal_lines') ?></th>
                        <th scope="col"><?= lang('Accounting_bank.status') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Accounting_bank.actions') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $match): ?>
                        <tr data-match-id="<?= (int) $match->match_id ?>">
                            <td>
                                <?= esc($date((string) $match->line_date)) ?> - <?= esc($match->description) ?>
                                <?php if ($match->reference !== ''): ?><div class="small text-body-secondary"><bdi dir="ltr"><?= esc($match->reference) ?></bdi></div><?php endif; ?>
                            </td>
                            <td><?= $amount((string) $match->amount) ?></td>
                            <td>
                                <?php foreach ($match->journal_lines as $line): ?>
                                    <div>
                                        <?php $label = esc($number($line)); ?>
                                        <?= $journal_links ? '<a href="' . esc(base_url('accounting_journals/view/' . (int) $line->journal_id), 'attr') . '">' . $label . '</a>' : $label ?>
                                        · <?= esc($date((string) $line->journal_date)) ?> · <?= $amount((string) $line->amount) ?>
                                        <?php if ($line->status === 'reversed'): ?><span class="badge text-bg-secondary"><?= lang('Accounting.state_reversed') ?></span><?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <?php if ($match->reconciliation_id === null): ?>
                                    <span class="badge text-bg-success"><?= lang('Accounting_bank.state_matched') ?></span>
                                <?php else: ?>
                                    <span class="badge text-bg-primary"><span class="bi bi-lock-fill" aria-hidden="true"></span> <?= lang('Accounting_bank.locked') ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($match->reconciliation_id === null): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm bank-unmatch" data-match="<?= (int) $match->match_id ?>"><?= lang('Accounting_bank.unmatch') ?></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
