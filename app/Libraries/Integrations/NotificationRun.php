<?php

namespace App\Libraries\Integrations;

final readonly class NotificationRun
{
    /**
     * @param list<array{rule: NotificationRule, created: int, resolved: int, open: int}> $lines
     */
    public function __construct(
        public bool $skipped,
        public string $today,
        public array $lines,
    ) {
    }

    public function created(): int
    {
        return array_sum(array_column($this->lines, 'created'));
    }

    public function resolved(): int
    {
        return array_sum(array_column($this->lines, 'resolved'));
    }

    public function open(): int
    {
        return array_sum(array_column($this->lines, 'open'));
    }
}
