<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\ScheduleStatus;

/**
 * Whether the schedules of invoices and expenses are being run: an active schedule whose next document
 * should have been made days ago is stuck, because the command that makes them is not running from cron
 * or something it meets stops it every time. The dates the command had to refuse are listed for review.
 * It reads and changes nothing.
 */
final class RecurringChecks extends CheckGroup
{
    public const STUCK_AFTER_DAYS = 2;
    private const LISTED          = 50;

    public function run(): array
    {
        return [
            $this->check('recurring', 'WP35-RECURRING', 'Recurring schedules are being run', ['recurring_schedules', 'recurring_runs'], fn (): CheckResult => $this->recurring()),
        ];
    }

    private function recurring(): CheckResult
    {
        $limit = date('Y-m-d', strtotime('-' . self::STUCK_AFTER_DAYS . ' days'));
        $stuck = $this->db->table('recurring_schedules')
            ->select('schedule_id, kind, name, next_due_date, issue_before_days')
            ->where('status', ScheduleStatus::Active->value)
            ->where('next_due_date IS NOT NULL', null, false)
            ->where('DATE_SUB(next_due_date, INTERVAL issue_before_days DAY) <', $limit)
            ->orderBy('next_due_date', 'ASC')
            ->limit(self::LISTED)
            ->get()
            ->getResult();

        $refused = (int) $this->db->table('recurring_runs')->where('status', 'refused')->countAllResults();
        $active  = (int) $this->db->table('recurring_schedules')->where('status', ScheduleStatus::Active->value)->countAllResults();
        $data    = ['active' => $active, 'stuck' => count($stuck), 'refused_dates' => $refused, 'stuck_after_days' => self::STUCK_AFTER_DAYS];

        $findings = [
            'Run php spark accounting:generate_recurring from cron at least once a day, for example: 5 2 * * * cd /path/to/app && php spark accounting:generate_recurring (the application installs no cron of its own).',
            ...array_map(static fn (object $row): string => sprintf('Schedule #%d "%s" (%s) was due on %s and nothing was made.', $row->schedule_id, $row->name, RecurringKind::from((string) $row->kind)->value, $row->next_due_date), $stuck),
        ];

        if ($stuck !== []) {
            return CheckResult::fail(count($stuck) . ' active schedule(s) are more than ' . self::STUCK_AFTER_DAYS . ' days behind: the command that makes them is not running or is stopped by what it meets.', $findings, $data);
        }

        if ($refused > 0) {
            return CheckResult::review($refused . ' date(s) of schedules were refused and are waiting for somebody to look at them.', [...$findings, 'Each refusal is named in the history of its schedule, where the date can be tried again.'], $data);
        }

        return CheckResult::pass($active . ' active schedule(s), none behind, no refused date.', $findings, $data);
    }
}
