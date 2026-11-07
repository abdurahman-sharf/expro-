<?php

namespace App\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The schedules that make an invoice or an expense on dates that come back. A schedule is written and
 * changed by someone who may manage the schedules of its kind and works in its branch, is paused and
 * resumed and ended, and is never deleted: it is the record of what it made, in the history of its dates.
 * The dates are counted from the start and the first date of a schedule is never a day that has passed, so
 * no old document is made by surprise. What a change may touch is narrowed once a date has been made: the
 * start and the period stay, because the dates already made were counted from them.
 */
final class RecurringSchedules
{
    use RunsInTransaction;

    public const PERMISSION_MODULE = ['invoice' => 'recurring_invoices', 'expense' => 'recurring_expenses'];

    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AccountingException|BranchException
     */
    public function create(RecurringKind $kind, array $input, ?int $employeeId, ?string $today = null): int
    {
        $this->guard->require($kind->managePermission(), $employeeId);

        if ($employeeId === null) {
            throw new AccountingException(AccountingError::PermissionDenied, 'A schedule is made by an employee, whose permissions the documents it makes are made with.');
        }

        $data = ScheduleInput::normalise($kind, $input, $this->db, $this->branches, $employeeId, $today ?? date('Y-m-d'), true);

        return $this->transactional(function () use ($kind, $data, $employeeId): int {
            $terms = $data['terms'];
            $first = $terms->dueDate(0);

            $this->db->table('recurring_schedules')->insert([
                ...$data['columns'],
                'company_id'      => CompanyContext::id(),
                'kind'            => $kind->value,
                'status'          => $first === null ? ScheduleStatus::Ended->value : ($data['active'] ? ScheduleStatus::Active->value : ScheduleStatus::Paused->value),
                'ended_reason'    => $first === null ? $terms->endReason(0) : null,
                'issue_mode'      => $data['columns']['issue_mode'] ?? null,
                'employee_id'     => $employeeId,
                'next_occurrence' => 0,
                'next_due_date'   => $first,
                'created_by'      => $employeeId,
            ]);

            $scheduleId = (int) $this->db->insertID();

            $this->replaceChildren($scheduleId, $data['lines'], $data['reminders']);
            $this->record($scheduleId, $kind, 'created', $employeeId, (int) $data['columns']['branch_id'], ['name' => $data['columns']['name'], 'first_date' => $first]);

            return $scheduleId;
        });
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AccountingException|BranchException
     */
    public function update(int $scheduleId, array $input, ?int $employeeId, ?string $today = null): void
    {
        $kind = $this->kindOf($scheduleId);

        $this->guard->require($kind->managePermission(), $employeeId);

        $today ??= date('Y-m-d');
        $data = ScheduleInput::normalise($kind, $input, $this->db, $this->branches, $employeeId, $today, false);

        $this->transactional(function () use ($scheduleId, $kind, $data, $employeeId, $today): void {
            $row = $this->lock($scheduleId, $employeeId);

            if ($row->status === ScheduleStatus::Ended->value) {
                throw new AccountingException(AccountingError::RecurringEnded, 'An ended schedule is not changed.');
            }

            $terms   = $data['terms'];
            $hasRuns = $this->db->table('recurring_runs')->where('schedule_id', $scheduleId)->countAllResults() > 0;
            $moved   = (int) $row->period_count !== $terms->periodCount || $row->period_unit !== $terms->unit->value || $row->start_date !== $terms->startDate;

            if ($moved && $hasRuns) {
                throw new AccountingException(AccountingError::RecurringTermsLocked, 'The dates already made were counted from the start and the period.');
            }

            if ($moved && $terms->startDate < $today) {
                throw new AccountingException(AccountingError::RecurringStartInPast, 'The first date of a schedule is not a day that has passed.', ['date' => $terms->startDate]);
            }

            $occurrence = $moved ? 0 : (int) $row->next_occurrence;
            $due        = $terms->dueDate($occurrence);

            $this->db->table('recurring_schedules')->where('schedule_id', $scheduleId)->update([
                ...$data['columns'],
                'next_occurrence' => $occurrence,
                'next_due_date'   => $due,
                'status'          => $due === null ? ScheduleStatus::Ended->value : $row->status,
                'ended_reason'    => $due === null ? $terms->endReason($occurrence) : null,
                'updated_by'      => $employeeId,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            $this->replaceChildren($scheduleId, $data['lines'], $data['reminders']);
            $this->record($scheduleId, $kind, 'updated', $employeeId, (int) $data['columns']['branch_id'], ['name' => $data['columns']['name'], 'next_date' => $due]);
        });
    }

    /**
     * Pauses, resumes or ends a schedule. A schedule that comes back from a pause goes on from the first
     * date that is today or later: the dates it was paused over are not made.
     *
     * @throws AccountingException|BranchException
     */
    public function changeStatus(int $scheduleId, ScheduleStatus $to, ?int $employeeId, ?string $today = null): ScheduleStatus
    {
        $kind = $this->kindOf($scheduleId);

        $this->guard->require($kind->managePermission(), $employeeId);

        $today ??= date('Y-m-d');

        return $this->transactional(function () use ($scheduleId, $kind, $to, $employeeId, $today): ScheduleStatus {
            $row  = $this->lock($scheduleId, $employeeId);
            $from = ScheduleStatus::from((string) $row->status);

            if ($from === $to) {
                return $from;
            }

            if (! $from->canMoveTo($to)) {
                throw new AccountingException(AccountingError::RecurringStatusNotAllowed, 'A schedule cannot move from ' . $from->name . ' to ' . $to->name . '.', ['from' => lang($from->languageKey()), 'to' => lang($to->languageKey())]);
            }

            $change  = ['status' => $to->value, 'updated_by' => $employeeId, 'updated_at' => date('Y-m-d H:i:s')];
            $skipped = 0;

            if ($to === ScheduleStatus::Ended) {
                $change += ['ended_reason' => 'manual', 'next_due_date' => null];
            }

            if ($to === ScheduleStatus::Active) {
                $terms      = RecurrenceTerms::of($row);
                $occurrence = $terms->firstOccurrenceFrom($today, (int) $row->next_occurrence);
                $skipped    = ($occurrence ?? (int) $row->next_occurrence) - (int) $row->next_occurrence;

                if ($occurrence === null) {
                    $change['status']        = ScheduleStatus::Ended->value;
                    $change['ended_reason']  = $terms->endReason((int) $row->next_occurrence + $skipped) ?? 'end_date';
                    $change['next_due_date'] = null;
                } else {
                    $change['next_occurrence'] = $occurrence;
                    $change['next_due_date']   = $terms->dueDate($occurrence);
                }
            }

            $this->db->table('recurring_schedules')->where('schedule_id', $scheduleId)->update($change);
            $this->record($scheduleId, $kind, 'status_changed', $employeeId, (int) $row->branch_id, ['from' => $from->name, 'to' => $change['status'], 'skipped_dates' => $skipped]);

            return ScheduleStatus::from((string) $change['status']);
        });
    }

    public function find(int $scheduleId, ?int $viewerId): ?object
    {
        $reach   = $this->branches->allowedBranchIds($viewerId);
        $builder = $this->selected();

        $this->limitTo($builder, $reach);

        return $builder->where('s.schedule_id', $scheduleId)->get()->getRow();
    }

    /**
     * @param array{status?: string, search?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(RecurringKind $kind, array $filters, ?int $viewerId, int $limit = 25, int $offset = 0): array
    {
        $reach = $this->branches->allowedBranchIds($viewerId);
        $runs  = $this->db->prefixTable('recurring_runs');

        $count = $this->searched($kind, $filters, $reach)->countAllResults();
        $rows  = $this->searched($kind, $filters, $reach)
            ->select("s.*, b.code AS branch_code, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS customer_name, (SELECT COUNT(*) FROM {$runs} r WHERE r.schedule_id = s.schedule_id AND r.status = 'generated') AS generated_count, (SELECT COUNT(*) FROM {$runs} r WHERE r.schedule_id = s.schedule_id AND r.status = 'refused') AS refused_count", false)
            ->orderBy('s.schedule_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $count, 'rows' => $rows];
    }

    /**
     * @return list<object>
     */
    public function lines(int $scheduleId): array
    {
        return $this->db->table('recurring_schedule_lines l')
            ->select('l.*, i.name AS item_name, i.item_number')
            ->join('items i', 'i.item_id = l.item_id')
            ->where('l.schedule_id', $scheduleId)
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @return list<object>
     */
    public function reminders(int $scheduleId): array
    {
        return $this->db->table('recurring_schedule_reminders')->where('schedule_id', $scheduleId)->orderBy('reminder_id', 'ASC')->get()->getResult();
    }

    /**
     * What each date of the schedule made, the newest first.
     *
     * @return list<object>
     */
    public function runs(int $scheduleId, int $limit = 200): array
    {
        $runs = $this->db->table('recurring_runs')->where('schedule_id', $scheduleId)->orderBy('due_date', 'DESC')->limit(max(1, $limit))->get()->getResult();

        foreach ($runs as $run) {
            $run->refusal_words = $run->status === 'refused' ? RunRefusal::wordsOf($run->refusal_source, $run->refusal_code, $run->refusal_context) : '';
        }

        return $runs;
    }

    /**
     * The next dates the schedule will make a document for.
     *
     * @return list<string>
     */
    public function upcoming(object $schedule, int $count = 5): array
    {
        if ($schedule->status === ScheduleStatus::Ended->value) {
            return [];
        }

        $terms = RecurrenceTerms::of($schedule);
        $dates = [];

        for ($occurrence = (int) $schedule->next_occurrence; count($dates) < $count; $occurrence++) {
            $date = $terms->dueDate($occurrence);

            if ($date === null) {
                break;
            }

            $dates[] = $date;
        }

        return $dates;
    }

    public function kindOf(int $scheduleId): RecurringKind
    {
        $row = $this->db->table('recurring_schedules')->select('kind')->where('schedule_id', $scheduleId)->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::RecurringNotFound, 'There is no such schedule.', ['name' => (string) $scheduleId]);
        }

        return RecurringKind::from((string) $row->kind);
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function lock(int $scheduleId, ?int $employeeId): object
    {
        $row = LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable('recurring_schedules') . ' WHERE schedule_id = ? FOR UPDATE', [$scheduleId]);

        if ($row === null || ! $this->branches->canAccess((int) $row->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::RecurringNotFound, 'There is no such schedule.', ['name' => (string) $scheduleId]);
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>>                  $lines
     * @param list<array{send_when: int, days: int|null}> $reminders
     */
    private function replaceChildren(int $scheduleId, array $lines, array $reminders): void
    {
        $this->db->table('recurring_schedule_lines')->where('schedule_id', $scheduleId)->delete();
        $this->db->table('recurring_schedule_reminders')->where('schedule_id', $scheduleId)->delete();

        foreach ($lines as $index => $line) {
            $this->db->table('recurring_schedule_lines')->insert([...$line, 'schedule_id' => $scheduleId, 'line_no' => $index + 1]);
        }

        foreach ($reminders as $reminder) {
            $this->db->table('recurring_schedule_reminders')->insert([...$reminder, 'schedule_id' => $scheduleId]);
        }
    }

    private function selected(): BaseBuilder
    {
        return $this->db->table('recurring_schedules s')
            ->select("s.*, b.code AS branch_code, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS customer_name, TRIM(CONCAT(ep.first_name, ' ', ep.last_name)) AS employee_name", false)
            ->join('branches b', 'b.branch_id = s.branch_id')
            ->join('people p', 'p.person_id = s.customer_id', 'left')
            ->join('people ep', 'ep.person_id = s.employee_id', 'left')
            ->where('s.company_id', CompanyContext::id());
    }

    /**
     * @param array{status?: string, search?: string} $filters
     * @param list<int>|null                          $reach
     */
    private function searched(RecurringKind $kind, array $filters, ?array $reach): BaseBuilder
    {
        $builder = $this->db->table('recurring_schedules s')
            ->join('branches b', 'b.branch_id = s.branch_id')
            ->join('people p', 'p.person_id = s.customer_id', 'left')
            ->where('s.company_id', CompanyContext::id())
            ->where('s.kind', $kind->value);

        $this->limitTo($builder, $reach);

        if (ScheduleStatus::tryFrom((string) ($filters['status'] ?? '')) !== null) {
            $builder->where('s.status', $filters['status']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $builder->groupStart()->like('s.name', $search)->orLike("CONCAT(p.first_name, ' ', p.last_name)", $search)->groupEnd();
        }

        return $builder;
    }

    /**
     * @param list<int>|null $reach
     */
    private function limitTo(BaseBuilder $builder, ?array $reach): void
    {
        if ($reach !== null) {
            $reach === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('s.branch_id', $reach);
        }
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(int $scheduleId, RecurringKind $kind, string $action, ?int $employeeId, int $branchId, array $details): void
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return;
        }

        $this->audit->write($kind->auditEntity(), $scheduleId, $action, $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, $details, $branchId);
    }
}
