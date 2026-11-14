<?php

namespace App\Libraries\Database;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The condition that keeps the rows of a time column that fall on the days from one to another, written on the
 * raw column so that the index of the column serves it: from the first moment of the first day up to, and not
 * including, the first moment of the day after the last. DATE(column) cannot use the index and reads the whole
 * table. The two give the same rows because both read the moment in the time zone of the connection; a bound that
 * is not a day (a time was typed with it) keeps the DATE() form, which is what it always meant.
 */
final class DayRange
{
    public static function condition(BaseConnection $db, string $column, ?string $firstDay, ?string $lastDay): string
    {
        $dayAfter = self::dayAfter($lastDay);

        if (self::isDay($firstDay) && $dayAfter !== null) {
            return '(' . $column . ' >= ' . $db->escape($firstDay . ' 00:00:00') . ' AND ' . $column . ' < ' . $db->escape($dayAfter . ' 00:00:00') . ')';
        }

        return 'DATE(' . $column . ') BETWEEN ' . $db->escape($firstDay) . ' AND ' . $db->escape($lastDay);
    }

    private static function isDay(?string $day): bool
    {
        if ($day === null) {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $day;
    }

    private static function dayAfter(?string $day): ?string
    {
        if ($day === null || ! self::isDay($day)) {
            return null;
        }

        $after = (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

        return strlen($after) === 10 ? $after : null;
    }
}
