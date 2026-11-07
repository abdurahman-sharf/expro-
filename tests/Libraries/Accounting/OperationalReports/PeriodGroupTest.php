<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\PeriodGroup;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The calendar periods a report is grouped by: the week from Monday to Sunday, the calendar month and
 * year, every bucket between two days with the empty ones in, the limit, and the default span.
 */
class PeriodGroupTest extends CIUnitTestCase
{
    public function testBucketOf_StartsAWeekOnMondayAndEndsItOnSunday(): void
    {
        $sunday = PeriodGroup::Week->bucketOf('2026-10-25');
        $monday = PeriodGroup::Week->bucketOf('2026-10-26');

        $this->assertSame(['2026-10-19', '2026-10-25'], [$sunday->from, $sunday->to]);
        $this->assertSame(['2026-10-26', '2026-11-01'], [$monday->from, $monday->to]);
        $this->assertSame('2026-10-19', $sunday->key);
    }

    public function testBucketOf_GivesTheCalendarMonthAndYearAndTheDay(): void
    {
        $month = PeriodGroup::Month->bucketOf('2028-02-14');
        $year  = PeriodGroup::Year->bucketOf('2026-10-27');
        $day   = PeriodGroup::Day->bucketOf('2026-10-27');

        $this->assertSame(['2028-02-01', '2028-02-29'], [$month->from, $month->to]);
        $this->assertSame(['2026-01-01', '2026-12-31'], [$year->from, $year->to]);
        $this->assertSame(['2026-10-27', '2026-10-27'], [$day->from, $day->to]);
    }

    public function testBuckets_ListsEveryBucketBetweenTwoDaysTheEmptyOnesIncluded(): void
    {
        $this->assertCount(31, PeriodGroup::Day->buckets('2026-09-27', '2026-10-27'));
        $this->assertSame(['2026-10-19', '2026-10-26'], array_map(static fn ($bucket): string => $bucket->key, PeriodGroup::Week->buckets('2026-10-25', '2026-10-27')));
        $this->assertSame(['2026-11-01', '2026-12-01', '2027-01-01'], array_map(static fn ($bucket): string => $bucket->key, PeriodGroup::Month->buckets('2026-11-15', '2027-01-02')));
        $this->assertSame(['2025-01-01', '2026-01-01'], array_map(static fn ($bucket): string => $bucket->key, PeriodGroup::Year->buckets('2025-12-31', '2026-01-01')));
    }

    public function testBuckets_GivesNoneBeyondTheLimit(): void
    {
        $this->assertSame([], PeriodGroup::Day->buckets('2020-01-01', '2026-10-27'));
        $this->assertCount(PeriodGroup::MAX_BUCKETS, PeriodGroup::Day->buckets('2024-01-01', date('Y-m-d', strtotime('2024-01-01 +' . (PeriodGroup::MAX_BUCKETS - 1) . ' days'))));
    }

    public function testDefaultFrom_GivesAMonthOfDaysThreeMonthsOfWeeksAndTwelveMonthsAndNothingForYears(): void
    {
        $this->assertSame('2026-09-27', PeriodGroup::Day->defaultFrom('2026-10-27'));
        $this->assertSame('2026-07-27', PeriodGroup::Week->defaultFrom('2026-10-27'));
        $this->assertSame('2025-11-01', PeriodGroup::Month->defaultFrom('2026-10-27'));
        $this->assertCount(12, PeriodGroup::Month->buckets(PeriodGroup::Month->defaultFrom('2026-10-27'), '2026-10-27'));
        $this->assertNull(PeriodGroup::Year->defaultFrom('2026-10-27'));
    }
}
