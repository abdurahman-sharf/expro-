<?php

namespace App\Libraries\Recurring;

enum RecurrenceUnit: string
{
    case Days   = 'days';
    case Weeks  = 'weeks';
    case Months = 'months';
    case Years  = 'years';

    public function daysPerUnit(): int
    {
        return match ($this) {
            self::Days   => 1,
            self::Weeks  => 7,
            self::Months => 30,
            self::Years  => 365,
        };
    }

    public function languageKey(): string
    {
        return 'Recurring.unit_' . $this->value;
    }
}
