<?php

namespace App\Libraries\Recurring;

enum ScheduleStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended  = 'ended';

    public function languageKey(): string
    {
        return 'Recurring.status_' . $this->value;
    }

    /**
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Active => [self::Paused, self::Ended],
            self::Paused => [self::Active, self::Ended],
            self::Ended  => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}
