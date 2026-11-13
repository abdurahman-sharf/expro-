<?php

namespace App\Libraries\Assets;

use DateTimeImmutable;

final readonly class DepreciationPeriod
{
    private function __construct(public string $start, public string $end)
    {
    }

    public static function fromMonth(string $month): ?self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $month, $parts) !== 1) {
            return null;
        }

        $first = DateTimeImmutable::createFromFormat('!Y-m-d', "{$parts[1]}-{$parts[2]}-01");

        if ($first === false || $first->format('Y-m') !== $month) {
            return null;
        }

        return new self($first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d'));
    }

    public static function containing(string $date): self
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return new self($day->modify('first day of this month')->format('Y-m-d'), $day->modify('last day of this month')->format('Y-m-d'));
    }

    public function label(): string
    {
        return substr($this->start, 0, 7);
    }

    public function previous(): self
    {
        return self::containing((new DateTimeImmutable($this->start))->modify('-1 day')->format('Y-m-d'));
    }

    public function next(): self
    {
        return self::containing((new DateTimeImmutable($this->end))->modify('+1 day')->format('Y-m-d'));
    }

    public function hasEnded(string $today): bool
    {
        return $this->end < $today;
    }
}
