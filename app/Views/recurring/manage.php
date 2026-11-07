<?php

use App\Libraries\Recurring\RecurrencePhrase;
use App\Libraries\Recurring\RecurrenceTerms;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\ScheduleStatus;

/**
 * @var RecurringKind                                                                                                   $kind
 * @var list<object>                                                                                                    $rows
 * @var int                                                                                                             $total
 * @var int                                                                                                             $offset
 * @var int                                                                                                             $pageSize
 * @var array{status: string, search: string}                                                                           $filters
 * @var list<ScheduleStatus>                                                                                            $statuses
 * @var list<array{schedule_id: int, name: string, send_when: int, days: int|null, date: string, invoice_date: string}> $reminders
 * @var bool                                                                                                            $canManage
 */
$module  = $kind->moduleId();
$invoice = $kind === RecurringKind::Invoice;
$query   = static fn (array $extra): string => http_build_query(array_filter([...$filters, ...$extra], static fn ($value): bool => $value !== '' && $value !== 0));
?>

<?= view('partial/header') ?>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Recurring.title_' . $kind->value) ?></h1>
    <?php if ($canManage): ?>
        <a class="btn btn-primary btn-sm" id="schedule_new" href="<?= esc(base_url($module . '/add'), 'attr') ?>">
            <span class="bi bi-plus-circle" aria-hidden="true">&nbsp;</span><?= lang('Recurring.new_' . $kind->value) ?>
        </a>
    <?php endif; ?>
</div>

<p class="small text-body-secondary"><?= lang('Recurring.note_' . $kind->value) ?></p>

<?php if ($reminders !== []): ?>
    <div class="alert alert-warning" id="schedule_reminders">
        <strong><?= lang('Recurring.reminders_due') ?></strong>
        <ul class="mb-0">
            <?php foreach ($reminders as $reminder): ?>
                <li>
                    <a href="<?= esc(base_url($module . '/view/' . (int) $reminder['schedule_id']), 'attr') ?>"><?= esc($reminder['name']) ?></a>:
                    <?= esc(RecurrencePhrase::reminder((int) $reminder['send_when'], $reminder['days'])) ?>
                    (<bdi dir="ltr"><?= esc($reminder['invoice_date']) ?></bdi>)
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="small mb-0"><?= lang('Recurring.reminders_in_app_only') ?></p>
    </div>
<?php endif; ?>

<form method="get" class="app-form d-flex flex-wrap gap-2 align-items-end mb-3" id="schedule_filters">
    <div>
        <label class="form-label" for="f_search"><?= lang('Recurring.search') ?></label>
        <input type="text" class="form-control form-control-sm" id="f_search" name="search" value="<?= esc($filters['search'], 'attr') ?>">
    </div>
    <div>
        <label class="form-label" for="f_status"><?= lang('Recurring.status') ?></label>
        <select class="form-select form-select-sm" id="f_status" name="status">
            <option value=""><?= lang('Recurring.status_all') ?></option>
            <?php foreach ($statuses as $status): ?>
                <option value="<?= esc($status->value, 'attr') ?>"<?= $filters['status'] === $status->value ? ' selected' : '' ?>><?= lang($status->languageKey()) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-outline-secondary btn-sm"><?= lang('Recurring.filter') ?></button>
</form>

<div class="table-responsive">
    <table class="table table-sm align-middle" id="schedules_table">
        <thead>
            <tr>
                <th scope="col"><?= lang('Recurring.name') ?></th>
                <th scope="col"><?= lang($invoice ? 'Recurring.customer' : 'Recurring.category') ?></th>
                <th scope="col"><?= lang('Recurring.period') ?></th>
                <th scope="col"><?= lang('Recurring.next_date') ?></th>
                <th scope="col"><?= lang('Recurring.branch') ?></th>
                <th scope="col" class="text-end"><?= lang('Recurring.made') ?></th>
                <th scope="col" class="text-end"><?= lang('Recurring.refused') ?></th>
                <th scope="col"><?= lang('Recurring.status') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-body-secondary" id="schedules_empty"><?= lang('Recurring.empty') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <?php $terms = RecurrenceTerms::of($row); ?>
                <tr id="schedule_<?= (int) $row->schedule_id ?>">
                    <td><a href="<?= esc(base_url($module . '/view/' . (int) $row->schedule_id), 'attr') ?>"><?= esc((string) $row->name) ?></a></td>
                    <td><?= esc((string) ($invoice ? $row->customer_name : $row->category_name)) ?></td>
                    <td><?= $terms->frequency() !== null && ! $invoice ? lang('Recurring.frequency_' . $terms->frequency()) : RecurrencePhrase::every((int) $row->period_count, $terms->unit) ?></td>
                    <td><bdi dir="ltr"><?= esc((string) ($row->next_due_date ?? '')) ?></bdi></td>
                    <td><bdi dir="ltr"><?= esc((string) $row->branch_code) ?></bdi></td>
                    <td class="text-end"><?= (int) $row->generated_count ?></td>
                    <td class="text-end"><?= (int) $row->refused_count > 0 ? '<span class="badge text-bg-danger">' . (int) $row->refused_count . '</span>' : '0' ?></td>
                    <td><span class="badge text-bg-<?= $row->status === 'active' ? 'success' : ($row->status === 'paused' ? 'warning' : 'secondary') ?>"><?= lang('Recurring.status_' . $row->status) ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<nav class="d-flex gap-2 align-items-center" aria-label="<?= lang('Recurring.title_' . $kind->value) ?>">
    <span class="text-body-secondary small"><?= lang('Recurring.count', [$total]) ?></span>
    <?php if ($offset > 0): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($module . '?' . $query(['offset' => max(0, $offset - $pageSize)])), 'attr') ?>"><?= lang('Recurring.previous') ?></a>
    <?php endif; ?>
    <?php if ($offset + $pageSize < $total): ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url($module . '?' . $query(['offset' => $offset + $pageSize])), 'attr') ?>"><?= lang('Recurring.next') ?></a>
    <?php endif; ?>
</nav>

<?= view('partial/footer') ?>
