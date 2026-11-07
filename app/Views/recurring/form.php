<?php

use App\Libraries\Recurring\RecurrenceTerms;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\ScheduleInput;

/**
 * @var RecurringKind              $kind
 * @var object|null                $schedule
 * @var list<array<string, mixed>> $lines
 * @var list<object>               $reminders
 * @var string                     $frequency
 * @var array<int, string>         $locations
 * @var array<int, string>         $categories
 * @var list<string>               $payments
 * @var array<int, string>         $suppliers
 * @var array<int, string>         $treasuries
 * @var string                     $today
 */
$module     = $kind->moduleId();
$invoice    = $kind === RecurringKind::Invoice;
$isNew      = $schedule === null;
$locked     = ! $isNew && (int) $schedule->next_occurrence > 0;
$value      = static fn (string $field, string $default = ''): string => $schedule === null ? $default : (string) ($schedule->{$field} ?? $default);
$units      = ['days', 'weeks', 'months', 'years'];
$action     = $isNew ? $module . '/save' : $module . '/save/' . (int) $schedule->schedule_id;
$locationId = $isNew ? (int) array_key_first($locations) : (int) ($lines[0]['item_location'] ?? array_key_first($locations));
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang($isNew ? 'Recurring.new_' . $kind->value : 'Recurring.edit') ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($module), 'attr') ?>"><?= lang('Recurring.back') ?></a>
</div>

<ul id="error_message_box" class="error_message_box"></ul>

<?= form_open($action, ['id' => 'schedule_form', 'class' => 'app-form']) ?>
    <p class="small text-body-secondary"><?= lang('Recurring.form_note_' . $kind->value) ?></p>
    <?php if ($locked): ?>
        <div class="alert alert-info" id="schedule_locked"><?= lang('Recurring.terms_locked_note') ?></div>
    <?php endif; ?>

    <div class="form-grid">
        <div class="field span-all">
            <label class="form-label text-danger required" for="name"><?= lang('Recurring.name') ?></label>
            <div class="field-control"><input type="text" name="name" id="name" class="form-control form-control-sm" maxlength="255" value="<?= esc($value('name'), 'attr') ?>"></div>
        </div>

        <?php if ($isNew): ?>
            <div class="field">
                <label class="form-label" for="active"><?= lang('Recurring.active') ?></label>
                <div class="field-control">
                    <input type="hidden" name="active" value="0">
                    <input type="checkbox" class="form-check-input" name="active" id="active" value="1" checked>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($invoice): ?>
            <div class="field">
                <label class="form-label text-danger required" for="period_count"><?= lang('Recurring.period_count') ?></label>
                <div class="field-control">
                    <?php if ($locked): ?>
                        <span class="form-control-plaintext"><?= esc($value('period_count')) ?></span>
                        <input type="hidden" name="period_count" value="<?= esc($value('period_count'), 'attr') ?>">
                    <?php else: ?>
                        <input type="text" name="period_count" id="period_count" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="numeric" value="<?= esc($value('period_count', '1'), 'attr') ?>">
                    <?php endif; ?>
                </div>
            </div>

            <div class="field">
                <label class="form-label text-danger required" for="period_unit"><?= lang('Recurring.period_unit') ?></label>
                <div class="field-control">
                    <?php if ($locked): ?>
                        <span class="form-control-plaintext"><?= lang('Recurring.unit_' . $value('period_unit')) ?></span>
                        <input type="hidden" name="period_unit" value="<?= esc($value('period_unit'), 'attr') ?>">
                    <?php else: ?>
                        <select name="period_unit" id="period_unit" class="form-select form-select-sm">
                            <?php foreach ($units as $unit): ?>
                                <option value="<?= $unit ?>"<?= $value('period_unit', 'months') === $unit ? ' selected' : '' ?>><?= lang('Recurring.unit_' . $unit) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="field">
                <label class="form-label text-danger required" for="frequency"><?= lang('Recurring.frequency') ?></label>
                <div class="field-control">
                    <?php if ($locked): ?>
                        <span class="form-control-plaintext"><?= lang('Recurring.frequency_' . $frequency) ?></span>
                        <input type="hidden" name="frequency" value="<?= esc($frequency, 'attr') ?>">
                    <?php else: ?>
                        <select name="frequency" id="frequency" class="form-select form-select-sm">
                            <?php foreach (array_keys(RecurrenceTerms::EXPENSE_FREQUENCIES) as $name): ?>
                                <option value="<?= esc($name, 'attr') ?>"<?= $frequency === $name ? ' selected' : '' ?>><?= lang('Recurring.frequency_' . $name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="field">
            <label class="form-label text-danger required" for="start_date"><?= lang('Recurring.start_date') ?></label>
            <div class="field-control">
                <?php if ($locked): ?>
                    <span class="form-control-plaintext"><bdi dir="ltr"><?= esc($value('start_date')) ?></bdi></span>
                    <input type="hidden" name="start_date" value="<?= esc($value('start_date'), 'attr') ?>">
                <?php else: ?>
                    <input type="date" name="start_date" id="start_date" class="form-control form-control-sm ltr-input" dir="ltr" min="<?= esc($today, 'attr') ?>" value="<?= esc($value('start_date', $today), 'attr') ?>">
                <?php endif; ?>
            </div>
        </div>

        <div class="field">
            <label class="form-label" for="end_date"><?= lang('Recurring.end_date') ?></label>
            <div class="field-control"><input type="date" name="end_date" id="end_date" class="form-control form-control-sm ltr-input" dir="ltr" value="<?= esc($value('end_date'), 'attr') ?>"></div>
        </div>

        <div class="field">
            <label class="form-label" for="max_repeats"><?= lang('Recurring.max_repeats') ?></label>
            <div class="field-control">
                <input type="text" name="max_repeats" id="max_repeats" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="numeric" value="<?= esc($value('max_repeats', '0'), 'attr') ?>">
                <div class="form-text"><?= lang('Recurring.max_repeats_note') ?></div>
            </div>
        </div>

        <?php if ($invoice): ?>
            <div class="field">
                <label class="form-label" for="issue_before_days"><?= lang('Recurring.issue_before_days') ?></label>
                <div class="field-control">
                    <input type="text" name="issue_before_days" id="issue_before_days" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="numeric" value="<?= esc($value('issue_before_days', '0'), 'attr') ?>">
                    <div class="form-text"><?= lang('Recurring.issue_before_note') ?></div>
                </div>
            </div>

            <div class="field">
                <label class="form-label text-danger required" for="customer_name"><?= lang('Recurring.customer') ?></label>
                <div class="field-control">
                    <input type="text" id="customer_name" class="form-control form-control-sm" autocomplete="off" value="<?= esc($isNew ? '' : (string) $schedule->customer_name, 'attr') ?>">
                    <input type="hidden" name="customer_id" id="customer_id" value="<?= esc($value('customer_id'), 'attr') ?>">
                </div>
            </div>

            <div class="field">
                <label class="form-label text-danger required" for="location_id"><?= lang('Sales_orders.location') ?></label>
                <div class="field-control">
                    <select name="location_id" id="location_id" class="form-select form-select-sm">
                        <?php foreach ($locations as $id => $name): ?>
                            <option value="<?= (int) $id ?>"<?= (int) $id === $locationId ? ' selected' : '' ?>><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label class="form-label" for="payment_terms_days"><?= lang('Recurring.payment_terms') ?></label>
                <div class="field-control"><input type="text" name="payment_terms_days" id="payment_terms_days" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="numeric" value="<?= esc($value('payment_terms_days'), 'attr') ?>"></div>
            </div>

            <div class="field">
                <label class="form-label" for="issue_mode"><?= lang('Recurring.issue_mode') ?></label>
                <div class="field-control">
                    <select name="issue_mode" id="issue_mode" class="form-select form-select-sm">
                        <option value="draft"<?= $value('issue_mode', 'draft') === 'draft' ? ' selected' : '' ?>><?= lang('Recurring.issue_mode_draft') ?></option>
                        <option value="issue"<?= $value('issue_mode', 'draft') === 'issue' ? ' selected' : '' ?>><?= lang('Recurring.issue_mode_issue') ?></option>
                    </select>
                    <div class="form-text"><?= lang('Recurring.issue_mode_note') ?></div>
                </div>
            </div>
        <?php else: ?>
            <div class="field">
                <label class="form-label text-danger required" for="expense_category_id"><?= lang('Recurring.category') ?></label>
                <div class="field-control">
                    <select name="expense_category_id" id="expense_category_id" class="form-select form-select-sm">
                        <?php foreach ($categories as $id => $name): ?>
                            <option value="<?= (int) $id ?>"<?= (int) $value('expense_category_id', '0') === (int) $id ? ' selected' : '' ?>><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label class="form-label text-danger required" for="amount"><?= lang('Recurring.amount') ?></label>
                <div class="field-control"><input type="text" name="amount" id="amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($value('amount'), 'attr') ?>"></div>
            </div>

            <div class="field">
                <label class="form-label" for="tax_amount"><?= lang('Recurring.tax_amount') ?></label>
                <div class="field-control"><input type="text" name="tax_amount" id="tax_amount" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="decimal" autocomplete="off" value="<?= esc($value('tax_amount', '0'), 'attr') ?>"></div>
            </div>

            <div class="field">
                <label class="form-label text-danger required" for="payment_type"><?= lang('Recurring.payment_type') ?></label>
                <div class="field-control">
                    <select name="payment_type" id="payment_type" class="form-select form-select-sm">
                        <?php foreach ($payments as $payment): ?>
                            <option value="<?= esc($payment, 'attr') ?>"<?= $value('payment_type') === $payment ? ' selected' : '' ?>><?= esc($payment) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if ($treasuries !== []): ?>
                <div class="field">
                    <label class="form-label" for="treasury_id"><?= lang('Recurring.treasury') ?></label>
                    <div class="field-control">
                        <select name="treasury_id" id="treasury_id" class="form-select form-select-sm">
                            <option value=""><?= lang('Recurring.treasury_default') ?></option>
                            <?php foreach ($treasuries as $id => $name): ?>
                                <option value="<?= (int) $id ?>"<?= (int) $value('treasury_id', '0') === (int) $id ? ' selected' : '' ?>><?= esc($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php endif; ?>

            <div class="field">
                <label class="form-label" for="supplier_id"><?= lang('Recurring.supplier') ?></label>
                <div class="field-control">
                    <select name="supplier_id" id="supplier_id" class="form-select form-select-sm">
                        <option value=""><?= lang('Recurring.no_supplier') ?></option>
                        <?php foreach ($suppliers as $id => $name): ?>
                            <option value="<?= (int) $id ?>"<?= (int) $value('supplier_id', '0') === (int) $id ? ' selected' : '' ?>><?= esc($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field">
                <label class="form-label" for="supplier_tax_code"><?= lang('Recurring.supplier_tax_code') ?></label>
                <div class="field-control"><input type="text" name="supplier_tax_code" id="supplier_tax_code" class="form-control form-control-sm" maxlength="255" value="<?= esc($value('supplier_tax_code'), 'attr') ?>"></div>
            </div>
        <?php endif; ?>

        <div class="field span-all">
            <label class="form-label" for="comment"><?= lang('Recurring.comment') ?></label>
            <div class="field-control"><textarea name="comment" id="comment" class="form-control form-control-sm" rows="2" maxlength="500"><?= esc($value('comment')) ?></textarea></div>
        </div>
    </div>

    <?php if ($invoice): ?>
        <?= view('partial/document_lines', ['rows' => $lines, 'items_url' => $module . '/items']) ?>

        <h2 class="h6 mt-3"><?= lang('Recurring.reminders') ?></h2>
        <p class="small text-body-secondary"><?= lang('Recurring.reminders_note') ?></p>
        <div id="reminder_rows"></div>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="add_reminder"><?= lang('Recurring.add_reminder') ?></button>
    <?php endif; ?>

    <div class="mt-3">
        <button type="submit" class="btn btn-primary btn-sm" id="submit_schedule"><?= lang('Common.submit') ?></button>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        <?php if ($invoice): ?>
        const reminderOptions = [
            [0, "<?= esc(lang('Recurring.reminder_when_0'), 'js') ?>"],
            [1, "<?= esc(lang('Recurring.reminder_when_1'), 'js') ?>"],
            [2, "<?= esc(lang('Recurring.reminder_when_2'), 'js') ?>"],
            [3, "<?= esc(lang('Recurring.reminder_choice_3'), 'js') ?>"],
            [4, "<?= esc(lang('Recurring.reminder_choice_4'), 'js') ?>"]
        ];
        const existing = <?= json_encode(array_map(static fn (object $reminder): array => ['send_when' => (int) $reminder->send_when, 'days' => $reminder->days === null ? '' : (string) $reminder->days], $reminders), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const maxReminders = <?= ScheduleInput::MAX_REMINDERS ?>;
        let nextReminder = 0;

        const addReminder = function(data) {
            if ($('#reminder_rows .reminder-row').length >= maxReminders) {
                return;
            }

            const index = nextReminder++;
            const select = $('<select class="form-select form-select-sm"></select>').attr('name', 'reminders[' + index + '][send_when]');
            reminderOptions.forEach(function(option) {
                select.append($('<option></option>').val(option[0]).text(option[1]));
            });
            select.val(String(data.send_when === undefined ? 1 : data.send_when));
            const days = $('<input type="text" class="form-control form-control-sm ltr-input" dir="ltr" inputmode="numeric" autocomplete="off">').attr('name', 'reminders[' + index + '][days]').val(data.days || '');
            const remove = $('<button type="button" class="btn btn-outline-danger btn-sm remove-reminder"></button>').text("<?= esc(lang('Sales_orders.remove_line'), 'js') ?>");
            const toggle = function() {
                days.toggle(parseInt(select.val(), 10) >= 3);
            };

            select.on('change', toggle);
            toggle();
            $('#reminder_rows').append($('<div class="reminder-row d-flex gap-2 mb-2"></div>').append(select, days, remove));
        };

        existing.forEach(addReminder);

        $('#add_reminder').on('click', function() {
            addReminder({});
        });

        $(document).on('click', '.remove-reminder', function() {
            $(this).closest('.reminder-row').remove();
        });

        $('#customer_name').autocomplete({
            source: '<?= esc($module, 'js') ?>/customers',
            minChars: 0,
            delay: 100,
            select: function(event, ui) {
                $('#customer_id').val(ui.item.value);
                $('#customer_name').val(ui.item.label);

                return false;
            },
            focus: function(event, ui) {
                $('#customer_name').val(ui.item.label);

                return false;
            }
        }).on('input', function() {
            $('#customer_id').val('');
        });
        <?php endif; ?>

        $('#schedule_form').on('submit', function(event) {
            event.preventDefault();
            $('#submit_schedule').prop('disabled', true);
            $(this).ajaxSubmit({
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        window.location.href = response.redirect;
                    } else {
                        $('#submit_schedule').prop('disabled', false);
                    }
                },
                error: function() {
                    $('#submit_schedule').prop('disabled', false);
                }
            });
        });
    });
</script>

<?= view('partial/footer') ?>
