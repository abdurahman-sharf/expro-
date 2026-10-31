<?php

namespace App\Libraries\Accounting;

use DateTimeImmutable;

/**
 * How the range of a profit and loss statement is cut into columns: calendar months, calendar
 * quarters or calendar years. A column is named by the month, the quarter or the year it stands
 * for, whether or not the range cuts it short.
 */
enum ProfitPeriodKind: string
{
    case Monthly   = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly    = 'yearly';

    public static function fromRequest(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(trim($value)) ?? self::Monthly) : self::Monthly;
    }

    /**
     * The first and the last day of the calendar period the day falls in.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}
     */
    public function around(DateTimeImmutable $day): array
    {
        $year  = (int) $day->format('Y');
        $month = (int) $day->format('n');

        [$first, $last] = match ($this) {
            self::Monthly   => [$month, $month],
            self::Quarterly => [intdiv($month - 1, 3) * 3 + 1, intdiv($month - 1, 3) * 3 + 3],
            self::Yearly    => [1, 12],
        };

        $start = $day->setDate($year, $first, 1)->setTime(0, 0);

        return [$start, $day->setDate($year, $last, 1)->modify('last day of this month')->setTime(0, 0)];
    }

    public function label(DateTimeImmutable $start): string
    {
        return match ($this) {
            self::Monthly   => $start->format('Y-m'),
            self::Quarterly => 'Q' . (intdiv((int) $start->format('n') - 1, 3) + 1) . ' ' . $start->format('Y'),
            self::Yearly    => $start->format('Y'),
        };
    }
}
