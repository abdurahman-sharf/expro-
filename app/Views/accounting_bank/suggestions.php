<?php
/**
 * @var object                                                                  $treasury
 * @var list<array{line: object, candidates: list<object>, confidence: string}> $suggestions
 * @var int                                                                     $window
 * @var bool                                                                    $journal_links
 * @var Closure                                                                 $amount
 * @var Closure                                                                 $date
 * @var Closure                                                                 $number
 */
$id    = (int) $treasury->treasury_id;
$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('.bank-accept').on('click', function() {
            const button = $(this);

            $.post(<?= json_encode(base_url('accounting_treasuries/bank/' . $id . '/match'), $flags) ?>, {
                line_id: button.data('line'),
                'journal_line_ids[]': button.data('journal'),
                method: 'suggested'
            }, function(response) {
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

<div class="container-fluid py-3" id="bank_suggestions">
    <h1 class="h4"><?= lang('Accounting_bank.suggestions_title', [esc($treasury->name)]) ?></h1>
    <p class="small text-body-secondary"><?= lang('Accounting_bank.suggestions_note', [$window]) ?></p>

    <?php if ($suggestions === []): ?>
        <p class="text-body-secondary" id="bank_suggestions_empty"><?= lang('Accounting_bank.suggestions_none') ?></p>
    <?php else: ?>
        <?php foreach ($suggestions as $suggestion): ?>
            <?php $line = $suggestion['line']; ?>
            <div class="card mb-3 bank-suggestion" data-line-id="<?= (int) $line->line_id ?>">
                <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                    <strong><?= esc($date((string) $line->line_date)) ?></strong>
                    <span><?= esc($line->description) ?></span>
                    <?php if ($line->reference !== ''): ?><span class="text-body-secondary"><bdi dir="ltr"><?= esc($line->reference) ?></bdi></span><?php endif; ?>
                    <span class="ms-auto"><?= $amount((string) $line->amount) ?></span>
                    <span class="badge <?= $suggestion['confidence'] === 'ambiguous' ? 'text-bg-warning' : 'text-bg-success' ?>"><?= lang('Accounting_bank.confidence_' . $suggestion['confidence']) ?></span>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($suggestion['candidates'] as $candidate): ?>
                        <li class="list-group-item d-flex flex-wrap gap-2 align-items-center">
                            <?= esc($date((string) $candidate->journal_date)) ?>
                            <?php $label = esc($number($candidate)); ?>
                            <?= $journal_links ? '<a href="' . esc(base_url('accounting_journals/view/' . (int) $candidate->journal_id), 'attr') . '">' . $label . '</a>' : $label ?>
                            <span><?= esc($candidate->description) ?></span>
                            <?php if ($candidate->hint === 'reference'): ?><span class="badge text-bg-primary"><?= lang('Accounting_bank.hint_reference') ?></span><?php endif; ?>
                            <?php if ($candidate->hint === 'description'): ?><span class="badge text-bg-info"><?= lang('Accounting_bank.hint_description') ?></span><?php endif; ?>
                            <span class="text-body-secondary small"><?= lang('Accounting_bank.days_apart', [$candidate->days]) ?></span>
                            <button type="button" class="btn btn-outline-primary btn-sm ms-auto bank-accept" data-line="<?= (int) $line->line_id ?>" data-journal="<?= (int) $candidate->line_id ?>"><?= lang('Accounting_bank.accept') ?></button>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
