<?php
/**
 * @var list<object> $branches
 * @var int $branch_id
 * @var list<object> $accounts
 * @var array{start_date: ?string, posted: ?object, draft: list<array{account_id: int, description: string, debit: string, credit: string}>|null} $state
 * @var bool $can_manage
 * @var string $start_date
 * @var bool $show_branch
 * @var list<array<string, mixed>> $stock
 * @var string|null $notice
 */

$posted    = $state['posted'];
$draft     = $state['draft'];
$editable  = $can_manage && $posted === null && $state['start_date'] !== null;
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$label     = static fn (object $account): string => $account->code . ' - ' . $account->name;
$labels    = [];

foreach ($accounts as $account) {
    $labels[(int) $account->account_id] = $label($account);
}

$amount = static fn (?string $value): string => $value === null || bccomp($value, '0', 4) === 0 ? '' : rtrim(rtrim($value, '0'), '.');
$rows   = $draft === null || $draft === [] ? [null, null] : $draft;

$accountInputs = static function (string $name, ?int $selected) use ($labels): string {
    $text = $selected !== null && isset($labels[$selected]) ? $labels[$selected] : '';

    return '<input type="text" class="form-control form-control-sm acc-account-text" list="accounts_list" autocomplete="off" value="' . esc($text, 'attr') . '" placeholder="' . esc(lang('Accounting_journals.choose_account'), 'attr') . '" aria-label="' . esc(lang('Accounting_journals.account'), 'attr') . '">'
        . '<input type="hidden" name="' . esc($name, 'attr') . '" class="acc-account" value="' . ($selected === null ? '' : $selected) . '">';
};
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<?= view('accounting_periods/tabs', ['active' => 'opening']) ?>

<?php if (! $can_manage): ?>
    <p class="text-body-secondary small" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_periods.permission_note') ?></p>
<?php endif; ?>

<?php if ($show_branch): ?>
    <form class="acc-filters print_hide" method="get" action="<?= esc(base_url('accounting_periods/opening'), 'attr') ?>">
        <div class="acc-filter-row">
            <div class="acc-filter">
                <label class="form-label" for="branch"><?= lang('Accounting_journals.branch') ?></label>
                <select name="branch" id="branch" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($branches as $branch): ?>
                        <option value="<?= (int) $branch->branch_id ?>"<?= (int) $branch->branch_id === $branch_id ? ' selected' : '' ?>><?= esc($branch->code . ' - ' . $branch->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php if ($state['start_date'] === null): ?>
    <p class="alert alert-warning" role="alert"><?= lang('Accounting_periods.opening_no_start') ?></p>
<?php else: ?>
    <p class="text-body-secondary"><?= esc(lang('Accounting_periods.opening_intro', [$start_date])) ?></p>
<?php endif; ?>

<?php if ($posted !== null): ?>
    <section class="acc-journal-view" aria-label="<?= esc(lang('Accounting_periods.opening_posted_title'), 'attr') ?>">
        <p><span class="badge text-bg-success"><?= lang('Accounting.state_posted') ?></span>
            <a href="<?= esc(base_url('accounting_journals/view/' . (int) $posted->journal_id), 'attr') ?>"><?= esc(lang('Accounting_periods.opening_view_journal')) ?></a></p>
        <div class="acc-lines-wrap">
            <table class="table acc-lines">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_journals.account') ?></th>
                        <th scope="col"><?= lang('Accounting_journals.line_description') ?></th>
                        <th scope="col" class="acc-amount"><?= lang('Accounting_journals.debit') ?></th>
                        <th scope="col" class="acc-amount"><?= lang('Accounting_journals.credit') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posted->lines as $line): ?>
                        <tr>
                            <td data-label="<?= esc(lang('Accounting_journals.account'), 'attr') ?>"><bdi dir="ltr"><?= esc($line->account_code) ?></bdi> <?= esc($line->account_name) ?></td>
                            <td data-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>"><?= esc((string) $line->description) ?></td>
                            <td data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc(to_currency_no_money($line->debit)) ?></bdi></td>
                            <td data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>" class="acc-amount"><bdi dir="ltr"><?= esc(to_currency_no_money($line->credit)) ?></bdi></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($can_manage): ?>
            <button type="button" class="btn btn-outline-danger btn-sm modal-dlg" data-btn-submit="<?= esc(lang('Accounting_journals.reverse'), 'attr') ?>" data-href="<?= esc('accounting_periods/opening/reverse_form?branch=' . $branch_id, 'attr') ?>" title="<?= esc(lang('Accounting_periods.opening_reverse'), 'attr') ?>">
                <span class="bi bi-arrow-counterclockwise" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.opening_reverse') ?>
            </button>
        <?php endif; ?>
    </section>
<?php elseif ($state['start_date'] !== null): ?>
    <template id="line_template">
        <tr class="acc-line">
            <td data-label="<?= esc(lang('Accounting_journals.account'), 'attr') ?>"><?= $accountInputs('lines[__INDEX__][account_id]', null) ?></td>
            <td data-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>"><input type="text" name="lines[__INDEX__][description]" class="form-control form-control-sm" maxlength="255" aria-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>"></td>
            <td data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>"><input type="text" name="lines[__INDEX__][debit]" class="form-control form-control-sm ltr-input acc-debit" dir="ltr" inputmode="decimal" autocomplete="off" aria-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>"></td>
            <td data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>"><input type="text" name="lines[__INDEX__][credit]" class="form-control form-control-sm ltr-input acc-credit" dir="ltr" inputmode="decimal" autocomplete="off" aria-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>"></td>
            <td class="acc-line-actions"><button type="button" class="btn btn-outline-danger btn-sm acc-line-remove" title="<?= esc(lang('Accounting_journals.remove_line'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.remove_line'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button></td>
        </tr>
    </template>

    <datalist id="accounts_list">
        <?php foreach ($accounts as $account): ?>
            <option value="<?= esc($label($account), 'attr') ?>" data-id="<?= (int) $account->account_id ?>" data-code="<?= esc($account->code, 'attr') ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <form id="opening_form" class="acc-journal app-form acc-opening-grid" method="post" novalidate>
        <input type="hidden" name="branch_id" value="<?= $branch_id ?>">
        <?php if ($editable): ?>
            <div class="acc-journal-bar print_hide">
                <button type="button" id="save_draft" class="btn btn-outline-secondary btn-sm"><span class="bi bi-save" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.save_draft') ?></button>
                <button type="button" id="post_opening" class="btn btn-primary btn-sm" disabled><span class="bi bi-check2-circle" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.post') ?></button>
                <button type="button" id="post_balance" class="btn btn-outline-primary btn-sm" disabled><?= lang('Accounting_periods.opening_post_balance') ?></button>
                <?php if ($draft !== null): ?>
                    <button type="button" id="discard_draft" class="btn btn-outline-danger btn-sm"><span class="bi bi-trash" aria-hidden="true">&nbsp;</span><?= lang('Accounting_periods.opening_discard') ?></button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <ul id="error_message_box" class="error_message_box" role="alert"></ul>

        <div class="acc-lines-wrap">
            <table class="table acc-lines" id="lines_table">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_journals.account') ?></th>
                        <th scope="col"><?= lang('Accounting_journals.line_description') ?></th>
                        <th scope="col" class="acc-amount"><?= lang('Accounting_journals.debit') ?></th>
                        <th scope="col" class="acc-amount"><?= lang('Accounting_journals.credit') ?></th>
                        <th scope="col"><span class="visually-hidden"><?= lang('Accounting_journals.remove_line') ?></span></th>
                    </tr>
                </thead>
                <tbody id="lines_body">
                    <?php foreach ($rows as $index => $line): ?>
                        <tr class="acc-line">
                            <td data-label="<?= esc(lang('Accounting_journals.account'), 'attr') ?>"><?= $accountInputs("lines[{$index}][account_id]", $line === null ? null : (int) $line['account_id']) ?></td>
                            <td data-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>"><input type="text" name="lines[<?= $index ?>][description]" class="form-control form-control-sm" maxlength="255" value="<?= esc($line === null ? '' : (string) $line['description'], 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.line_description'), 'attr') ?>"></td>
                            <td data-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>"><input type="text" name="lines[<?= $index ?>][debit]" class="form-control form-control-sm ltr-input acc-debit" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount($line['debit'] ?? null), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.debit'), 'attr') ?>"></td>
                            <td data-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>"><input type="text" name="lines[<?= $index ?>][credit]" class="form-control form-control-sm ltr-input acc-credit" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($amount($line['credit'] ?? null), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.credit'), 'attr') ?>"></td>
                            <td class="acc-line-actions"><button type="button" class="btn btn-outline-danger btn-sm acc-line-remove" title="<?= esc(lang('Accounting_journals.remove_line'), 'attr') ?>" aria-label="<?= esc(lang('Accounting_journals.remove_line'), 'attr') ?>"><span class="bi bi-trash" aria-hidden="true"></span></button></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($editable): ?>
                <button type="button" id="add_line" class="btn btn-outline-primary btn-sm print_hide"><span class="bi bi-plus-lg" aria-hidden="true">&nbsp;</span><?= lang('Accounting_journals.add_line') ?></button>
            <?php endif; ?>
        </div>

        <div class="acc-totals" role="status" aria-live="polite">
            <div><span class="acc-totals-label"><?= lang('Accounting_journals.total_debit') ?></span> <bdi dir="ltr" id="total_debit">0.00</bdi></div>
            <div><span class="acc-totals-label"><?= lang('Accounting_journals.total_credit') ?></span> <bdi dir="ltr" id="total_credit">0.00</bdi></div>
            <div id="difference_box" class="acc-difference"><span class="acc-totals-label"><?= lang('Accounting_journals.difference') ?></span> <bdi dir="ltr" id="difference">0.00</bdi> <span id="balance_state"></span></div>
        </div>
    </form>

    <script type="text/javascript">
        $(document).ready(function() {
            const editable = <?= $editable ? 'true' : 'false' ?>;
            const text = <?= json_encode([
                'balanced' => lang('Accounting_journals.balanced'),
                'unbalanced' => lang('Accounting_journals.unbalanced'),
                'confirm_discard' => lang('Accounting_periods.opening_confirm_discard'),
                'confirm_balance' => lang('Accounting_periods.opening_confirm_balance'),
                'failed' => lang('Accounting_journals.request_failed'),
            ], $jsonFlags) ?>;
            const SCALE = 10000;
            const picker = accounting_support.accountPicker('accounts_list');
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
                let withoutAccount = 0;
                let amounts = 0;

                $body.children().each(function() {
                    const rowDebit = units($(this).find('.acc-debit').val());
                    const rowCredit = units($(this).find('.acc-credit').val());
                    debit += rowDebit;
                    credit += rowCredit;
                    if (rowDebit > 0 || rowCredit > 0) {
                        amounts++;
                        withoutAccount += $(this).find('input.acc-account').val() === '' ? 1 : 0;
                    }
                });

                const difference = debit - credit;
                const balanced = difference === 0 && debit > 0;
                const ready = amounts > 0 && withoutAccount === 0;

                $('#total_debit').text(format(debit));
                $('#total_credit').text(format(credit));
                $('#difference').text(format(difference));
                $('#balance_state').text(balanced ? text.balanced : text.unbalanced);
                $('#difference_box').toggleClass('is-balanced', balanced).toggleClass('is-unbalanced', !balanced);
                $('#post_opening').prop('disabled', !(ready && balanced) || busy);
                $('#post_balance').prop('disabled', !(ready && !balanced) || busy);
            };

            const submit = function(url, extra) {
                if (busy) {
                    return;
                }

                busy = true;
                $('#error_message_box').empty();

                $.post(url, $('#opening_form').serialize() + (extra || ''), function(response) {
                    if (response.success) {
                        window.location.href = response.redirect;
                        return;
                    }
                    $('#error_message_box').append($('<li></li>').text(response.message));
                    toast_support.show(response.message, 'danger');
                }, 'json').fail(function() {
                    $('#error_message_box').append($('<li></li>').text(text.failed));
                }).always(function() {
                    busy = false;
                    refreshTotals();
                });
            };

            if (editable) {
                $('#add_line').on('click', function() {
                    const $row = $(template.replace(/__INDEX__/g, nextIndex++));
                    $body.append($row);
                    $row.find('.acc-account-text').trigger('focus');
                });

                $body.on('click', '.acc-line-remove', function() {
                    const $row = $(this).closest('tr');

                    if ($body.children().length > 2) {
                        $row.remove();
                    } else {
                        $row.find('input').val('').removeClass('is-invalid');
                    }
                    refreshTotals();
                });

                $body.on('input change', '.acc-account-text', function() {
                    picker.sync($(this), $(this).closest('td').find('.acc-account'));
                    refreshTotals();
                });

                $body.on('blur', '.acc-account-text', function() {
                    picker.complete($(this), $(this).closest('td').find('.acc-account'));
                    refreshTotals();
                });

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

                $('#save_draft').on('click', function() {
                    submit('accounting_periods/opening/save_draft');
                });

                $('#post_opening').on('click', function() {
                    submit('accounting_periods/opening/post');
                });

                $('#post_balance').on('click', function() {
                    if (confirm(text.confirm_balance)) {
                        submit('accounting_periods/opening/post', '&balance=1');
                    }
                });

                $('#discard_draft').on('click', function() {
                    if (confirm(text.confirm_discard)) {
                        submit('accounting_periods/opening/discard');
                    }
                });
            } else {
                $('#opening_form').find('input, button').prop('disabled', true);
            }

            $('#opening_form').on('submit', function(event) {
                event.preventDefault();
            });

            refreshTotals();
        });
    </script>
<?php endif; ?>

<?= view('accounting_periods/opening_stock', ['stock' => $stock, 'branch_id' => $branch_id, 'can_manage' => $can_manage, 'start_date' => $start_date, 'has_start' => $state['start_date'] !== null]) ?>

<?= view('partial/footer') ?>
