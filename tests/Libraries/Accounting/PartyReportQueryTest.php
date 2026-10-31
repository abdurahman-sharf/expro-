<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\PartyReportQuery;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What a screen of customers or suppliers reads from the request: the day, the length of the buckets
 * and the period, and the refusal that says why a day that is not one was not used.
 */
class PartyReportQueryTest extends CIUnitTestCase
{
    private const TODAY = '2026-10-30';

    public function testForAging_ReadsTodayWhenTheRequestNamesNoDay(): void
    {
        $query = PartyReportQuery::forAging([], self::TODAY);

        $this->assertSame(self::TODAY, $query->day());
        $this->assertNull($query->asOf);
        $this->assertNull($query->refusal);
        $this->assertSame(AgingBuckets::DEFAULT_PERIOD, $query->days);
    }

    public function testForAging_ReadsTheDayTypedAndGivesItAsTheDayOfTheStatement(): void
    {
        $query = PartyReportQuery::forAging(['to' => '2026-09-15', 'period' => '10'], self::TODAY);

        $this->assertSame('2026-09-15', $query->day());
        $this->assertSame('2026-09-15', $query->asOf);
        $this->assertSame(10, $query->days);
        $this->assertSame('2026-09-15', $query->to);
    }

    public function testForAging_AcceptsADayAfterToday(): void
    {
        $query = PartyReportQuery::forAging(['to' => '2026-12-31'], self::TODAY);

        $this->assertSame('2026-12-31', $query->day());
        $this->assertNull($query->refusal);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notADayProvider(): array
    {
        return [
            'a word'              => ['tomorrow'],
            'a day that is not'   => ['2026-02-30'],
            'the wrong order'     => ['30-10-2026'],
            'a month only'        => ['2026-10'],
            'a day with a time'   => ['2026-10-30 10:00'],
        ];
    }

    #[DataProvider('notADayProvider')]
    public function testForAging_RefusesWhatIsNotADayAndReadsTodayInstead(string $typed): void
    {
        $query = PartyReportQuery::forAging(['to' => $typed], self::TODAY);

        $this->assertSame(AccountingError::InvalidDate, $query->refusal?->error);
        $this->assertSame(self::TODAY, $query->day());
        $this->assertNull($query->asOf);
        $this->assertSame($typed, $query->to);
    }

    public function testForAging_IgnoresAStartDayItHasNoUseFor(): void
    {
        $query = PartyReportQuery::forAging(['from' => '2027-01-01', 'to' => '2026-09-15'], self::TODAY);

        $this->assertNull($query->refusal);
        $this->assertSame('', $query->from);
        $this->assertSame('2026-09-15', $query->day());
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function periodProvider(): array
    {
        return [
            'nothing'            => ['', 30],
            'a number'           => ['7', 7],
            'one day'            => ['1', 1],
            'the limit'          => ['365', 365],
            'above the limit'    => ['366', 365],
            'four digits'        => ['9999', 365],
            'too many digits'    => ['10000', 30],
            'zero'               => ['0', 1],
            'negative'           => ['-5', 30],
            'a decimal'          => ['7.5', 30],
            'a word'             => ['week', 30],
            'an array'           => [['7'], 30],
        ];
    }

    #[DataProvider('periodProvider')]
    public function testForAging_KeepsTheLengthOfTheBucketsWithinOneDayAndTheLimit(mixed $typed, int $expected): void
    {
        $this->assertSame($expected, PartyReportQuery::forAging(['period' => $typed], self::TODAY)->days);
    }

    public function testForAging_TakesAnythingButAnArrayOfInputAsNoInput(): void
    {
        $this->assertSame(self::TODAY, PartyReportQuery::forAging(null, self::TODAY)->day());
        $this->assertSame(self::TODAY, PartyReportQuery::forAging(['to' => ['2026-01-01']], self::TODAY)->day());
    }

    public function testForBalances_ReadsThePeriodTyped(): void
    {
        $query = PartyReportQuery::forBalances(['from' => '2026-09-01', 'to' => '2026-09-30'], self::TODAY);

        $this->assertSame('2026-09-01', $query->period->from);
        $this->assertSame('2026-09-30', $query->period->to);
        $this->assertNull($query->refusal);
    }

    public function testForBalances_ReadsFromTheStartOfTheBooksToTodayWhenNothingIsTyped(): void
    {
        $query = PartyReportQuery::forBalances([], self::TODAY);

        $this->assertNull($query->period->from);
        $this->assertSame(self::TODAY, $query->period->to);
        $this->assertFalse($query->showEmpty);
    }

    public function testForBalances_RefusesAPeriodThatEndsBeforeItStartsAndReadsTodayInstead(): void
    {
        $query = PartyReportQuery::forBalances(['from' => '2026-10-20', 'to' => '2026-10-10'], self::TODAY);

        $this->assertSame(AccountingError::ReportPeriodInvalid, $query->refusal?->error);
        $this->assertNull($query->period->from);
        $this->assertSame(self::TODAY, $query->period->to);
        $this->assertSame('2026-10-20', $query->from);
        $this->assertSame('2026-10-10', $query->to);
    }

    public function testForBalances_RefusesAStartThatIsNotADay(): void
    {
        $query = PartyReportQuery::forBalances(['from' => 'last month'], self::TODAY);

        $this->assertSame(AccountingError::InvalidDate, $query->refusal?->error);
        $this->assertSame(self::TODAY, $query->period->to);
    }

    public function testForBalances_ShowsWhoHasNothingToShowOnlyWhenAsked(): void
    {
        $this->assertTrue(PartyReportQuery::forBalances(['empty' => '1'], self::TODAY)->showEmpty);
        $this->assertFalse(PartyReportQuery::forBalances(['empty' => '0'], self::TODAY)->showEmpty);
        $this->assertFalse(PartyReportQuery::forBalances(['empty' => 'yes'], self::TODAY)->showEmpty);
        $this->assertFalse(PartyReportQuery::forBalances(['empty' => ['1']], self::TODAY)->showEmpty);
    }
}
