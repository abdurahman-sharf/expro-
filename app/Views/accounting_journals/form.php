<?php
/**
 * @var object|null $journal
 * @var list<object> $branches
 * @var int $branch_id
 * @var array<int, array{date: string, label: string}|null> $cutoffs
 * @var list<object> $accounts
 * @var list<object> $cost_centers
 * @var string $currency
 * @var bool $can_post
 * @var string|null $notice
 * @var list<array{id: int, name: string, size: string, by: string, at: string, url: string}> $attachments
 * @var bool $canAttach
 * @var bool $lineDescriptionRequired
 * @var array{allow_over_100: bool, cost_center_required: bool, automatic_default: bool, line_tags: bool, line_tax: bool} $settings
 * @var array<int, list<array{cost_center_id: int, percent: string}>> $defaults The default distribution of the accounts that have one.
 * @var array<int, string> $center_labels
 * @var int $decimals
 * @var string $cap
 */


$isNew       = $journal === null;
$journalId   = $isNew ? NEW_ENTRY : (int) $journal->journal_id;
$lines       = $isNew ? [] : $journal->lines;
$jsonFlags   = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$accountLabel = static fn (object $account): string => $account->code . ' - ' . $account->name;
$accountLabels = [];

foreach ($accounts as $account) {
    $accountLabels[(int) $account->account_id] = $accountLabel($account);
}

$accountInputs = static function (string $name, ?int $selected) use ($accountLabels): string {
    $label = $selected !== null && isset($accountLabels[$selected]) ? $accountLabels[$selected] : '';

    return '<input type="text" class="form-control form-control-sm acc-account-text" list="accounts_list" autocomplete="off" value="' . esc($label, 'attr') . '" placeholder="' . esc(lang('Accounting_journals.choose_account'), 'attr') . '" aria-label="' . esc(lang('Accounting_journals.account'), 'attr') . '">'
        . '<input type="hidden" name="' . esc($name, 'attr') . '" class="acc-account" value="' . ($selected === null ? '' : $selected) . '">';
};
$amount = static fn (?string $value): string => $value === null || bccomp($value, '0', 4) === 0 ? '' : rtrim(rtrim($value, '0'), '.');
$rows   = $lines === [] ? [null, null] : $lines;
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice ?? null]) ?>

<template id="line_template">
    <tr class="acc-line">
        <td data-label="<?= esc(lang('Accounting_journals.account'), 'attr') ?>">
            <?= $accountInputs('lines[__INDEX__][account_id]', null) ?>
        </td>
        <td data-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>">
            <input type="text" name="lines[__INDEX__][description]" class="form-control form-control-sm acc-line-text" maxlength="255"<?= $lineDescriptionRequired ? ' aria-required="true"' : '' ?> aria-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>">
            <?= view('accounting_journals/line_more', ['name' => 'lines[__INDEX__]', 'analysis' => null, 'settings' => $settings]) ?>
        </td>
        <td data-label="<?= esc(lang('Accounting_journals.cost_center'), 'attr') ?>">
            <?= view('accounting_journals/line_charge', ['name' => 'lines[__INDEX__]', 'analysis' => null, 'centers' => $cost_centers, 'labels' => $center_labels, 'amount' => null]) ?>
        </td>
        <td data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>">
            <input type="text" name="lines[__INDEX__][debit]" class="form-control form-control-sm ltr-input acc-debit" dir="ltr" inputmode="decimal" autocomplete="off" aria-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>">
        </td>
        <td data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>">
            <input type="text" name="lines[__INDEX__][credit]" class="form-control form-control-sm ltr-input acc-credit" dir="ltr" inputmode="decimal" autocomplete="off" aria-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>">
        </td>
        <td class="acc-line-actions">
            <button type="button" class="btn btn-outline-danger btn-sm acc-line-remove" title="<?= esc(lang('Accounting_journals.remove_line'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.remove_line'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button>
        </td>
    </tr>
</template>

<datalist id="accounts_list">
    <?php foreach ($accounts as $account): ?>
        <option value="<?= esc($accountLabel($account), 'attr') ?>" data-id="<?= (int) $account->account_id ?>" data-code="<?= esc($account->code, 'attr') ?>"></option>
    <?php endforeach; ?>
</datalist>

<form id="journal_form" class="acc-journal app-form" action="<?= esc(base_url('accounting_journals'), 'attr') ?>" method="post" novalidate>
    <div class="acc-journal-bar print_hide">
        <button type="button" id="save_draft" class="btn btn-outline-secondary btn-sm"><span class="bi bi-save" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.save_draft') ?></button>
        <?php if ($can_post): ?>
            <button type="button" id="post_journal" class="btn btn-primary btn-sm" disabled><span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.post') ?></button>
            <button type="button" id="post_and_new" class="btn btn-outline-primary btn-sm" disabled><?= lang('Accounting_journals.post_and_new') ?></button>
        <?php endif; ?>
        <?php if (! $isNew): ?>
            <button type="button" id="delete_draft" class="btn btn-outline-danger btn-sm"><span class="bi bi-trash" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.delete_draft') ?></button>
        <?php endif; ?>
        <a class="btn btn-link btn-sm" href="<?= esc(base_url('accounting_journals'), 'attr') ?>"><?= lang('Accounting_journals.cancel') ?></a>
    </div>

    <ul id="error_message_box" class="error_message_box" role="alert"></ul>

    <div class="acc-journal-head form-grid">
        <div class="field">
            <label class="form-label text-danger required" for="journal_date"><?= lang('Accounting_journals.date') ?></label>
            <div class="field-control">
                <input type="date" name="date" id="journal_date" class="form-control form-control-sm" value="<?= esc($isNew ? date('Y-m-d') : $journal->journal_date, 'attr') ?>" required>
            </div>
            <p class="form-text" id="closed_hint" role="note"></p>
        </div>
        <?php if ($branches !== []): ?>
            <div class="field">
                <label class="form-label" for="journal_branch"><?= lang('Accounting_journals.branch') ?></label>
                <div class="field-control">
                    <select name="branch_id" id="journal_branch" class="form-select form-select-sm">
                        <?php foreach ($branches as $branch): ?>
                            <option value="<?= (int) $branch->branch_id ?>"<?= $branch_id === (int) $branch->branch_id ? ' selected' : '' ?>><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        <?php endif; ?>
        <div class="field">
            <span class="form-label"><?= lang('Accounting_journals.number') ?></span>
            <div class="field-control">
                <span class="form-control-plaintext"><bdi dir="ltr"><?= $isNew || $journal->number === null ? esc(lang('Accounting_journals.number_auto')) : esc($journal->number) ?></bdi></span>
            </div>
        </div>
        <div class="field">
            <span class="form-label"><?= lang('Accounting_journals.currency') ?></span>
            <div class="field-control">
                <span class="form-control-plaintext"><bdi dir="ltr"><?= esc($currency) ?></bdi></span>
            </div>
        </div>
        <div class="field span-all">
            <label class="form-label" for="journal_description"><?= lang('Accounting_journals.description') ?></label>
            <div class="field-control">
                <textarea name="description" id="journal_description" class="form-control form-control-sm" rows="2"><?= esc($isNew ? '' : (string) $journal->description) ?></textarea>
            </div>
        </div>
    </div>

    <div class="acc-lines-wrap">
        <table class="table acc-lines" id="lines_table">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Accounting_journals.account') ?></th>
                    <th scope="col"><?= lang('Accounting_journals.line_description') ?><?php if ($lineDescriptionRequired): ?> <span class="text-danger" aria-hidden="true">*</span><?php endif; ?></th>
                    <th scope="col"><?= lang('Accounting_journals.cost_center') ?></th>
                    <th scope="col" class="acc-amount"><?= lang('Accounting_journals.debit') ?></th>
                    <th scope="col" class="acc-amount"><?= lang('Accounting_journals.credit') ?></th>
                    <th scope="col"><span class="visually-hidden"><?= lang('Accounting_journals.remove_line') ?></span></th>
                </tr>
            </thead>
            <tbody id="lines_body">
                <?php foreach ($rows as $index => $line): ?>
                    <tr class="acc-line">
                        <td data-label="<?= esc(lang('Accounting_journals.account'), 'attr') ?>">
                            <?= $accountInputs("lines[{$index}][account_id]", $line === null ? null : (int) $line->account_id) ?>
                        </td>
                        <td data-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>">
                            <input type="text" name="lines[<?= $index ?>][description]" class="form-control form-control-sm acc-line-text" maxlength="255"<?= $lineDescriptionRequired ? ' aria-required="true"' : '' ?> value="<?= esc($line === null ? '' : (string) $line->description, 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>">
                            <?= view('accounting_journals/line_more', ['name' => "lines[{$index}]", 'analysis' => $line?->analysis, 'settings' => $settings]) ?>
                        </td>
                        <td data-label="<?= esc(lang('Accounting_journals.cost_center'), 'attr') ?>">
                            <?= view('accounting_journals/line_charge', ['name' => "lines[{$index}]", 'analysis' => $line?->analysis, 'centers' => $cost_centers, 'labels' => $center_labels, 'amount' => null]) ?>
                        </td>
                        <td data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>">
                            <input type="text" name="lines[<?= $index ?>][debit]" class="form-control form-control-sm ltr-input acc-debit" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount($line?->debit), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>">
                        </td>
                        <td data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>">
                            <input type="text" name="lines[<?= $index ?>][credit]" class="form-control form-control-sm ltr-input acc-credit" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount($line?->credit), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>">
                        </td>
                        <td class="acc-line-actions">
                            <button type="button" class="btn btn-outline-danger btn-sm acc-line-remove" title="<?= esc(lang('Accounting_journals.remove_line'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.remove_line'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <button type="button" id="add_line" class="btn btn-outline-primary btn-sm print_hide"><span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.add_line') ?></button>
    </div>

    <div class="acc-totals" role="status" aria-live="polite">
        <div><span class="acc-totals-label"><?= lang('Accounting_journals.total_debit') ?></span> <bdi dir="ltr" id="total_debit">0.00</bdi></div>
        <div><span class="acc-totals-label"><?= lang('Accounting_journals.total_credit') ?></span> <bdi dir="ltr" id="total_credit">0.00</bdi></div>
        <div id="difference_box" class="acc-difference"><span class="acc-totals-label"><?= lang('Accounting_journals.difference') ?></span> <bdi dir="ltr" id="difference">0.00</bdi> <span id="balance_state"></span></div>
    </div>
    <?php if ($lineDescriptionRequired): ?>
        <p class="form-text" id="line_text_hint" role="note"><?= lang('Accounting_journals.line_description_required_hint') ?></p>
    <?php endif; ?>
</form>

<?= view('accounting_journals/attachments', ['journalId' => $isNew ? 0 : $journalId, 'attachments' => $attachments, 'canAttach' => $canAttach]) ?>

<?= view('accounting_journals/distribution_script', ['centers' => $cost_centers, 'decimals' => $decimals, 'overAllowed' => $settings['allow_over_100'], 'cap' => $cap]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        const journalId = <?= $journalId ?>;
        const text = <?= json_encode([
            'balanced' => lang('Accounting_journals.balanced'),
            'unbalanced' => lang('Accounting_journals.unbalanced'),
            'confirm_delete' => lang('Accounting_journals.confirm_delete_draft'),
            'failed' => lang('Accounting_journals.request_failed'),
            'closed' => lang('Accounting_journals.closed_until'),
        ], $jsonFlags) ?>;
        const cutoffs = <?= json_encode($cutoffs, $jsonFlags) ?>;
        const textRequired = <?= $lineDescriptionRequired ? 'true' : 'false' ?>;
        const branchId = <?= (int) $branch_id ?>;
        const SCALE = 10000;
        const picker = accounting_support.accountPicker('accounts_list');
        const defaults = <?= json_encode($defaults, $jsonFlags) ?>;

        const applyCutoff = function() {
            const cutoff = cutoffs[$('#journal_branch').val() || branchId];
            const $date = $('#journal_date');

            if (cutoff) {
                const next = new Date(cutoff.date + 'T00:00:00Z');
                next.setUTCDate(next.getUTCDate() + 1);
                $date.attr('min', next.toISOString().slice(0, 10));
                $('#closed_hint').text(text.closed.replace('{0}', cutoff.label));
            } else {
                $date.removeAttr('min');
                $('#closed_hint').text('');
            }
        };

        $('#journal_branch').on('change', applyCutoff);
        applyCutoff();
        const $body = $('#lines_body');
        const template = $('#line_template').html();
        let nextIndex = $body.children().length;
        let busy = false;

        const digits = {'٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9', '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9'};

        const normalize = function(value) {
            return value.replace(/[٠-٩۰-۹]/g, function(character) {
                return digits[character];
            }).replace(/[٫,،]/g, '.');
        };

        const units = function(value) {
            const number = parseFloat(normalize(value));
            return isNaN(number) ? 0 : Math.round(number * SCALE);
        };

        const format = function(amount) {
            const sign = amount < 0 ? '-' : '';
            const fixed = (Math.abs(amount) / SCALE).toFixed(4).replace(/0{1,2}$/, '');
            return sign + fixed;
        };

        const refreshTotals = function() {
            let debit = 0;
            let credit = 0;
            let amounts = 0;
            let withoutAccount = 0;
            let withoutText = 0;

            $body.children().each(function() {
                const rowDebit = units($(this).find('.acc-debit').val());
                const rowCredit = units($(this).find('.acc-credit').val());
                debit += rowDebit;
                credit += rowCredit;
                if (rowDebit > 0 || rowCredit > 0) {
                    amounts++;
                    withoutAccount += $(this).find('input.acc-account').val() === '' ? 1 : 0;
                    withoutText += textRequired && $.trim($(this).find('.acc-line-text').val()) === '' ? 1 : 0;
                }
            });

            const difference = debit - credit;
            const balanced = difference === 0 && debit > 0 && amounts >= 2;
            const ready = balanced && withoutAccount === 0 && withoutText === 0;

            $('#total_debit').text(format(debit));
            $('#total_credit').text(format(credit));
            $('#difference').text(format(difference));
            $('#balance_state').text(balanced ? text.balanced : text.unbalanced);
            $('#difference_box').toggleClass('is-balanced', balanced).toggleClass('is-unbalanced', !balanced);
            $('#post_journal, #post_and_new').prop('disabled', !ready || busy);
            journal_distribution.refreshAll($body);
        };

        journal_distribution.bind($body, function($dist) {
            const $row = $dist.closest('tr');

            return BigInt(Math.max(units($row.find('.acc-debit').val()), units($row.find('.acc-credit').val())));
        });

        const applyDefaults = function($row) {
            const rows = defaults[$row.find('input.acc-account').val()];
            const $dist = $row.find('.acc-dist');

            if (rows && !journal_distribution.hasRows($dist) && journal_distribution.single($dist) === '') {
                journal_distribution.fill($dist, rows);
            }
        };

        const addLine = function() {
            const $row = $(template.replace(/__INDEX__/g, nextIndex++));
            $body.append($row);
            journal_distribution.init($row.find('.acc-dist'));
            return $row;
        };

        $('#add_line').on('click', function() {
            addLine().find('.acc-account-text').trigger('focus');
        });

        $body.on('click', '.acc-line-remove', function() {
            const $row = $(this).closest('tr');

            if ($body.children().length > 2) {
                $row.remove();
            } else {
                $row.find('input').not('[type=hidden], [type=checkbox]').add($row.find('input.acc-account')).add($row.find('select')).val('').removeClass('is-invalid');
                $row.find('input[type=checkbox]').prop('checked', false);
                $row.find('.acc-dist').attr('data-rows', '[]');
                journal_distribution.init($row.find('.acc-dist'));
            }
            refreshTotals();
        });

        $body.on('input change', '.acc-account-text', function() {
            picker.sync($(this), $(this).closest('td').find('.acc-account'));
            refreshTotals();
        });

        $body.on('blur', '.acc-account-text', function() {
            picker.complete($(this), $(this).closest('td').find('.acc-account'));
            applyDefaults($(this).closest('tr'));
            refreshTotals();
        });

        $body.on('input', '.acc-line-text', refreshTotals);

        $body.on('input', '.acc-debit, .acc-credit', function() {
            const normalized = normalize($(this).val());
            if (normalized !== $(this).val()) {
                $(this).val(normalized);
            }
            if (units(normalized) > 0) {
                $(this).closest('tr').find($(this).hasClass('acc-debit') ? '.acc-credit' : '.acc-debit').val('');
            }
            refreshTotals();
        });

        const submit = function(url, andNew) {
            if (busy) {
                return;
            }

            busy = true;
            $('#journal_form button').prop('disabled', true);
            $('#error_message_box').empty();

            $.post(url, $('#journal_form').serialize(), function(response) {
                if (response.success) {
                    window.location.href = andNew ? response.new_url : response.redirect;
                    return;
                }
                $('#error_message_box').append($('<li></li>').text(response.message));
                toast_support.show(response.message, 'danger');
            }, 'json').fail(function() {
                $('#error_message_box').append($('<li></li>').text(text.failed));
                toast_support.show(text.failed, 'danger');
            }).always(function() {
                busy = false;
                $('#journal_form button').prop('disabled', false);
                refreshTotals();
            });
        };

        const saveUrl = 'accounting_journals/save_draft' + (journalId > 0 ? '/' + journalId : '');
        const postUrl = 'accounting_journals/post' + (journalId > 0 ? '/' + journalId : '');

        $('#save_draft').on('click', function() {
            submit(saveUrl, false);
        });

        $('#post_journal').on('click', function() {
            submit(postUrl, false);
        });

        $('#post_and_new').on('click', function() {
            submit(postUrl, true);
        });

        $('#delete_draft').on('click', function() {
            if (confirm(text.confirm_delete)) {
                $.post('accounting_journals/delete_draft/' + journalId, {}, function(response) {
                    if (response.success) {
                        window.location.href = response.redirect;
                    } else {
                        toast_support.show(response.message, 'danger');
                    }
                }, 'json');
            }
        });

        $(document).on('keydown', function(event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter' && !$('#post_journal').prop('disabled')) {
                event.preventDefault();
                $('#post_journal').trigger('click');
            }
        });

        $('#journal_form').on('submit', function(event) {
            event.preventDefault();
        });

        refreshTotals();
    });
</script>

<?= view('partial/footer') ?>
