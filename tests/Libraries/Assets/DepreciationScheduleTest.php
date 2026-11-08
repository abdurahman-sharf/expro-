<?php

namespace Tests\Libraries\Assets;

use App\Libraries\Assets\AssetInterval;
use App\Libraries\Assets\AssetMethod;
use App\Libraries\Assets\AssetTerms;
use App\Libraries\Assets\DepreciationSchedule;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The first depreciation date and amount as the form of the reference system computes them (read
 * from its scripts), and the dated events of the four methods that follow them: the methods are an
 * assumption where the form says only which fields each one takes.
 *
 * @internal
 */
final class DepreciationScheduleTest extends CIUnitTestCase
{
    private DepreciationSchedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = new DepreciationSchedule(2);
    }

    private function fixed(string $cost, string $salvage, string $amount, string $inService, ?string $firstDate = null, int $period = 1, AssetInterval $interval = AssetInterval::Month, ?string $endDate = null, string $openingAccumulated = '0', ?string $openingThrough = null): AssetTerms
    {
        return new AssetTerms(AssetMethod::Fixed, $cost, $salvage, $inService, $period, $interval, amount: $amount, firstDate: $firstDate, endDate: $endDate, openingAccumulated: $openingAccumulated, openingThrough: $openingThrough);
    }

    private function declining(string $cost, string $salvage, string $percentage, string $inService, ?string $firstDate = null, string $openingAccumulated = '0', ?string $openingThrough = null): AssetTerms
    {
        return new AssetTerms(AssetMethod::Declining, $cost, $salvage, $inService, 1, AssetInterval::Month, percentage: $percentage, firstDate: $firstDate, openingAccumulated: $openingAccumulated, openingThrough: $openingThrough);
    }

    /**
     * @param list<array{date: string, amount: string}> $events
     *
     * @return list<string>
     */
    private function amounts(array $events): array
    {
        return array_map(static fn (array $event): string => $event['amount'], $events);
    }

    /**
     * @param list<array{date: string, amount: string}> $events
     *
     * @return list<string>
     */
    private function dates(array $events): array
    {
        return array_map(static fn (array $event): string => $event['date'], $events);
    }

    public function testFirstAmount_FollowsTheFixedAndPercentageFormulas(): void
    {
        $days = $this->fixed('100000', '0', '3000', '2026-01-01', '2026-01-16', 30, AssetInterval::Day);
        $this->assertSame('1500.0000', $this->schedule->firstAmount($days), 'days: 3000 x 15 / 30');

        $weeks = $this->fixed('100000', '0', '800', '2026-01-01', '2026-01-15', 2, AssetInterval::Week);
        $this->assertSame('800.0000', $this->schedule->firstAmount($weeks), 'weeks: 800 x (14 / 7) / 2');

        $percentage = $this->declining('12500', '500', '10', '2026-01-01', '2026-02-01');
        $this->assertSame('1200.0000', $this->schedule->firstAmount($percentage), 'percentage: 10 % of 12,500 less 500, one period of one');

        $capped = $this->fixed('12500', '500', '5000', '2026-01-01', '2026-04-01');
        $this->assertSame('12000.0000', $this->schedule->firstAmount($capped), '5,000 x 3 is 15,000, cut to what is to be depreciated');
    }

    public function testFirstAmount_IsZeroBeforeTheServiceDate(): void
    {
        $this->assertSame('0.0000', $this->schedule->firstAmount($this->fixed('12000', '0', '1000', '2026-03-01', '2026-02-01')));
        $this->assertSame('0.0000', $this->schedule->firstAmount($this->declining('12000', '0', '10', '2026-03-01', '2026-02-28')));
    }

    public function testFirstAmount_IsZeroWithoutDepreciationByDates(): void
    {
        $none  = new AssetTerms(AssetMethod::None, '12000', '0', '2026-01-01');
        $units = new AssetTerms(AssetMethod::Units, '12000', '0', '2026-01-01', costPerUnit: '2');

        $this->assertSame('0.0000', $this->schedule->firstAmount($none));
        $this->assertSame('0.0000', $this->schedule->firstAmount($units));
    }

    public function testDefaultFirstDate_ClampsToTheMonthEnd(): void
    {
        $this->assertSame('2026-02-28', DepreciationSchedule::defaultFirstDate('2026-01-31', 1, AssetInterval::Month));
        $this->assertSame('2027-02-28', DepreciationSchedule::defaultFirstDate('2026-01-31', 13, AssetInterval::Month));
        $this->assertSame('2028-02-29', DepreciationSchedule::defaultFirstDate('2026-12-31', 14, AssetInterval::Month), 'a leap year keeps its 29th');
    }

    public function testDefaultFirstDate_AddsDaysAndWeeks(): void
    {
        $this->assertSame('2026-01-25', DepreciationSchedule::defaultFirstDate('2026-01-15', 10, AssetInterval::Day));
        $this->assertSame('2026-01-29', DepreciationSchedule::defaultFirstDate('2026-01-15', 2, AssetInterval::Week));
    }

    public function testAddInterval_CountsMonthsFromTheStartSoTheDayComesBack(): void
    {
        $this->assertSame('2026-02-28', DepreciationSchedule::addInterval('2026-01-31', AssetInterval::Month, 1));
        $this->assertSame('2026-03-31', DepreciationSchedule::addInterval('2026-01-31', AssetInterval::Month, 2));
        $this->assertSame('2026-04-30', DepreciationSchedule::addInterval('2026-01-31', AssetInterval::Month, 3));
        $this->assertSame('2027-01-31', DepreciationSchedule::addInterval('2026-01-31', AssetInterval::Month, 12));
    }

    public function testElapsed_CountsWholeMonthsAndTreatsTheClampedMonthEndAsComplete(): void
    {
        $this->assertSame('1.0000', DepreciationSchedule::elapsed('2026-01-31', '2026-02-28', AssetInterval::Month));
        $this->assertSame('0.0000', DepreciationSchedule::elapsed('2026-01-16', '2026-02-01', AssetInterval::Month));
        $this->assertSame('3.0000', DepreciationSchedule::elapsed('2026-01-01', '2026-04-01', AssetInterval::Month));
        $this->assertSame('15.0000', DepreciationSchedule::elapsed('2026-01-01', '2026-01-16', AssetInterval::Day));
        $this->assertSame('2.0000', DepreciationSchedule::elapsed('2026-01-01', '2026-01-15', AssetInterval::Week));
        $this->assertSame('1.4285', DepreciationSchedule::elapsed('2026-01-01', '2026-01-11', AssetInterval::Week), 'ten days are a week and four sevenths of one');
        $this->assertNull(DepreciationSchedule::elapsed('2026-02-01', '2026-01-31', AssetInterval::Month));
    }

    public function testEvents_FixedEndsWithAPeriodCutToCostLessSalvage(): void
    {
        $events = $this->schedule->events($this->fixed('10500', '500', '3000', '2026-01-01'), '2030-12-31');

        $this->assertSame(['3000.0000', '3000.0000', '3000.0000', '1000.0000'], $this->amounts($events));
        $this->assertSame(['2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01'], $this->dates($events));
        $this->assertSame('10000.0000', array_reduce($this->amounts($events), static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0'));
    }

    public function testEvents_FixedStopsAtTheLastPeriodWhenTheAmountDividesTheBaseExactly(): void
    {
        $events = $this->schedule->events($this->fixed('12000', '0', '1000', '2026-01-01'), '2099-12-31');

        $this->assertCount(12, $events);
        $this->assertSame('2026-02-01', $events[0]['date']);
        $this->assertSame('2027-01-01', $events[11]['date']);
    }

    public function testEvents_FixedProratesOnlyTheFirstPeriod(): void
    {
        $events = $this->schedule->events($this->fixed('100000', '0', '3000', '2026-01-01', '2026-01-16', 30, AssetInterval::Day), '2026-03-20');

        $this->assertSame(['1500.0000', '3000.0000', '3000.0000'], $this->amounts($events));
        $this->assertSame(['2026-01-16', '2026-02-15', '2026-03-17'], $this->dates($events));
    }

    public function testEvents_FixedKeepsTheDayOfTheMonthAcrossShortMonths(): void
    {
        $events = $this->schedule->events($this->fixed('12000', '0', '1000', '2025-12-31', '2026-01-31'), '2026-05-31');

        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31'], $this->dates($events));
    }

    public function testEvents_FixedRespectsTheEndDate(): void
    {
        $events = $this->schedule->events($this->fixed('12000', '0', '1000', '2026-01-01', null, 1, AssetInterval::Month, '2026-04-15'), '2030-01-01');

        $this->assertSame(['2026-02-01', '2026-03-01', '2026-04-01'], $this->dates($events));
    }

    public function testEvents_FixedSkipsWhatIsNotDueYet(): void
    {
        $events = $this->schedule->events($this->fixed('12000', '0', '1000', '2026-01-01'), '2026-03-31');

        $this->assertSame(['2026-02-01', '2026-03-01'], $this->dates($events));
    }

    public function testEvents_FixedWithAZeroFirstPeriodStartsOnTheNextOne(): void
    {
        $events = $this->schedule->events($this->fixed('12000', '0', '1000', '2026-01-16', '2026-02-01'), '2026-04-30');

        $this->assertSame(['2026-03-01', '2026-04-01'], $this->dates($events), 'the first date is less than a whole month after going into service, so the first amount is nil');
    }

    public function testEvents_DecliningTakesAPercentageOfWhatIsLeftToDepreciate(): void
    {
        $events = $this->schedule->events($this->declining('12500', '500', '10', '2026-01-01'), '2026-05-31');

        $this->assertSame(['1200.0000', '1080.0000', '972.0000', '874.8000'], $this->amounts($events));
    }

    public function testEvents_DecliningNeverPassesCostLessSalvage(): void
    {
        $events = $this->schedule->events($this->declining('1000', '100', '60', '2026-01-01'), '2035-12-31');
        $sum    = array_reduce($this->amounts($events), static fn (string $total, string $amount): string => bcadd($total, $amount, 4), '0');

        $this->assertLessThanOrEqual(0, bccomp($sum, '900', 4));
        $this->assertGreaterThan(0, bccomp($sum, '899.9', 4), 'the declining amounts get close to the base');
    }

    public function testEvents_DecliningWithAWholeBaseInOnePeriodTakesItAllAndStops(): void
    {
        $events = $this->schedule->events($this->declining('1000', '100', '100', '2026-01-01'), '2030-12-31');

        $this->assertSame(['900.0000'], $this->amounts($events));
    }

    public function testEvents_KeepOnlyThoseAfterTheDaysAnOpeningDepreciationCovers(): void
    {
        $terms  = $this->fixed('12000', '0', '1000', '2025-01-01', null, 1, AssetInterval::Month, null, '5000', '2025-12-31');
        $events = $this->schedule->events($terms, '2026-12-31');

        $this->assertSame('2026-01-01', $events[0]['date']);
        $this->assertSame(['1000.0000', '1000.0000', '1000.0000', '1000.0000', '1000.0000', '1000.0000', '1000.0000'], $this->amounts($events), 'seven periods are left of the 7,000 the opening left');
        $this->assertSame('2026-07-01', $events[6]['date']);
    }

    public function testEvents_DecliningAfterAnOpeningTakesThePercentageOfWhatIsLeft(): void
    {
        $events = $this->schedule->events($this->declining('12500', '500', '10', '2025-01-01', null, '2000', '2025-12-31'), '2026-03-31');

        $this->assertSame(['1000.0000', '900.0000', '810.0000'], $this->amounts($events), '10 % of 12,000 less the 2,000 already depreciated, then of what is left');
    }

    public function testEvents_AreNoneForAnAssetThatIsNotDepreciatedByDates(): void
    {
        $this->assertSame([], $this->schedule->events(new AssetTerms(AssetMethod::None, '1000', '0', '2026-01-01'), '2030-01-01'));
        $this->assertSame([], $this->schedule->events(new AssetTerms(AssetMethod::Units, '1000', '0', '2026-01-01', costPerUnit: '1'), '2030-01-01'));
    }

    public function testEvents_AreNoneOnceTheOpeningDepreciationCoversTheBase(): void
    {
        $this->assertSame([], $this->schedule->events($this->fixed('12000', '0', '1000', '2020-01-01', null, 1, AssetInterval::Month, null, '12000', '2025-12-31'), '2030-01-01'));
    }

    public function testBetween_SumsTheEventsOfAPeriodWithBothEndsIncluded(): void
    {
        $terms = $this->fixed('12000', '0', '1000', '2025-12-31', '2026-01-31');

        $this->assertSame('1000.0000', $this->schedule->between($terms, '2026-01-31', '2026-02-28'), 'the event of 28 February is in, the one of 31 January is out');
        $this->assertSame('1000.0000', $this->schedule->between($terms, '2026-01-30', '2026-01-31'));
        $this->assertSame('0.0000', $this->schedule->between($terms, '2026-02-28', '2026-03-30'));
        $this->assertSame('3000.0000', $this->schedule->between($terms, null, '2026-03-31'));
    }

    public function testBetween_GivesTheWholeBaseOverAllThePeriodsOfTheLife(): void
    {
        $terms   = $this->fixed('10500', '500', '3000', '2026-01-01');
        $periods = [['2026-01-31', '2026-02-28'], ['2026-02-28', '2026-03-31'], ['2026-03-31', '2026-04-30'], ['2026-04-30', '2026-05-31'], ['2026-05-31', '2026-06-30']];
        $total   = '0';

        foreach ($periods as [$after, $through]) {
            $total = bcadd($total, $this->schedule->between($terms, $after, $through), 4);
        }

        $this->assertSame('10000.0000', $total);
    }

    public function testForUnits_MultipliesTheUnitsByTheCostOfAUnit(): void
    {
        $terms = new AssetTerms(AssetMethod::Units, '10500', '500', '2026-01-01', costPerUnit: '2.5');

        $this->assertSame('1250.0000', $this->schedule->forUnits($terms, '500', '0'));
    }

    public function testForUnits_IsCutToWhatIsLeftToDepreciate(): void
    {
        $terms = new AssetTerms(AssetMethod::Units, '10500', '500', '2026-01-01', costPerUnit: '2.5', openingAccumulated: '1000');

        $this->assertSame('1000.0000', $this->schedule->forUnits($terms, '5000', '8000'), '10,000 less 1,000 opening less 8,000 posted');
        $this->assertSame('0.0000', $this->schedule->forUnits($terms, '5000', '9000'));
    }

    public function testForUnits_IsNothingWithoutUnitsOrACostPerUnit(): void
    {
        $terms = new AssetTerms(AssetMethod::Units, '10500', '500', '2026-01-01', costPerUnit: '2.5');

        $this->assertSame('0.0000', $this->schedule->forUnits($terms, '0', '0'));
        $this->assertSame('0.0000', $this->schedule->forUnits(new AssetTerms(AssetMethod::Units, '10500', '500', '2026-01-01'), '10', '0'));
    }

    public function testBase_IsCostLessSalvageAndNeverBelowZero(): void
    {
        $this->assertSame('10000.0000', (new AssetTerms(AssetMethod::Fixed, '10500', '500', '2026-01-01'))->base());
        $this->assertSame('0.0000', (new AssetTerms(AssetMethod::Fixed, '100', '500', '2026-01-01'))->base());
    }

    public function testEvents_RoundEachEventToTheDecimalsOfTheBooks(): void
    {
        $events = (new DepreciationSchedule(0))->events($this->declining('1000', '0', '15', '2026-01-01'), '2026-04-30');

        $this->assertSame(['150.0000', '128.0000', '108.0000'], $this->amounts($events), '15 % of 1,000, of 850 (127.5 rounds away from zero), of 722');
    }
}
