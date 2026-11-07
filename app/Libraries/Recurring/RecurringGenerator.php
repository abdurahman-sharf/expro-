<?php

namespace App\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\CustomerLock;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Tax_lib;
use App\Libraries\Token_lib;
use App\Models\Expense;
use App\Models\Sale;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\OSPOS;
use DateTimeImmutable;
use Throwable;

/**
 * Makes the documents of the dates that have come. For each active schedule it makes the document of its
 * next date, and the next after it, in order and up to a cap, until none is due. Each date is one unit of
 * work under the lock of its schedule: the document is made through the same path as one typed by hand (the
 * sale or the expense model, with its numbering, its posting, its stock and its periods), and the run that
 * records it goes in the same transaction, with a unique key on the schedule and the date. So a repeated
 * run, or two at the same moment, make one document for a date, and a document that is refused (a closed
 * period, no stock, a credit limit, a missing account) leaves nothing behind but a named refusal for that
 * date, after which the schedule goes on with the next. The day that counts is the day of this application
 * and never the clock of the database. An invoice is dated with its due date, but never a day that has not
 * come yet; with the books off it is a plain legacy sale and a plain expense, which post nothing. A schedule
 * that fails in a way nobody named (a broken row, a bug) is left as it is for the next run and reported, and
 * the schedules after it are made all the same.
 */
final class RecurringGenerator
{
    use RunsInTransaction;

    public const DEFAULT_CAP = 12;

    private BaseConnection $db;
    private AccountingGuard $guard;
    private AuditLog $audit;
    private Sale $sales;
    private Expense $expenses;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->guard    = $guard ?? new AccountingGuard();
        $this->audit    = new AuditLog($this->db);
        $this->sales    = model(Sale::class);
        $this->expenses = model(Expense::class);
    }

    /**
     * @throws AccountingException
     */
    public function run(?string $today = null, ?int $scheduleId = null, int $cap = self::DEFAULT_CAP): GenerationReport
    {
        $today  = $this->day($today);
        $cap    = max(1, $cap);
        $report = new GenerationReport($today);

        foreach ($this->due($today, $scheduleId) as $id) {
            for ($done = 0; $done < $cap; $done++) {
                try {
                    $result = $this->next($id, $today);
                } catch (Throwable $unexpected) {
                    log_message('error', 'The recurring schedule ' . $id . ' is left for the next run: ' . $unexpected->getMessage());
                    $report->add(new RunResult($id, RunResult::FAILED, failure: $unexpected->getMessage()));

                    break;
                }

                if ($result === null) {
                    break;
                }

                $report->add($result);

                if ($result->stops()) {
                    break;
                }
            }

            if ($done === $cap && $this->due($today, $id) !== []) {
                $report->capped[] = $id;
            }
        }

        return $report;
    }

    /**
     * Makes again the document of a date that was refused, as the generator would have, when the cause is gone.
     *
     * @throws AccountingException
     */
    public function retry(int $scheduleId, int $runId, ?int $employeeId, ?string $today = null): RunResult
    {
        $today = $this->day($today);
        $row   = $this->db->table('recurring_schedules')->select('kind, customer_id, branch_id')->where('schedule_id', $scheduleId)->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::RecurringNotFound, 'There is no such schedule.', ['name' => (string) $scheduleId]);
        }

        $this->guard->require(RecurringKind::from((string) $row->kind)->managePermission(), $employeeId);

        if (! (new BranchGuard($this->db))->canAccess((int) $row->branch_id, $employeeId)) {
            throw new AccountingException(AccountingError::RecurringNotFound, 'There is no such schedule.', ['name' => (string) $scheduleId]);
        }

        try {
            return (new CustomerLock($this->db))->run($row->customer_id === null ? null : (int) $row->customer_id, function () use ($scheduleId, $runId, $employeeId, $today): RunResult {
                try {
                    return $this->transactional(fn (): RunResult => $this->retryUnit($scheduleId, $runId, $employeeId, $today));
                } catch (RunRefusal $refusal) {
                    return $this->recordRetryRefusal($scheduleId, $runId, $refusal);
                }
            });
        } catch (AccountingException $busy) {
            if ($busy->error === AccountingError::CustomerBusy) {
                throw new AccountingException(AccountingError::RecurringBusy, 'Another run of this schedule is working now.');
            }

            throw $busy;
        }
    }

    /**
     * @return list<int>
     */
    private function due(string $today, ?int $scheduleId): array
    {
        $builder = $this->db->table('recurring_schedules')
            ->select('schedule_id')
            ->where('status', ScheduleStatus::Active->value)
            ->where('next_due_date IS NOT NULL', null, false)
            ->where('DATE_SUB(next_due_date, INTERVAL issue_before_days DAY) <=', $today)
            ->orderBy('schedule_id', 'ASC');

        if ($scheduleId !== null) {
            $builder->where('schedule_id', $scheduleId);
        }

        return array_map('intval', array_column($builder->get()->getResultArray(), 'schedule_id'));
    }

    private function next(int $scheduleId, string $today): ?RunResult
    {
        $row = $this->db->table('recurring_schedules')->select('customer_id, status')->where('schedule_id', $scheduleId)->get()->getRow();

        if ($row === null || $row->status !== ScheduleStatus::Active->value) {
            return null;
        }

        try {
            return (new CustomerLock($this->db))->run($row->customer_id === null ? null : (int) $row->customer_id, function () use ($scheduleId, $today): ?RunResult {
                try {
                    return $this->transactional(fn (): ?RunResult => $this->unit($scheduleId, $today));
                } catch (RunRefusal $refusal) {
                    return $this->recordRefusal($scheduleId, $refusal);
                }
            });
        } catch (AccountingException $busy) {
            if (in_array($busy->error, [AccountingError::CustomerBusy, AccountingError::WriteFailed], true)) {
                log_message('warning', 'The recurring schedule ' . $scheduleId . ' is left for the next run: ' . $busy->getMessage());

                return new RunResult($scheduleId, RunResult::BUSY);
            }

            throw $busy;
        }
    }

    /**
     * @throws AccountingException|RunRefusal
     */
    private function unit(int $scheduleId, string $today): ?RunResult
    {
        $schedule = $this->lock($scheduleId);

        if ($schedule === null || $schedule->status !== ScheduleStatus::Active->value) {
            return null;
        }

        $terms      = RecurrenceTerms::of($schedule);
        $occurrence = (int) $schedule->next_occurrence;
        $due        = $terms->dueDate($occurrence);

        if ($due === null) {
            $this->end($schedule, $terms->endReason($occurrence) ?? 'end_date');

            return new RunResult($scheduleId, RunResult::ENDED);
        }

        if ($terms->generationDate($due) > $today) {
            return null;
        }

        $existing = $this->db->table('recurring_runs')->select('run_id, sale_id, expense_id')->where(['schedule_id' => $scheduleId, 'due_date' => $due])->get()->getRow();

        if ($existing !== null) {
            $this->advance($schedule, $terms, $occurrence);

            return new RunResult($scheduleId, RunResult::EXISTING, $due, $existing->sale_id === null ? null : (int) $existing->sale_id, $existing->expense_id === null ? null : (int) $existing->expense_id);
        }

        $made = $this->make($schedule, $due, $today, $occurrence);

        $this->db->table('recurring_runs')->ignore(true)->insert([
            'schedule_id'  => $scheduleId,
            'occurrence'   => $occurrence,
            'due_date'     => $due,
            'status'       => 'generated',
            'sale_id'      => $made['sale_id'],
            'expense_id'   => $made['expense_id'],
            'generated_as' => $made['generated_as'],
            'attempts'     => 1,
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        if ($this->db->affectedRows() !== 1) {
            throw new AccountingException(AccountingError::WriteFailed, 'The run of the date could not be recorded.');
        }

        $this->advance($schedule, $terms, $occurrence);
        $this->record($schedule, 'generated', ['due_date' => $due, ...$made]);

        return new RunResult($scheduleId, RunResult::GENERATED, $due, $made['sale_id'], $made['expense_id']);
    }

    /**
     * @throws AccountingException|RunRefusal
     */
    private function retryUnit(int $scheduleId, int $runId, ?int $employeeId, string $today): RunResult
    {
        $schedule = $this->lock($scheduleId);
        $run      = $schedule === null ? null : LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable('recurring_runs') . ' WHERE run_id = ? AND schedule_id = ? FOR UPDATE', [$runId, $scheduleId]);

        if ($schedule === null || $run === null) {
            throw new AccountingException(AccountingError::RecurringRunNotFound, 'There is no such run in this schedule.');
        }

        if ($run->status !== 'refused' || $run->sale_id !== null || $run->expense_id !== null) {
            throw new AccountingException(AccountingError::RecurringRunNotRetryable, 'Only a refused date is tried again.');
        }

        $made = $this->make($schedule, (string) $run->due_date, $today, (int) $run->occurrence);

        $this->db->table('recurring_runs')->where('run_id', $runId)->update([
            'status'          => 'generated',
            'sale_id'         => $made['sale_id'],
            'expense_id'      => $made['expense_id'],
            'generated_as'    => $made['generated_as'],
            'refusal_source'  => null,
            'refusal_code'    => null,
            'refusal_context' => null,
            'attempts'        => (int) $run->attempts + 1,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        $this->record($schedule, 'retried', ['due_date' => (string) $run->due_date, 'run_id' => $runId, ...$made], $employeeId);

        return new RunResult($scheduleId, RunResult::GENERATED, (string) $run->due_date, $made['sale_id'], $made['expense_id']);
    }

    private function recordRefusal(int $scheduleId, RunRefusal $refusal): ?RunResult
    {
        return $this->transactional(function () use ($scheduleId, $refusal): ?RunResult {
            $schedule = $this->lock($scheduleId);

            if ($schedule === null || $schedule->status !== ScheduleStatus::Active->value || $refusal->dueDate === null || (int) $schedule->next_occurrence !== $refusal->occurrence) {
                return null;
            }

            $this->db->table('recurring_runs')->ignore(true)->insert([
                'schedule_id'     => $scheduleId,
                'occurrence'      => $refusal->occurrence,
                'due_date'        => $refusal->dueDate,
                'status'          => 'refused',
                'refusal_source'  => $refusal->source,
                'refusal_code'    => $refusal->error,
                'refusal_context' => $refusal->contextJson(),
                'attempts'        => 1,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            $this->advance($schedule, RecurrenceTerms::of($schedule), (int) $schedule->next_occurrence);
            $this->record($schedule, 'refused', ['due_date' => $refusal->dueDate, 'source' => $refusal->source, 'code' => $refusal->error]);

            return new RunResult($scheduleId, RunResult::REFUSED, $refusal->dueDate, refusal: $refusal);
        });
    }

    private function recordRetryRefusal(int $scheduleId, int $runId, RunRefusal $refusal): RunResult
    {
        $this->db->table('recurring_runs')->where(['run_id' => $runId, 'schedule_id' => $scheduleId, 'status' => 'refused'])->set('attempts', 'attempts + 1', false)->update([
            'refusal_source'  => $refusal->source,
            'refusal_code'    => $refusal->error,
            'refusal_context' => $refusal->contextJson(),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return new RunResult($scheduleId, RunResult::REFUSED, $refusal->dueDate, refusal: $refusal);
    }

    private function lock(int $scheduleId): ?object
    {
        return LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable('recurring_schedules') . ' WHERE schedule_id = ? FOR UPDATE', [$scheduleId]);
    }

    private function advance(object $schedule, RecurrenceTerms $terms, int $occurrence): void
    {
        $next    = $occurrence + 1;
        $nextDue = $terms->dueDate($next);

        $this->db->table('recurring_schedules')->where('schedule_id', $schedule->schedule_id)->update([
            'next_occurrence' => $next,
            'next_due_date'   => $nextDue,
            'status'          => $nextDue === null ? ScheduleStatus::Ended->value : ScheduleStatus::Active->value,
            'ended_reason'    => $nextDue === null ? $terms->endReason($next) : null,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        if ($nextDue === null) {
            $this->record($schedule, 'ended', ['reason' => $terms->endReason($next)]);
        }
    }

    private function end(object $schedule, string $reason): void
    {
        $this->db->table('recurring_schedules')->where('schedule_id', $schedule->schedule_id)->update([
            'status'        => ScheduleStatus::Ended->value,
            'ended_reason'  => $reason,
            'next_due_date' => null,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $this->record($schedule, 'ended', ['reason' => $reason]);
    }

    /**
     * @return array{sale_id: int|null, expense_id: int|null, generated_as: string|null}
     *
     * @throws RunRefusal
     */
    private function make(object $schedule, string $due, string $today, int $occurrence): array
    {
        $scale = bcscale();

        bcscale(max(2, totals_decimals() + tax_decimals()));

        try {
            $this->guard->require(RecurringKind::from((string) $schedule->kind)->managePermission(), (int) $schedule->employee_id);
            (new BranchGuard($this->db))->requireBranch((int) $schedule->branch_id, (int) $schedule->employee_id);

            return $schedule->kind === RecurringKind::Invoice->value ? $this->makeInvoice($schedule, $due, $today) : $this->makeExpense($schedule, $due);
        } catch (RunRefusal $refusal) {
            throw $refusal->forDate($due, $occurrence);
        } catch (AccountingException|BranchException|PostingException $failure) {
            throw RunRefusal::from($failure)->forDate($due, $occurrence);
        } finally {
            bcscale($scale);
        }
    }

    /**
     * @return array{sale_id: int, expense_id: null, generated_as: string}
     *
     * @throws RunRefusal
     */
    private function makeInvoice(object $schedule, string $due, string $today): array
    {
        $issue      = $schedule->issue_mode === ScheduleInput::ISSUE_NOW;
        $customerId = (int) $schedule->customer_id;
        $cart       = [];

        foreach ($this->db->table('recurring_schedule_lines l')->select('l.*, i.name AS item_name, i.cost_price, i.tax_category_id')->join('items i', 'i.item_id = l.item_id')->where('l.schedule_id', $schedule->schedule_id)->orderBy('l.line_no', 'ASC')->get()->getResult() as $index => $line) {
            $cart[] = [
                'item_id'         => (int) $line->item_id,
                'line'            => $index + 1,
                'description'     => (string) $line->description === '' ? (string) $line->item_name : (string) $line->description,
                'serialnumber'    => '',
                'quantity'        => (string) $line->quantity,
                'discount'        => (string) $line->discount,
                'discount_type'   => (int) $line->discount_type,
                'cost_price'      => (string) $line->cost_price,
                'price'           => (string) $line->unit_price,
                'item_location'   => (int) $line->item_location,
                'print_option'    => 0,
                'tax_category_id' => $line->tax_category_id === null ? null : (int) $line->tax_category_id,
            ];
        }

        $taxes         = $issue ? (new Tax_lib())->get_taxes($cart, NEW_ENTRY, $customerId, 'sale_invoice') : [[], []];
        $invoiceNumber = $issue ? $this->legacyInvoiceNumber() : null;
        $status        = $issue ? COMPLETED : SUSPENDED;
        $date          = min($due, $today);
        $comment       = (string) $schedule->comment === '' ? (string) $schedule->name : (string) $schedule->comment;

        $saleId = $this->sales->datedBySchedule(fn (): int => $this->sales->save_value(
            NEW_ENTRY,
            $status,
            $cart,
            $customerId,
            (int) $schedule->employee_id,
            $comment,
            $invoiceNumber,
            null,
            null,
            SALE_TYPE_INVOICE,
            [],
            null,
            $taxes,
            true,
            invoiceDate: $date,
            paymentTerms: $schedule->payment_terms_days === null ? null : (int) $schedule->payment_terms_days,
        ));

        if ($saleId <= 0) {
            throw match (true) {
                $saleId === ACCOUNTING_FAILED && $this->sales->accountingFailure() !== null => RunRefusal::from($this->sales->accountingFailure()),
                $saleId === INSUFFICIENT_STOCK                                              => RunRefusal::sale('insufficient_stock'),
                $saleId === INVOICE_DISCOUNT_INVALID                                        => RunRefusal::sale('discount_invalid'),
                default                                                                     => RunRefusal::sale('save_failed'),
            };
        }

        return ['sale_id' => $saleId, 'expense_id' => null, 'generated_as' => $issue ? ScheduleInput::ISSUE_NOW : ScheduleInput::ISSUE_DRAFT];
    }

    /**
     * @return array{sale_id: null, expense_id: int, generated_as: null}
     *
     * @throws RunRefusal
     */
    private function makeExpense(object $schedule, string $due): array
    {
        $comment = (string) $schedule->comment === '' ? (string) $schedule->name : (string) $schedule->comment;
        $data    = [
            'date'                => $due . ' 00:00:00',
            'supplier_id'         => $schedule->supplier_id === null ? null : (int) $schedule->supplier_id,
            'supplier_tax_code'   => $schedule->supplier_tax_code,
            'amount'              => (string) $schedule->amount,
            'tax_amount'          => (string) ($schedule->tax_amount ?? '0'),
            'payment_type'        => $this->paymentLabel((string) $schedule->payment_type),
            'expense_category_id' => (int) $schedule->expense_category_id,
            'description'         => mb_substr($comment, 0, 255),
            'employee_id'         => (int) $schedule->employee_id,
            'deleted'             => 0,
        ];

        if ($schedule->treasury_id !== null) {
            $data['treasury_id']       = (int) $schedule->treasury_id;
            $data['expense_branch_id'] = (int) $schedule->branch_id;
        }

        if (! $this->expenses->save_value($data, NEW_ENTRY, (int) $schedule->employee_id)) {
            throw $this->expenses->accountingFailure() === null ? RunRefusal::sale('save_failed') : RunRefusal::from($this->expenses->accountingFailure());
        }

        return ['sale_id' => null, 'expense_id' => (int) $data['expense_id'], 'generated_as' => null];
    }

    private function paymentLabel(string $stored): string
    {
        $type = PaymentType::tryFrom($stored);

        return $type === null ? $stored : lang($type->languageKey());
    }

    /**
     * The number of an invoice while the numbering service is off and the shop has a format for it, as the
     * register gives one.
     *
     * @throws RunRefusal
     */
    private function legacyInvoiceNumber(): ?string
    {
        $format = (string) (config(OSPOS::class)->settings['sales_invoice_format'] ?? '');

        if ($format === '' || (new DocumentNumbering($this->db))->isEnabled(DocumentType::SalesInvoice)) {
            return null;
        }

        $number = (new Token_lib())->render($format);

        if ($this->sales->check_invoice_number_exists($number)) {
            throw RunRefusal::sale('invoice_number_duplicate');
        }

        return $number;
    }

    private function record(object $schedule, string $action, array $details, ?int $employeeId = null): void
    {
        if (! (new AccountingSettings($this->db))->isEnabled()) {
            return;
        }

        $kind = RecurringKind::from((string) $schedule->kind);

        $this->audit->write($kind->auditEntity(), (int) $schedule->schedule_id, $action, $employeeId, $employeeId === null ? JournalOrigin::System : JournalOrigin::User, $details, (int) $schedule->branch_id);
    }

    /**
     * @throws AccountingException
     */
    private function day(?string $day): string
    {
        $day ??= date('Y-m-d');
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        if ($parsed === false || $parsed->format('Y-m-d') !== $day) {
            throw new AccountingException(AccountingError::RecurringDateInvalid, "The day {$day} is not a day.", ['date' => $day]);
        }

        return $day;
    }
}
