<?php

namespace App\Libraries\Recurring;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The reminders of the schedules of invoices, as the people who work with them see them on the screen.
 * Nothing is sent: no e-mail and no message leaves the system, so a reminder is a line on the page of the
 * schedules that is there while its day has come and is not older than a month. A reminder is set on the
 * issue date, the invoice date, the due date, or some days before or after the invoice date. The ones that
 * look ahead (the issue date, the invoice date, days before) count from the next date the schedule will
 * make an invoice for; the ones that look back (the due date, days after) from the invoice the schedule made
 * last.
 */
final class RecurringReminders
{
    public const ON_ISSUE    = 0;
    public const ON_INVOICE  = 1;
    public const ON_DUE      = 2;
    public const DAYS_BEFORE = 3;
    public const DAYS_AFTER  = 4;
    public const WINDOW_DAYS = 30;

    private BaseConnection $db;
    private BranchGuard $branches;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->branches = $branches ?? new BranchGuard($this->db);
    }

    /**
     * @return list<array{schedule_id: int, name: string, send_when: int, days: int|null, date: string, invoice_date: string}>
     */
    public function due(string $today, ?int $viewerId): array
    {
        $reach   = $this->branches->allowedBranchIds($viewerId);
        $builder = $this->db->table('recurring_schedule_reminders r')
            ->select('r.send_when, r.days, s.schedule_id, s.name, s.next_due_date, s.issue_before_days, s.payment_terms_days')
            ->join('recurring_schedules s', 's.schedule_id = r.schedule_id')
            ->where(['s.kind' => RecurringKind::Invoice->value, 's.status' => ScheduleStatus::Active->value])
            ->orderBy('s.schedule_id', 'ASC')
            ->orderBy('r.reminder_id', 'ASC');

        if ($reach !== null) {
            if ($reach === []) {
                return [];
            }

            $builder->whereIn('s.branch_id', $reach);
        }

        $oldest = (new DateTimeImmutable($today, new DateTimeZone('UTC')))->modify('-' . self::WINDOW_DAYS . ' days')->format('Y-m-d');
        $found  = [];

        foreach ($builder->get()->getResult() as $row) {
            $sendWhen    = (int) $row->send_when;
            $looksBack   = $sendWhen === self::ON_DUE || $sendWhen === self::DAYS_AFTER;
            $invoiceDate = $looksBack ? $this->lastMade((int) $row->schedule_id) : ($row->next_due_date === null ? null : (string) $row->next_due_date);

            if ($invoiceDate === null) {
                continue;
            }

            $date = self::dateOf($sendWhen, $row->days === null ? null : (int) $row->days, $invoiceDate, (int) $row->issue_before_days, $row->payment_terms_days === null ? null : (int) $row->payment_terms_days);

            if ($date <= $today && $date >= $oldest) {
                $found[] = ['schedule_id' => (int) $row->schedule_id, 'name' => (string) $row->name, 'send_when' => $sendWhen, 'days' => $row->days === null ? null : (int) $row->days, 'date' => $date, 'invoice_date' => $invoiceDate];
            }
        }

        return $found;
    }

    public static function dateOf(int $sendWhen, ?int $days, string $invoiceDate, int $issueBeforeDays, ?int $paymentTerms): string
    {
        $offset = match ($sendWhen) {
            self::ON_ISSUE    => -$issueBeforeDays,
            self::ON_INVOICE  => 0,
            self::ON_DUE      => $paymentTerms ?? 0,
            self::DAYS_BEFORE => -($days ?? 0),
            default           => $days ?? 0,
        };

        return (new DateTimeImmutable($invoiceDate, new DateTimeZone('UTC')))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    private function lastMade(int $scheduleId): ?string
    {
        $row = $this->db->table('recurring_runs')->select('MAX(due_date) AS last')->where(['schedule_id' => $scheduleId, 'status' => 'generated'])->get()->getRow();

        return $row === null || $row->last === null ? null : (string) $row->last;
    }
}
