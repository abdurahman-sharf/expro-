<?php

namespace App\Libraries\Accounting;

use DateTimeImmutable;

/**
 * The six buckets what is owed is aged in: the day itself, then four stretches of a period each
 * (30 days by default, so 1 to 30, 31 to 60, 61 to 90 and 91 to 120), then everything older. The
 * age is the days from the date of the document to the day of the report, so a document of the day
 * is in the first bucket and one dated after the day of the report is in none.
 */
final class AgingBuckets
{
    public const KEYS           = ['today', '1-30', '31-60', '61-90', '91-120', '120+'];
    public const LANGUAGE_KEYS  = ['today' => 'age_today', '1-30' => 'age_1_30', '31-60' => 'age_31_60', '61-90' => 'age_61_90', '91-120' => 'age_91_120', '120+' => 'age_over_120'];
    public const DEFAULT_PERIOD = 30;
    public const MAX_PERIOD     = 365;

    /**
     * @return array<string, numeric-string>
     */
    public static function empty(): array
    {
        return array_fill_keys(self::KEYS, '0.0000');
    }

    public static function ageInDays(string $date, string $asOf): int
    {
        return (int) (new DateTimeImmutable($date))->diff(new DateTimeImmutable($asOf))->format('%r%a');
    }

    public static function indexOf(int $age, int $period = self::DEFAULT_PERIOD): int
    {
        return $age <= 0 ? 0 : min(5, intdiv($age - 1, max(1, $period)) + 1);
    }

    /**
     * Spreads what is open over the buckets by the age of each document on the day of the report.
     * A document dated after that day is not counted.
     *
     * @param iterable<array{0: string, 1: string}> $documents The date and the amount of each document.
     *
     * @return list<numeric-string>
     */
    public static function spread(iterable $documents, string $asOf, int $period = self::DEFAULT_PERIOD): array
    {
        $buckets = array_fill(0, 6, '0.0000');

        foreach ($documents as [$date, $amount]) {
            $age = self::ageInDays($date, $asOf);

            if ($age < 0) {
                continue;
            }

            $index           = self::indexOf($age, $period);
            $buckets[$index] = bcadd($buckets[$index], $amount, SaleAmounts::SCALE);
        }

        return $buckets;
    }

    /**
     * @param iterable<array{0: string, 1: string}> $documents
     *
     * @return array<string, numeric-string>
     */
    public static function named(iterable $documents, string $asOf): array
    {
        return array_combine(self::KEYS, self::spread($documents, $asOf));
    }

    /**
     * The ranges the buckets cover for a period, the first being the day itself.
     *
     * @return list<string>
     */
    public static function ranges(int $period = self::DEFAULT_PERIOD): array
    {
        $period = max(1, $period);

        return [
            '',
            '1 - ' . $period,
            ($period + 1) . ' - ' . (2 * $period),
            (2 * $period + 1) . ' - ' . (3 * $period),
            (3 * $period + 1) . ' - ' . (4 * $period),
            (4 * $period) . '+',
        ];
    }

    /**
     * @param list<numeric-string> $buckets
     *
     * @return numeric-string
     */
    public static function total(array $buckets): string
    {
        $total = '0';

        foreach ($buckets as $amount) {
            $total = bcadd($total, $amount, SaleAmounts::SCALE);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }
}
