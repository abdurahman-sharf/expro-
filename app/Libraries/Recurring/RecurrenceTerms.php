<?php

namespace App\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\TextInput;
use DateTimeImmutable;
use DateTimeZone;

/**
 * When a schedule is due. The due dates are counted from the start date and never from the date
 * before, so a schedule that starts on the 31st of January and repeats every month is due on the
 * last day of February and again on the 31st of March, and no date ever slips into the next month.
 * The first due date is the start date. A schedule has an end by a day, by a number of dates, by
 * neither (then it never ends) or by both, whichever comes first. An invoice may be issued some days
 * before its date, fewer than the days the period has, which is counted as one for a day, seven for a
 * week, thirty for a month and 365 for a year.
 */
final readonly class RecurrenceTerms
{
    public const MAX_NAME_LENGTH = 255;
    public const MAX_YEAR        = 9999;
    public const MAX_COUNT       = 3650;

    /**
     * The frequencies an expense may repeat at.
     *
     * @var array<string, array{0: int, 1: RecurrenceUnit}>
     */
    public const EXPENSE_FREQUENCIES = [
        'weekly'   => [1, RecurrenceUnit::Weeks],
        '2_weeks'  => [2, RecurrenceUnit::Weeks],
        '4_weeks'  => [4, RecurrenceUnit::Weeks],
        'monthly'  => [1, RecurrenceUnit::Months],
        '2_months' => [2, RecurrenceUnit::Months],
        '3_months' => [3, RecurrenceUnit::Months],
        '6_months' => [6, RecurrenceUnit::Months],
        'yearly'   => [1, RecurrenceUnit::Years],
        '2_years'  => [2, RecurrenceUnit::Years],
    ];

    public function __construct(
        public int $periodCount,
        public RecurrenceUnit $unit,
        public string $startDate,
        public ?string $endDate,
        public int $maxRepeats,
        public int $issueBeforeDays,
    ) {
    }

    public static function of(object $schedule): self
    {
        return new self(
            (int) $schedule->period_count,
            RecurrenceUnit::from((string) $schedule->period_unit),
            (string) $schedule->start_date,
            $schedule->end_date === null ? null : (string) $schedule->end_date,
            (int) $schedule->max_repeats,
            (int) $schedule->issue_before_days,
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AccountingException
     */
    public static function fromInput(array $input, RecurringKind $kind): self
    {
        $unit  = null;
        $count = null;

        if ($kind === RecurringKind::Expense) {
            [$count, $unit] = self::EXPENSE_FREQUENCIES[TextInput::of($input['frequency'] ?? null, AccountingError::RecurringFrequencyInvalid)] ?? throw new AccountingException(AccountingError::RecurringFrequencyInvalid, 'The frequency is not one of the nine.');
        } else {
            $unit  = RecurrenceUnit::tryFrom(is_string($input['period_unit'] ?? null) ? $input['period_unit'] : '') ?? throw new AccountingException(AccountingError::RecurringPeriodInvalid, 'The period unit is days, weeks, months or years.', ['max' => self::MAX_COUNT]);
            $count = self::wholeNumber($input['period_count'] ?? '1', AccountingError::RecurringPeriodInvalid, 1, self::MAX_COUNT);
        }

        $start = self::day($input['start_date'] ?? null, AccountingError::RecurringDateInvalid);
        $end   = TextInput::of($input['end_date'] ?? null, AccountingError::RecurringDateInvalid) === '' ? null : self::day($input['end_date'], AccountingError::RecurringDateInvalid);

        if ($end !== null && $end < $start) {
            throw new AccountingException(AccountingError::RecurringEndBeforeStart, 'The end date is before the start date.', ['date' => $end, 'from' => $start]);
        }

        $max = TextInput::of($input['max_repeats'] ?? null, AccountingError::RecurringMaxRepeatInvalid) === '' ? 0 : self::wholeNumber($input['max_repeats'], AccountingError::RecurringMaxRepeatInvalid, 0, 1_000_000);

        $before = 0;

        if ($kind === RecurringKind::Invoice) {
            $limit  = $count * $unit->daysPerUnit();
            $before = TextInput::of($input['issue_before_days'] ?? null, AccountingError::RecurringIssueBeforeInvalid) === '' ? 0 : self::wholeNumber($input['issue_before_days'], AccountingError::RecurringIssueBeforeInvalid, 0, $limit - 1);
        }

        return new self($count, $unit, $start, $end, $max, $before);
    }

    /**
     * The date of the occurrence that has this number, counting from 0, or null when the schedule has
     * ended before it: the number of dates is used up, or the date is past the end day.
     */
    public function dueDate(int $occurrence): ?string
    {
        if ($occurrence < 0 || ($this->maxRepeats > 0 && $occurrence >= $this->maxRepeats)) {
            return null;
        }

        $date = $this->rawDate($occurrence);

        return $date === null || ($this->endDate !== null && $date > $this->endDate) ? null : $date;
    }

    /**
     * Why there is no date for the occurrence, for the one that has none.
     */
    public function endReason(int $occurrence): ?string
    {
        if ($this->dueDate($occurrence) !== null) {
            return null;
        }

        return $this->maxRepeats > 0 && $occurrence >= $this->maxRepeats ? 'max_repeats' : 'end_date';
    }

    /**
     * The first day a document for the date may be made: the date, or the days before it that the
     * schedule asks for.
     */
    public function generationDate(string $dueDate): string
    {
        return (new DateTimeImmutable($dueDate, new DateTimeZone('UTC')))->modify('-' . $this->issueBeforeDays . ' days')->format('Y-m-d');
    }

    /**
     * The number of the first occurrence that is on or after the day, for a schedule that comes back
     * from a pause and does not make the documents of the days it was paused. Null when none is left.
     */
    public function firstOccurrenceFrom(string $day, int $notBefore): ?int
    {
        $occurrence = $notBefore;

        for ($guard = 0; $guard < 100_000; $guard++, $occurrence++) {
            $date = $this->dueDate($occurrence);

            if ($date === null || $date >= $day) {
                return $date === null ? null : $occurrence;
            }
        }

        return null;
    }

    public function frequency(): ?string
    {
        foreach (self::EXPENSE_FREQUENCIES as $name => [$count, $unit]) {
            if ($count === $this->periodCount && $unit === $this->unit) {
                return $name;
            }
        }

        return null;
    }

    private function rawDate(int $occurrence): ?string
    {
        $start = new DateTimeImmutable($this->startDate, new DateTimeZone('UTC'));
        $steps = $this->periodCount * $occurrence;

        if ($this->unit === RecurrenceUnit::Days || $this->unit === RecurrenceUnit::Weeks) {
            $date = $start->modify('+' . ($steps * $this->unit->daysPerUnit()) . ' days');
        } else {
            $months = $steps * ($this->unit === RecurrenceUnit::Years ? 12 : 1);
            $total  = (int) $start->format('Y') * 12 + (int) $start->format('n') - 1 + $months;
            $year   = intdiv($total, 12);
            $month  = $total % 12 + 1;
            $last   = (int) $start->setDate($year, $month, 1)->format('t');
            $date   = $start->setDate($year, $month, min((int) $start->format('j'), $last));
        }

        return (int) $date->format('Y') > self::MAX_YEAR ? null : $date->format('Y-m-d');
    }

    /**
     * @throws AccountingException
     */
    private static function wholeNumber(mixed $value, AccountingError $error, int $min, int $max): int
    {
        $text = is_int($value) ? (string) $value : (is_string($value) ? trim($value) : '');

        if (preg_match('/^\d{1,9}$/', $text) !== 1 || (int) $text < $min || (int) $text > $max) {
            throw new AccountingException($error, 'The number is not a whole number between ' . $min . ' and ' . $max . '.', ['min' => $min, 'max' => $max]);
        }

        return (int) $text;
    }

    /**
     * @throws AccountingException
     */
    private static function day(mixed $value, AccountingError $error): string
    {
        $text   = is_string($value) ? trim($value) : '';
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($parsed === false || $parsed->format('Y-m-d') !== $text) {
            throw new AccountingException($error, "The date {$text} is not a day.", ['date' => $text]);
        }

        return $text;
    }
}
