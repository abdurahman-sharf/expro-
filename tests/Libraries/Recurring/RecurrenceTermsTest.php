<?php

namespace Tests\Libraries\Recurring;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Recurring\RecurrenceTerms;
use App\Libraries\Recurring\RecurrenceUnit;
use App\Libraries\Recurring\RecurringKind;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * When a schedule is due: the dates are counted from the start and never from the date before, the number
 * of dates and the end day stop it, and the days before the date are fewer than the days of the period as
 * the reference system counts them.
 *
 * @internal
 */
final class RecurrenceTermsTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function input(array $override = []): array
    {
        return array_merge(['period_count' => '1', 'period_unit' => 'months', 'start_date' => '2026-01-31', 'end_date' => '', 'max_repeats' => '', 'issue_before_days' => ''], $override);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function terms(array $override = []): RecurrenceTerms
    {
        return RecurrenceTerms::fromInput($this->input($override), RecurringKind::Invoice);
    }

    private function assertRefused(AccountingError $error, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $refusal) {
            $this->assertSame($error, $refusal->error);

            return;
        }

        $this->fail('The input should have been refused with ' . $error->value);
    }

    #[DataProvider('provideFromInput_AcceptsTheLastDayBeforeThePeriodAndRejectsThePeriodItself')]
    public function testFromInputAcceptsTheLastDayBeforeThePeriodAndRejectsThePeriodItself(string $count, string $unit, string $accepted, string $refused): void
    {
        $this->assertSame((int) $accepted, $this->terms(['period_count' => $count, 'period_unit' => $unit, 'issue_before_days' => $accepted])->issueBeforeDays);

        $this->assertRefused(AccountingError::RecurringIssueBeforeInvalid, fn () => $this->terms(['period_count' => $count, 'period_unit' => $unit, 'issue_before_days' => $refused]));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function provideFromInput_AcceptsTheLastDayBeforeThePeriodAndRejectsThePeriodItself(): iterable
    {
        return [
            'a month: 29 days'      => ['1', 'months', '29', '30'],
            'two weeks: 13 days'    => ['2', 'weeks', '13', '14'],
            'a year: 364 days'      => ['1', 'years', '364', '365'],
            'ten days: 9 days'      => ['10', 'days', '9', '10'],
            'three months: 89 days' => ['3', 'months', '89', '90'],
        ];
    }

    public function testFromInputAcceptsAZeroOrEmptyIssueBefore(): void
    {
        $this->assertSame(0, $this->terms(['issue_before_days' => '0'])->issueBeforeDays);
        $this->assertSame(0, $this->terms(['issue_before_days' => ''])->issueBeforeDays);
        $this->assertSame(0, $this->terms(['period_count' => '1', 'period_unit' => 'days', 'issue_before_days' => '0'])->issueBeforeDays);
    }

    public function testFromInputRejectsAnIssueBeforeThatIsNotAWholeNumber(): void
    {
        foreach (['-1', '1.5', 'x', '1e3'] as $bad) {
            $this->assertRefused(AccountingError::RecurringIssueBeforeInvalid, fn () => $this->terms(['issue_before_days' => $bad]));
        }
    }

    public function testFromInputRequiresAPeriodOfAtLeastOneDayWeekMonthOrYear(): void
    {
        foreach (['0', '-1', '1.5', 'x', '', '3651'] as $bad) {
            $this->assertRefused(AccountingError::RecurringPeriodInvalid, fn () => $this->terms(['period_count' => $bad]));
        }

        $this->assertRefused(AccountingError::RecurringPeriodInvalid, fn () => $this->terms(['period_unit' => 'fortnights']));
        $this->assertSame(RecurrenceUnit::Weeks, $this->terms(['period_unit' => 'weeks'])->unit);
    }

    public function testFromInputAcceptsAMaximumOfRepeatsOfZeroOrNothingAndRejectsWhatIsNotANumber(): void
    {
        $this->assertSame(0, $this->terms(['max_repeats' => ''])->maxRepeats);
        $this->assertSame(0, $this->terms(['max_repeats' => '0'])->maxRepeats);
        $this->assertSame(12, $this->terms(['max_repeats' => '12'])->maxRepeats);

        foreach (['-1', '2.5', 'many'] as $bad) {
            $this->assertRefused(AccountingError::RecurringMaxRepeatInvalid, fn () => $this->terms(['max_repeats' => $bad]));
        }
    }

    public function testFromInputRequiresRealDatesAndAnEndThatIsNotBeforeTheStart(): void
    {
        foreach (['2026-02-30', '31/01/2026', '', 'today'] as $bad) {
            $this->assertRefused(AccountingError::RecurringDateInvalid, fn () => $this->terms(['start_date' => $bad]));
        }

        $this->assertRefused(AccountingError::RecurringDateInvalid, fn () => $this->terms(['end_date' => '2026-13-01']));
        $this->assertRefused(AccountingError::RecurringEndBeforeStart, fn () => $this->terms(['end_date' => '2026-01-30']));
        $this->assertSame('2026-01-31', $this->terms(['end_date' => '2026-01-31'])->endDate);
        $this->assertNull($this->terms(['end_date' => ''])->endDate);
    }

    public function testFromInputTakesOnlyTheNineFrequenciesForAnExpense(): void
    {
        $frequencies = array_keys(RecurrenceTerms::EXPENSE_FREQUENCIES);

        $this->assertSame(['weekly', '2_weeks', '4_weeks', 'monthly', '2_months', '3_months', '6_months', 'yearly', '2_years'], $frequencies);

        foreach ($frequencies as $frequency) {
            $terms = RecurrenceTerms::fromInput(['frequency' => $frequency, 'start_date' => '2026-01-01'], RecurringKind::Expense);

            $this->assertSame($frequency, $terms->frequency());
        }

        $this->assertRefused(AccountingError::RecurringFrequencyInvalid, static fn () => RecurrenceTerms::fromInput(['frequency' => '5_days', 'start_date' => '2026-01-01'], RecurringKind::Expense));
        $this->assertRefused(AccountingError::RecurringFrequencyInvalid, static fn () => RecurrenceTerms::fromInput(['start_date' => '2026-01-01'], RecurringKind::Expense));
    }

    public function testFromInputLeavesTheIssueBeforeOutOfAnExpense(): void
    {
        $terms = RecurrenceTerms::fromInput(['frequency' => 'monthly', 'start_date' => '2026-01-01', 'issue_before_days' => '500'], RecurringKind::Expense);

        $this->assertSame(0, $terms->issueBeforeDays);
    }

    public function testDueDateCountsMonthEndsFromTheStartAndNeverFromTheDateBefore(): void
    {
        $terms = $this->terms();

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31', '2026-06-30'], array_map(static fn (int $n): ?string => $terms->dueDate($n), range(0, 5)));
    }

    public function testDueDateKeepsTheLeapDayOfALeapYear(): void
    {
        $monthly = $this->terms(['start_date' => '2028-01-31']);
        $yearly  = $this->terms(['start_date' => '2028-02-29', 'period_unit' => 'years']);

        $this->assertSame('2028-02-29', $monthly->dueDate(1));
        $this->assertSame(['2028-02-29', '2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29'], array_map(static fn (int $n): ?string => $yearly->dueDate($n), range(0, 4)));
    }

    public function testDueDateCountsDaysAndWeeksAcrossTheEndOfAMonthAndAYear(): void
    {
        $days  = $this->terms(['start_date' => '2026-12-30', 'period_unit' => 'days', 'period_count' => '2']);
        $weeks = $this->terms(['start_date' => '2026-12-25', 'period_unit' => 'weeks', 'period_count' => '2']);

        $this->assertSame(['2026-12-30', '2027-01-01', '2027-01-03'], array_map(static fn (int $n): ?string => $days->dueDate($n), range(0, 2)));
        $this->assertSame(['2026-12-25', '2027-01-08', '2027-01-22'], array_map(static fn (int $n): ?string => $weeks->dueDate($n), range(0, 2)));
    }

    public function testDueDateStepsEveryTwoMonthsFromTheStart(): void
    {
        $bimonthly = $this->terms(['period_count' => '2', 'start_date' => '2026-03-31']);

        $this->assertSame(['2026-03-31', '2026-05-31', '2026-07-31', '2026-09-30', '2026-11-30', '2027-01-31'], array_map(static fn (int $n): ?string => $bimonthly->dueDate($n), range(0, 5)));
    }

    public function testDueDateStopsAfterTheMaximumNumberOfRepeatsAndPastTheEndDay(): void
    {
        $limited = $this->terms(['max_repeats' => '2']);
        $ending  = $this->terms(['end_date' => '2026-03-30']);

        $this->assertNotNull($limited->dueDate(1));
        $this->assertNull($limited->dueDate(2));
        $this->assertSame('max_repeats', $limited->endReason(2));

        $this->assertSame('2026-02-28', $ending->dueDate(1));
        $this->assertNull($ending->dueDate(2));
        $this->assertSame('end_date', $ending->endReason(2));
        $this->assertNull($ending->endReason(1));
    }

    public function testDueDateNeverEndsWithoutAnEndOrAMaximumAndHasNoDateBeforeTheFirst(): void
    {
        $terms = $this->terms();

        $this->assertNotNull($terms->dueDate(500));
        $this->assertNull($terms->dueDate(-1));
    }

    public function testDueDateHasNoDateBeyondTheLastYear(): void
    {
        $terms = $this->terms(['start_date' => '9998-06-01', 'period_unit' => 'years']);

        $this->assertSame('9998-06-01', $terms->dueDate(0));
        $this->assertNull($terms->dueDate(2));
    }

    public function testGenerationDateIsTheDueDateLessTheDaysBefore(): void
    {
        $terms = $this->terms(['issue_before_days' => '5']);

        $this->assertSame('2026-02-23', $terms->generationDate('2026-02-28'));
        $this->assertSame('2026-03-01', $this->terms()->generationDate('2026-03-01'));
        $this->assertSame('2025-12-29', $terms->generationDate('2026-01-03'));
    }

    public function testFirstOccurrenceFromSkipsTheDatesBeforeTheDayAndFindsNoneAfterTheEnd(): void
    {
        $terms = $this->terms(['start_date' => '2026-01-01', 'period_unit' => 'days', 'end_date' => '2026-01-10']);

        $this->assertSame(4, $terms->firstOccurrenceFrom('2026-01-05', 0));
        $this->assertSame(4, $terms->firstOccurrenceFrom('2026-01-05', 2));
        $this->assertSame(7, $terms->firstOccurrenceFrom('2026-01-01', 7));
        $this->assertNull($terms->firstOccurrenceFrom('2026-01-11', 0));
    }

    public function testOfReadsTheTermsOfAStoredSchedule(): void
    {
        $row = (object) ['period_count' => '2', 'period_unit' => 'weeks', 'start_date' => '2026-05-01', 'end_date' => null, 'max_repeats' => '4', 'issue_before_days' => '3'];

        $terms = RecurrenceTerms::of($row);

        $this->assertSame([2, RecurrenceUnit::Weeks, '2026-05-01', null, 4, 3], [$terms->periodCount, $terms->unit, $terms->startDate, $terms->endDate, $terms->maxRepeats, $terms->issueBeforeDays]);
    }

    public function testFrequencyIsNullForAPeriodThatIsNotOneOfTheNine(): void
    {
        $this->assertNull($this->terms(['period_count' => '5', 'period_unit' => 'days'])->frequency());
        $this->assertSame('4_weeks', $this->terms(['period_count' => '4', 'period_unit' => 'weeks'])->frequency());
    }
}
