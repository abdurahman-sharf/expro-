<?php

namespace App\Libraries\Integrations;

final readonly class NotificationRule
{
    public function __construct(
        public int $id,
        public NotificationRuleKind $kind,
        public string $name,
        public ?int $threshold,
        public bool $active,
    ) {
    }
}
