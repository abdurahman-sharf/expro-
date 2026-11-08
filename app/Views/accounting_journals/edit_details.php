<?php
/**
 * @var object                                                                                                            $journal
 * @var list<object>                                                                                                      $costCenters
 * @var string                                                                                                            $currency
 * @var string                                                                                                            $numberLabel
 * @var string                                                                                                            $version
 * @var string                                                                                                            $sourceLabel
 * @var bool                                                                                                              $showBranch
 * @var string|null                                                                                                       $notice
 * @var array<int, string>                                                                                                $centerLabels
 * @var array{allow_over_100: bool, cost_center_required: bool, automatic_default: bool, line_tags: bool, line_tax: bool} $settings
 * @var int                                                                                                               $decimals
 * @var string                                                                                                            $cap
 */
$format    = static fn (?string $value): string => to_currency_no_money($value ?? '0');
$date      = static fn (?string $value): string => $value === null || strtotime($value) === false ? '' : to_date(strtotime($value));
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice ?? null]) ?>

<form id="details_form" class="acc-journal app-form" action="<?= esc(base_url('accounting_journals/save_details/' . (int) $journal->journal_id), 'attr') ?>" method="post" novalidate>
    <div class="acc-journal-bar print_hide">
        <button type="submit" id="save_details" class="btn btn-primary btn-sm"><span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.save_details') ?></button>
        <a class="btn btn-link btn-sm" href="<?= esc(base_url('accounting_journals/view/' . (int) $journal->journal_id), 'attr') ?>"><?= lang('Accounting_journals.cancel') ?></a>
    </div>

    <input type="hidden" name="version" value="<?= esc($version, 'attr') ?>">

    <ul id="error_message_box" class="error_message_box" role="alert"></ul>

    <p class="form-text" role="note"><span class="bi bi-lock" aria-hidden="true"></span> <?= lang('Accounting_journals.details_locked_note') ?></p>

    <dl class="acc-summary">
        <div>
            <dt><?= lang('Accounting_journals.number') ?></dt>
            <dd><bdi dir="ltr"><?= esc($numberLabel) ?></bdi></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_journals.date') ?></dt>
            <dd><bdi dir="ltr"><?= esc($date($journal->journal_date)) ?></bdi></dd>
        </div>
        <?php if ($showBranch): ?>
            <div>
                <dt><?= lang('Accounting_journals.branch') ?></dt>
                <dd><bdi dir="ltr"><?= esc((string) $journal->branch_code) ?></bdi> <?= esc((string) $journal->branch_name) ?></dd>
            </div>
        <?php endif; ?>
        <div>
            <dt><?= lang('Accounting_journals.source') ?></dt>
            <dd><?= esc($sourceLabel) ?></dd>
        </div>
        <div>
            <dt><?= lang('Accounting_journals.currency') ?></dt>
            <dd><bdi dir="ltr"><?= esc($journal->currency_code ?? $currency) ?></bdi></dd>
        </div>
    </dl>

    <div class="acc-journal-head form-grid">
        <div class="field span-all">
            <label class="form-label" for="journal_description"><?= lang('Accounting_journals.description') ?></label>
            <div class="field-control">
                <textarea name="description" id="journal_description" class="form-control form-control-sm" rows="2" maxlength="2000"><?= esc((string) $journal->description) ?></textarea>
            </div>
        </div>
    </div>

    <div class="acc-lines-wrap">
        <table class="table acc-lines">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_journals.account') ?></th>
                    <th scope="col"><?= lang('Accounting_journals.line_description') ?></th>
                    <th scope="col"><?= lang('Accounting_journals.cost_center') ?></th>
                    <th scope="col" class="acc-amount"><?= lang('Accounting_journals.debit') ?></th>
                    <th scope="col" class="acc-amount"><?= lang('Accounting_journals.credit') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($journal->lines as $line): ?>
                    <?php $lineId = (int) $line->line_id; ?>
                    <tr>
                        <td data-label="<?= esc(lang('Accounting_journals.account'), 'attr') ?>"><bdi dir="ltr"><?= esc($line->account_code) ?></bdi> <?= esc($line->account_name) ?></td>
                        <td data-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>">
                            <input type="text" name="lines[<?= $lineId ?>][description]" class="form-control form-control-sm" maxlength="255" value="<?= esc((string) $line->description, 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>">
                            <?= view('accounting_journals/line_more', ['name' => "lines[{$lineId}]", 'analysis' => $line->analysis, 'settings' => $settings]) ?>
                        </td>
                        <td data-label="<?= esc(lang('Accounting_journals.cost_center'), 'attr') ?>">
                            <?= view('accounting_journals/line_charge', ['name' => "lines[{$lineId}]", 'analysis' => $line->analysis, 'centers' => $costCenters, 'labels' => $centerLabels, 'amount' => bccomp($line->debit, '0', 4) > 0 ? $line->debit : $line->credit]) ?>
                        </td>
                        <td class="acc-amount" data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>"><bdi dir="ltr"><?= bccomp($line->debit, '0', 4) === 0 ? '' : esc($format($line->debit)) ?></bdi></td>
                        <td class="acc-amount" data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>"><bdi dir="ltr"><?= bccomp($line->credit, '0', 4) === 0 ? '' : esc($format($line->credit)) ?></bdi></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="3"><?= lang('Accounting_journals.totals') ?></th>
                    <td class="acc-amount"><bdi dir="ltr"><?= esc($format($journal->total_debit)) ?></bdi></td>
                    <td class="acc-amount"><bdi dir="ltr"><?= esc($format($journal->total_credit)) ?></bdi></td>
                </tr>
            </tfoot>
        </table>
    </div>
</form>

<?= view('accounting_journals/distribution_script', ['centers' => $costCenters, 'decimals' => $decimals, 'overAllowed' => $settings['allow_over_100'], 'cap' => $cap]) ?>
<?= view('accounting_journals/line_more_script') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const failed = <?= json_encode(lang('Accounting_journals.request_failed'), $jsonFlags) ?>;
        let busy = false;

        journal_distribution.bind($('#details_form'), function($dist) {
            return journal_distribution.units(String($dist.attr('data-amount')));
        });

        $('#details_form').on('submit', function(event) {
            event.preventDefault();

            if (busy) {
                return;
            }

            busy = true;
            $('#save_details').prop('disabled', true);
            $('#error_message_box').empty();

            $.post($(this).attr('action'), $(this).serialize(), function(response) {
                if (response.success) {
                    window.location.href = response.redirect;
                    return;
                }
                $('#error_message_box').append($('<li></li>').text(response.message));
                toast_support.show(response.message, 'danger');
            }, 'json').fail(function() {
                $('#error_message_box').append($('<li></li>').text(failed));
                toast_support.show(failed, 'danger');
            }).always(function() {
                busy = false;
                $('#save_details').prop('disabled', false);
            });
        });
    });
</script>

<?= view('partial/footer') ?>
