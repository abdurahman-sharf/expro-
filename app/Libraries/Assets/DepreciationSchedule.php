<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\Money;
use DateTimeImmutable;

/**
 * What an asset depreciates by and when, from its terms alone, so the same terms give the same
 * figures whatever order the periods are run in. The fixed and the declining methods are a list of
 * dated events: the first is the prorated one the form shows as the first depreciation amount, each
 * later one is a whole period, every one is rounded to the decimals of the books and cut so the
 * total never passes the cost less the salvage. An asset entered with the depreciation it already
 * had keeps only the events after the day that depreciation covers. The units of production method
 * has no dates: a period's amount is what its units come to, cut the same way.
 */
final class DepreciationSchedule
{
    public const MAX_EVENTS = 40000;
    private const SCALE     = 4;
    private const WORK      = 12;

    public function __construct(private readonly int $decimals = 2)
    {
    }

    public static function addInterval(string $date, AssetInterval $interval, int $count): string
    {
        $start = self::day($date);

        if ($interval === AssetInterval::Month) {
            $months = ((int) $start->format('Y')) * 12 + ((int) $start->format('n') - 1) + $count;
            $year   = intdiv($months, 12);
            $month  = $months % 12 + 1;
            $last   = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');

            return sprintf('%04d-%02d-%02d', $year, $month, min((int) $start->format('j'), $last));
        }

        $days = $interval === AssetInterval::Day ? $count : 7 * $count;

        return $start->modify(sprintf('%+d days', $days))->format('Y-m-d');
    }

    public static function defaultFirstDate(string $inServiceDate, int $period, AssetInterval $interval): string
    {
        return self::addInterval($inServiceDate, $interval, $period);
    }

    /**
     * How many periods lie between the day an asset went into service and the day of its first
     * depreciation: whole months (a month is complete when the day it ends on, cut to the end of
     * the month, has come), days, or days over seven for weeks. Null when the second day is before
     * the first.
     *
     * @return numeric-string|null
     */
    public static function elapsed(string $from, string $to, AssetInterval $interval): ?string
    {
        if ($to < $from) {
            return null;
        }

        if ($interval === AssetInterval::Month) {
            $a      = self::day($from);
            $b      = self::day($to);
            $months = ((int) $b->format('Y') - (int) $a->format('Y')) * 12 + ((int) $b->format('n') - (int) $a->format('n'));

            while ($months > 0 && self::addInterval($from, AssetInterval::Month, $months) > $to) {
                $months--;
            }

            return bcadd((string) $months, '0', self::SCALE);
        }

        $days = (int) self::day($from)->diff(self::day($to))->days;

        return $interval === AssetInterval::Day ? bcadd((string) $days, '0', self::SCALE) : bcdiv((string) $days, '7', self::SCALE);
    }

    /**
     * The amount of the first depreciation as the form shows it: the amount of a period (or the
     * percentage of what is to be depreciated) in the proportion the time from going into service to
     * the first depreciation date bears to a period, cut to what is to be depreciated. Nothing for
     * an asset that is not depreciated by dates, and nothing when that date is before the day the
     * asset went into service.
     *
     * @return numeric-string
     */
    public function firstAmount(AssetTerms $terms): string
    {
        $zero = bcadd('0', '0', self::SCALE);

        if (! $terms->method->isScheduled()) {
            return $zero;
        }

        $first   = $terms->firstDate ?? self::defaultFirstDate($terms->inServiceDate, $terms->period, $terms->interval);
        $elapsed = self::elapsed($terms->inServiceDate, $first, $terms->interval);

        if ($elapsed === null) {
            return $zero;
        }

        $ratio = bcdiv($elapsed, (string) max(1, $terms->period), self::WORK);

        return $this->capped($this->raw($terms, $terms->base(), $ratio), $terms->base());
    }

    /**
     * The depreciation events due on or before a day, after the days an opening accumulated
     * depreciation covers.
     *
     * @return list<array{date: string, amount: numeric-string}>
     */
    public function events(AssetTerms $terms, string $through): array
    {
        if (! $terms->method->isScheduled()) {
            return [];
        }

        $remaining = bcsub($terms->base(), $terms->openingAccumulated, self::SCALE);
        $first     = $terms->firstDate ?? self::defaultFirstDate($terms->inServiceDate, $terms->period, $terms->interval);
        $events    = [];

        for ($index = 0; $index < self::MAX_EVENTS && bccomp($remaining, '0', self::SCALE) > 0; $index++) {
            $date = $index === 0 ? $first : self::addInterval($first, $terms->interval, $index * max(1, $terms->period));

            if ($date > $through || ($terms->endDate !== null && $date > $terms->endDate)) {
                break;
            }

            if ($terms->isOpening() && $date <= $terms->openingThrough) {
                continue;
            }

            $ratio = '1';

            if ($index === 0 && ! $terms->isOpening()) {
                $elapsed = self::elapsed($terms->inServiceDate, $first, $terms->interval);
                $ratio   = $elapsed === null ? '0' : bcdiv($elapsed, (string) max(1, $terms->period), self::WORK);
            }

            $amount = $this->capped($this->raw($terms, $remaining, $ratio), $remaining);

            if (bccomp($amount, '0', self::SCALE) <= 0) {
                if ($index > 0) {
                    break;
                }

                continue;
            }

            $events[]  = ['date' => $date, 'amount' => $amount];
            $remaining = bcsub($remaining, $amount, self::SCALE);
        }

        return $events;
    }

    /**
     * What the events due after a day and up to another, the second included, come to.
     *
     * @return numeric-string
     */
    public function between(AssetTerms $terms, ?string $after, string $through): string
    {
        $sum = bcadd('0', '0', self::SCALE);

        foreach ($this->events($terms, $through) as $event) {
            if ($after === null || $event['date'] > $after) {
                $sum = bcadd($sum, $event['amount'], self::SCALE);
            }
        }

        return $sum;
    }

    /**
     * @return numeric-string
     */
    public function forUnits(AssetTerms $terms, string $units, string $postedSince): string
    {
        $remaining = bcsub(bcsub($terms->base(), $terms->openingAccumulated, self::SCALE), $postedSince, self::SCALE);

        if ($terms->costPerUnit === null || bccomp($remaining, '0', self::SCALE) <= 0 || bccomp($units, '0', self::SCALE) <= 0) {
            return bcadd('0', '0', self::SCALE);
        }

        return $this->capped(bcmul($units, $terms->costPerUnit, self::WORK), $remaining);
    }

    /**
     * The amount one event takes of what is left to depreciate, before it is rounded.
     */
    private function raw(AssetTerms $terms, string $remaining, string $ratio): string
    {
        if ($terms->method === AssetMethod::Declining) {
            return bcmul(bcmul(bcdiv($terms->percentage ?? '0', '100', self::WORK), $remaining, self::WORK), $ratio, self::WORK);
        }

        return bcmul($terms->amount ?? '0', $ratio, self::WORK);
    }

    /**
     * @return numeric-string
     */
    private function capped(string $raw, string $limit): string
    {
        $rounded = Money::round($raw, $this->decimals);

        return bcadd(bccomp($rounded, $limit, self::SCALE) > 0 ? $limit : $rounded, '0', self::SCALE);
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }
}
