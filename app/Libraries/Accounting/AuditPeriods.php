<?php

namespace App\Libraries\Accounting;

use DateTimeImmutable;

/**
 * The stretches of days the activity log offers beside a free choice of two days: the current month
 * runs from its first day to today, and the last month runs from the same day of the month before
 * (or the last day of that month when it is shorter) to today.
 */
final class AuditPeriods
{
    public const TODAY         = 'today';
    public const CURRENT_MONTH = 'current_month';
    public const LAST_MONTH    = 'last_month';
    public const ALL           = [self::TODAY, self::CURRENT_MONTH, self::LAST_MONTH];

    /**
     * @return array{from: string, to: string}|null Null for a name that is not one of them.
     */
    public static function resolve(string $preset, string $today): ?array
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $today);

        if ($day === false || $day->format('Y-m-d') !== $today) {
            return null;
        }

        return match ($preset) {
            self::TODAY         => ['from' => $today, 'to' => $today],
            self::CURRENT_MONTH => ['from' => $day->format('Y-m-01'), 'to' => $today],
            self::LAST_MONTH    => ['from' => self::sameDayLastMonth($day), 'to' => $today],
            default             => null,
        };
    }

    private static function sameDayLastMonth(DateTimeImmutable $day): string
    {
        $firstOfLast = $day->modify('first day of last month');
        $lastDay     = (int) $firstOfLast->format('t');

        return $firstOfLast->format('Y-m-') . str_pad((string) min((int) $day->format('j'), $lastDay), 2, '0', STR_PAD_LEFT);
    }
}
