<?php

use App\Libraries\Recurring\RecurrencePhrase;
use App\Libraries\Recurring\RecurrenceTerms;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\ScheduleStatus;

/**
 * @var RecurringKind   $kind
 * @var object          $schedule
 * @var RecurrenceTerms $terms
 * @var list<object>    $lines
 * @var list<object>    $reminders
 * @var list<object>    $runs
 * @var list<string>    $upcoming
 * @var string          $category
 * @var string          $payment
 * @var bool            $canManage
 */
$module     = $kind->moduleId();
$invoice    = $kind === RecurringKind::Invoice;
$scheduleId = (int) $schedule->schedule_id;
$status     = ScheduleStatus::from((string) $schedule->status);
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar gap-2">
    <h1 class="h5 me-auto mb-0"><?= esc((string) $schedule->name) ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($module), 'attr') ?>"><?= lang('Recurring.back') ?></a>
    <?php if ($canManage && $status !== ScheduleStatus::Ended): ?>
        <a class="btn btn-outline-secondary btn-sm" id="schedule_edit" href="<?= esc(base_url($module . '/edit/' . $scheduleId), 'attr') ?>"><?= lang('Recurring.edit') ?></a>
        <?php foreach ($status->next() as $to): ?>
            <button type="button" class="btn btn-outline-<?= $to === ScheduleStatus::Ended ? 'danger' : 'primary' ?> btn-sm schedule-status" data-status="<?= esc($to->value, 'attr') ?>" data-confirm="<?= $to === ScheduleStatus::Ended ? '1' : '0' ?>"><?= lang('Recurring.move_to_' . $to->value) ?></button>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<dl class="row" id="schedule_header">
    <dt class="col-sm-3"><?= lang('Recurring.status') ?></dt>
    <dd class="col-sm-9"><span class="badge text-bg-primary" id="schedule_status"><?= lang($status->languageKey()) ?></span>
        <?php if ($schedule->ended_reason !== null): ?>
            <span class="text-body-secondary small">(<?= lang('Recurring.ended_' . $schedule->ended_reason) ?>)</span>
        <?php endif; ?>
    </dd>
    <?php if ($invoice): ?>
        <dt class="col-sm-3"><?= lang('Recurring.customer') ?></dt>
        <dd class="col-sm-9"><?= esc((string) $schedule->customer_name) ?></dd>
        <dt class="col-sm-3"><?= lang('Recurring.issue_mode') ?></dt>
        <dd class="col-sm-9"><?= lang('Recurring.issue_mode_' . $schedule->issue_mode) ?></dd>
        <dt class="col-sm-3"><?= lang('Recurring.payment_terms') ?></dt>
        <dd class="col-sm-9"><?= $schedule->payment_terms_days === null ? '-' : esc((string) $schedule->payment_terms_days) ?></dd>
    <?php else: ?>
        <dt class="col-sm-3"><?= lang('Recurring.category') ?></dt>
        <dd class="col-sm-9"><?= esc($category) ?></dd>
        <dt class="col-sm-3"><?= lang('Recurring.amount') ?></dt>
        <dd class="col-sm-9"><bdi dir="ltr"><?= to_currency($schedule->amount) ?></bdi> (<?= lang('Recurring.tax_amount') ?>: <bdi dir="ltr"><?= to_currency($schedule->tax_amount ?? '0') ?></bdi>)</dd>
        <dt class="col-sm-3"><?= lang('Recurring.payment_type') ?></dt>
        <dd class="col-sm-9"><?= esc($payment) ?></dd>
    <?php endif; ?>
    <dt class="col-sm-3"><?= lang('Recurring.period') ?></dt>
    <dd class="col-sm-9"><?= $terms->frequency() !== null && ! $invoice ? lang('Recurring.frequency_' . $terms->frequency()) : RecurrencePhrase::every($terms->periodCount, $terms->unit) ?></dd>
    <dt class="col-sm-3"><?= lang('Recurring.start_date') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc($terms->startDate) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Recurring.end_date') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc($terms->endDate ?? '-') ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Recurring.max_repeats') ?></dt>
    <dd class="col-sm-9"><?= $terms->maxRepeats === 0 ? lang('Recurring.unlimited') : $terms->maxRepeats ?></dd>
    <?php if ($invoice): ?>
        <dt class="col-sm-3"><?= lang('Recurring.issue_before_days') ?></dt>
        <dd class="col-sm-9"><?= $terms->issueBeforeDays ?></dd>
    <?php endif; ?>
    <dt class="col-sm-3"><?= lang('Recurring.branch') ?></dt>
    <dd class="col-sm-9"><bdi dir="ltr"><?= esc((string) $schedule->branch_code) ?></bdi></dd>
    <dt class="col-sm-3"><?= lang('Recurring.made_as') ?></dt>
    <dd class="col-sm-9"><?= esc((string) $schedule->employee_name) ?></dd>
    <dt class="col-sm-3"><?= lang('Recurring.upcoming') ?></dt>
    <dd class="col-sm-9" id="schedule_upcoming"><?= $upcoming === [] ? '-' : '<bdi dir="ltr">' . esc(implode(' , ', $upcoming)) . '</bdi>' ?></dd>
</dl>

<?php if ($invoice): ?>
    <h2 class="h6"><?= lang('Sales_orders.lines') ?></h2>
    <div class="table-responsive">
        <table class="table table-sm align-middle" id="schedule_lines">
            <thead>
                <tr>
                    <th scope="col"><?= lang('Sales_orders.item') ?></th>
                    <th scope="col" class="text-end"><?= lang('Sales.quantity') ?></th>
                    <th scope="col" class="text-end"><?= lang('Sales_orders.unit_price') ?></th>
                    <th scope="col" class="text-end"><?= lang('Sales_orders.discount') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lines as $line): ?>
                    <tr>
                        <td><?= esc((string) $line->item_name) ?></td>
                        <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->quantity) ?></bdi></td>
                        <td class="text-end"><bdi dir="ltr"><?= to_currency($line->unit_price) ?></bdi></td>
                        <td class="text-end"><bdi dir="ltr"><?= esc((string) $line->discount) ?><?= (int) $line->discount_type === 0 ? '%' : '' ?></bdi></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h2 class="h6"><?= lang('Recurring.reminders') ?></h2>
    <ul id="schedule_reminder_list">
        <?php if ($reminders === []): ?>
            <li class="text-body-secondary"><?= lang('Recurring.no_reminders') ?></li>
        <?php endif; ?>
        <?php foreach ($reminders as $reminder): ?>
            <li><?= esc(RecurrencePhrase::reminder((int) $reminder->send_when, $reminder->days === null ? null : (int) $reminder->days)) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h2 class="h6"><?= lang('Recurring.history') ?></h2>
<div class="table-responsive">
    <table class="table table-sm align-middle" id="schedule_history">
        <thead>
            <tr>
                <th scope="col"><?= lang('Recurring.due_date') ?></th>
                <th scope="col"><?= lang('Recurring.outcome') ?></th>
                <th scope="col"><?= lang('Recurring.document') ?></th>
                <th scope="col"><?= lang('Recurring.attempts') ?></th>
                <th scope="col"></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($runs === []): ?>
                <tr><td colspan="5" class="text-body-secondary"><?= lang('Recurring.no_history') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($runs as $run): ?>
                <tr id="run_<?= (int) $run->run_id ?>">
                    <td><bdi dir="ltr"><?= esc((string) $run->due_date) ?></bdi></td>
                    <td>
                        <?php if ($run->status === 'generated'): ?>
                            <span class="badge text-bg-success"><?= lang('Recurring.outcome_generated') ?></span>
                            <?php if ($run->generated_as !== null): ?>
                                <span class="text-body-secondary small"><?= lang('Recurring.issue_mode_' . $run->generated_as) ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge text-bg-danger"><?= lang('Recurring.outcome_refused') ?></span>
                            <span class="small"><?= esc((string) $run->refusal_words) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($run->sale_id !== null): ?>
                            <a href="<?= esc(base_url('sales/' . ((int) $run->sale_status === COMPLETED ? 'invoice' : 'document') . '/' . (int) $run->sale_id), 'attr') ?>"><bdi dir="ltr">POS <?= (int) $run->sale_id ?></bdi></a>
                        <?php elseif ($run->expense_id !== null): ?>
                            <a href="<?= esc(base_url('expenses'), 'attr') ?>"><bdi dir="ltr"><?= lang('Recurring.expense') ?> <?= (int) $run->expense_id ?></bdi></a>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $run->attempts ?></td>
                    <td>
                        <?php if ($canManage && $run->status === 'refused'): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm run-retry" data-run="<?= (int) $run->run_id ?>"><?= lang('Recurring.retry') ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        const post = function(url, data) {
            $.post(url, data, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.reload();
                }
            }, 'json').fail(function() {
                toast_support.show("<?= esc(lang('Common.request_failed'), 'js') ?>", 'danger');
            });
        };

        $('.schedule-status').on('click', function() {
            const status = $(this).data('status');

            if ($(this).data('confirm') !== 1) {
                post('<?= esc($module, 'js') ?>/status/<?= $scheduleId ?>', {status: status});
                return;
            }

            appConfirm("<?= esc(lang('Recurring.end_confirm'), 'js') ?>", {tone: 'danger'}).then(function(confirmed) {
                if (confirmed) {
                    post('<?= esc($module, 'js') ?>/status/<?= $scheduleId ?>', {status: status});
                }
            });
        });

        $('.run-retry').on('click', function() {
            post('<?= esc($module, 'js') ?>/retry/<?= $scheduleId ?>/' + $(this).data('run'), {});
        });
    });
</script>

<?= view('partial/footer') ?>
