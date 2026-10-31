<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AgingBuckets;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The six buckets what is owed is aged in: the day itself, four stretches of a period each, and
 * everything older, counted from the date of the document to the day of the report.
 */
class AgingBucketsTest extends CIUnitTestCase
{
    /**
     * @return array<string, array{int, int}>
     */
    public static function edgeProvider(): array
    {
        return [
            'the day itself'          => [0, 0],
            'one day'                 => [1, 1],
            'the last day of the first stretch'  => [30, 1],
            'the first day of the second'        => [31, 2],
            'the last day of the second'         => [60, 2],
            'the first day of the third'         => [61, 3],
            'the last day of the third'          => [90, 3],
            'the first day of the fourth'        => [91, 4],
            'the last day of the fourth'         => [120, 4],
            'the first day of everything older'  => [121, 5],
            'a year'                  => [365, 5],
            'a document of the future' => [-3, 0],
        ];
    }

    #[DataProvider('edgeProvider')]
    public function testIndexOf_PutsAnAgeInTheBucketOfItsRange(int $age, int $index): void
    {
        $this->assertSame($index, AgingBuckets::indexOf($age));
    }

    /**
     * @return array<string, array{int, int, int}>
     */
    public static function shortPeriodProvider(): array
    {
        return [
            'period 10, 10 days'  => [10, 10, 1],
            'period 10, 11 days'  => [11, 10, 2],
            'period 10, 40 days'  => [40, 10, 4],
            'period 10, 41 days'  => [41, 10, 5],
            'period 1, 1 day'     => [1, 1, 1],
            'period 1, 2 days'    => [2, 1, 2],
            'period 1, 4 days'    => [4, 1, 4],
            'period 1, 5 days'    => [5, 1, 5],
            'period 0 is a day'   => [2, 0, 2],
        ];
    }

    #[DataProvider('shortPeriodProvider')]
    public function testIndexOf_FollowsThePeriodOfTheReport(int $age, int $period, int $index): void
    {
        $this->assertSame($index, AgingBuckets::indexOf($age, $period));
    }

    public function testRanges_NameTheStretchesOfAPeriodOfThirtyDays(): void
    {
        $this->assertSame(['', '1 - 30', '31 - 60', '61 - 90', '91 - 120', '120+'], AgingBuckets::ranges(30));
        $this->assertSame(['', '1 - 30', '31 - 60', '61 - 90', '91 - 120', '120+'], AgingBuckets::ranges());
    }

    public function testRanges_FollowThePeriodTheReferenceSystemShowedForTenDaysAndForOne(): void
    {
        $this->assertSame(['', '1 - 10', '11 - 20', '21 - 30', '31 - 40', '40+'], AgingBuckets::ranges(10));
        $this->assertSame(['', '1 - 1', '2 - 2', '3 - 3', '4 - 4', '4+'], AgingBuckets::ranges(1));
    }

    public function testAgeInDays_CountsTheDaysFromTheDocumentToTheReportAndIsNegativeForAFutureDocument(): void
    {
        $this->assertSame(0, AgingBuckets::ageInDays('2026-10-27', '2026-10-27'));
        $this->assertSame(3, AgingBuckets::ageInDays('2026-10-24', '2026-10-27'));
        $this->assertSame(-2, AgingBuckets::ageInDays('2026-10-29', '2026-10-27'));
    }

    public function testAgeInDays_IsNotMovedByTheChangeOfTheClock(): void
    {
        $this->assertSame(2, AgingBuckets::ageInDays('2026-03-28', '2026-03-30'));
        $this->assertSame(2, AgingBuckets::ageInDays('2026-10-24', '2026-10-26'));
        $this->assertSame(365, AgingBuckets::ageInDays('2025-10-27', '2026-10-27'));
    }

    public function testSpread_AddsEachDocumentToTheBucketOfItsAgeAndLeavesOutADocumentOfTheFuture(): void
    {
        $spread = AgingBuckets::spread([
            ['2026-10-27', '1.0000'],
            ['2026-10-26', '2.0000'],
            ['2026-09-27', '4.0000'],
            ['2026-09-26', '8.0000'],
            ['2026-06-29', '16.0000'],
            ['2026-10-28', '32.0000'],
        ], '2026-10-27');

        $this->assertSame(['1.0000', '6.0000', '8.0000', '0.0000', '16.0000', '0.0000'], $spread);
        $this->assertSame('31.0000', AgingBuckets::total($spread));
    }

    public function testNamed_GivesTheBucketsTheirNames(): void
    {
        $named = AgingBuckets::named([['2026-10-27', '5.0000']], '2026-10-27');

        $this->assertSame(AgingBuckets::KEYS, array_keys($named));
        $this->assertSame('5.0000', $named['today']);
        $this->assertSame(AgingBuckets::empty(), AgingBuckets::named([], '2026-10-27'));
    }
}
