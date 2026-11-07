<?php

namespace App\Libraries\Recurring;

/**
 * The words that say how often a schedule repeats and when a reminder falls, with the noun in the number
 * the count asks for (one day, two days, eleven days). The count is given twice: once as the number the
 * plural rule reads and once as the text that is printed, so the digits stay the ones the page is written in.
 */
final class RecurrencePhrase
{
    public static function every(int $count, RecurrenceUnit $unit): string
    {
        return lang('Recurring.every', [$count, $unit->value, (string) $count]);
    }

    public static function reminder(int $sendWhen, ?int $days): string
    {
        $count = $days ?? 0;

        return lang('Recurring.reminder_when_' . $sendWhen, [$count, (string) $count]);
    }
}
