<?php
/**
 * @var object       $treasury
 * @var object       $line
 * @var list<object> $candidates
 * @var int          $window
 * @var bool         $journal_links
 * @var Closure      $amount
 * @var Closure      $date
 * @var Closure      $number
 */

$id      = (int) $treasury->treasury_id;
$deposit = bccomp((string) $line->amount, '0', 4) > 0;
$flags   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const wanted = <?= json_encode(ltrim((string) $line->amount, '-'), $flags) ?>;
        const scale = 10000;

        const update = function() {
            let total = 0;

            $('.bank-candidate:checked').each(function() {
                total += Math.round(Math.abs(parseFloat($(this).data('amount'))) * scale);
            });

            const equal = total === Math.round(parseFloat(wanted) * scale);

            $('#bank_match_total').text((total / scale).toFixed(4));
            $('#bank_match_submit').prop('disabled', !equal);
            $('#bank_match_total_badge').toggleClass('text-bg-success', equal).toggleClass('text-bg-warning', !equal);
        };

        $('.bank-candidate').on('change', update);
        update();

        $('#bank_match_form').on('submit', function(event) {
            event.preventDefault();

            $.post(<?= json_encode(base_url('accounting_treasuries/bank/' . $id . '/match'), $flags) ?>, $(this).serialize(), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');

                if (response.success) {
                    setTimeout(function() {
                        window.location.href = <?= json_encode(base_url('accounting_treasuries/bank/' . $id . '/lines?state=unmatched'), $flags) ?>;
                    }, 700);
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('accounting_treasuries/bank/' . $id . '/lines?state=unmatched'), 'attr') ?>"><span class="bi bi-arrow-left" aria-hidden="true">&nbsp;</span><?= lang('Accounting_bank.back_lines') ?></a>
</div>

<div class="container-fluid py-3" id="bank_match">
    <h1 class="h4"><?= lang('Accounting_bank.match_title', [esc($treasury->name)]) ?></h1>

    <dl class="row mb-3" id="bank_match_line">
        <dt class="col-sm-3"><?= lang('Accounting_bank.date') ?></dt>
        <dd class="col-sm-9"><?= esc($date((string) $line->line_date)) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_bank.description') ?></dt>
        <dd class="col-sm-9"><?= esc($line->description) ?></dd>
        <dt class="col-sm-3"><?= lang('Accounting_bank.reference') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= esc($line->reference) ?></bdi></dd>
        <dt class="col-sm-3"><?= lang($deposit ? 'Accounting_bank.deposit' : 'Accounting_bank.withdrawal') ?></dt>
        <dd class="col-sm-9"><?= $amount(ltrim((string) $line->amount, '-')) ?></dd>
    </dl>

    <h2 class="h5"><?= lang('Accounting_bank.book_lines') ?></h2>
    <p class="small text-body-secondary"><?= lang($deposit ? 'Accounting_bank.book_lines_deposit_note' : 'Accounting_bank.book_lines_withdrawal_note', [$window]) ?></p>

    <?php if ($candidates === []): ?>
        <p class="text-body-secondary" id="bank_match_none"><?= lang('Accounting_bank.book_lines_none') ?></p>
    <?php else: ?>
        <form id="bank_match_form">
            <input type="hidden" name="line_id" value="<?= (int) $line->line_id ?>">
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="bank_match_candidates">
                    <thead>
                        <tr>
                            <th scope="col"><span class="visually-hidden"><?= lang('Accounting_bank.choose') ?></span></th>
                            <th scope="col"><?= lang('Accounting_bank.date') ?></th>
                            <th scope="col"><?= lang('Accounting_bank.journal') ?></th>
                            <th scope="col"><?= lang('Accounting_bank.description') ?></th>
                            <th scope="col"><?= lang('Accounting_bank.amount') ?></th>
                            <th scope="col"><span class="visually-hidden"><?= lang('Accounting_bank.hint') ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($candidates as $candidate): ?>
                            <tr<?= $candidate->near ? ' class="table-success"' : '' ?>>
                                <td><input type="checkbox" class="form-check-input bank-candidate" name="journal_line_ids[]" value="<?= (int) $candidate->line_id ?>" data-amount="<?= esc((string) $candidate->amount, 'attr') ?>" aria-label="<?= esc(lang('Accounting_bank.choose'), 'attr') ?>"></td>
                                <td><?= esc($date((string) $candidate->journal_date)) ?></td>
                                <td>
                                    <?php $label = esc($number($candidate)); ?>
                                    <?= $journal_links ? '<a href="' . esc(base_url('accounting_journals/view/' . (int) $candidate->journal_id), 'attr') . '">' . $label . '</a>' : $label ?>
                                    <?php if ($candidate->status === 'reversed'): ?><span class="badge text-bg-secondary"><?= lang('Accounting.state_reversed') ?></span><?php endif; ?>
                                </td>
                                <td><?= esc($candidate->description) ?></td>
                                <td><?= $amount(ltrim((string) $candidate->amount, '-')) ?></td>
                                <td>
                                    <?php if ($candidate->near): ?><span class="badge text-bg-success"><?= lang('Accounting_bank.hint_near') ?></span><?php endif; ?>
                                    <?php if ($candidate->hint === 'reference'): ?><span class="badge text-bg-primary"><?= lang('Accounting_bank.hint_reference') ?></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p>
                <?= lang('Accounting_bank.chosen_total') ?>
                <span class="badge text-bg-warning" id="bank_match_total_badge"><bdi dir="ltr" id="bank_match_total">0.0000</bdi></span>
                <span class="text-body-secondary">/ <bdi dir="ltr"><?= esc(ltrim((string) $line->amount, '-')) ?></bdi></span>
            </p>
            <button type="submit" class="btn btn-primary btn-sm" id="bank_match_submit" disabled><?= lang('Accounting_bank.match') ?></button>
        </form>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
