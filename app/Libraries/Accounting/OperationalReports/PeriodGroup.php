<?php

namespace App\Libraries\Accounting\OperationalReports;

use DateTimeImmutable;

/**
 * The calendar period a report is grouped by. A week runs from Monday to Sunday and a month and a
 * year are the calendar ones; the key of a bucket is the first day of it, so buckets sort by their key.
 */
enum PeriodGroup: string
{
    case Day   = 'day';
    case Week  = 'week';
    case Month = 'month';
    case Year  = 'year';

    public const MAX_BUCKETS = 800;

    public function bucketOf(string $date): PeriodBucket
    {
        $day = new DateTimeImmutable($date);

        return match ($this) {
            self::Day   => new PeriodBucket($this, $day->format('Y-m-d'), $day->format('Y-m-d'), $day->format('Y-m-d')),
            self::Week  => $this->weekOf($day),
            self::Month => new PeriodBucket($this, $day->format('Y-m-01'), $day->format('Y-m-01'), $day->format('Y-m-t')),
            self::Year  => new PeriodBucket($this, $day->format('Y-01-01'), $day->format('Y-01-01'), $day->format('Y-12-31')),
        };
    }

    /**
     * Every bucket from the one that holds the first day to the one that holds the last, those
     * with no document included. More than the limit gives none, and the report then lists the
     * buckets that have documents only.
     *
     * @return list<PeriodBucket>
     */
    public function buckets(string $from, string $to): array
    {
        $buckets = [];
        $bucket  = $this->bucketOf($from);
        $last    = $this->bucketOf($to)->key;

        while ($bucket->key <= $last) {
            $buckets[] = $bucket;

            if (count($buckets) > self::MAX_BUCKETS) {
                return [];
            }

            $bucket = $this->bucketOf((new DateTimeImmutable($bucket->to))->modify('+1 day')->format('Y-m-d'));
        }

        return $buckets;
    }

    /**
     * The first day a report of this grouping starts from when it is not given one: a month of days,
     * three months of weeks and twelve months of months, and every year there is for years.
     */
    public function defaultFrom(string $to): ?string
    {
        $day = new DateTimeImmutable($to);

        return match ($this) {
            self::Day   => $day->modify('-1 month')->format('Y-m-d'),
            self::Week  => $day->modify('-3 months')->format('Y-m-d'),
            self::Month => $day->modify('first day of this month')->modify('-11 months')->format('Y-m-d'),
            self::Year  => null,
        };
    }

    private function weekOf(DateTimeImmutable $day): PeriodBucket
    {
        $start = $day->modify('-' . ((int) $day->format('N') - 1) . ' days');

        return new PeriodBucket($this, $start->format('Y-m-d'), $start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d'));
    }
}
