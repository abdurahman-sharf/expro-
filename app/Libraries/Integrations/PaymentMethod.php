<?php

namespace App\Libraries\Integrations;

use App\Libraries\Accounting\PaymentType;

final readonly class PaymentMethod
{
    public function __construct(
        public PaymentType $type,
        public bool $enabled,
        public ?string $customName,
        public string $defaultName,
        public bool $mayBeSwitchedOff,
    ) {
    }

    public function shownAs(): string
    {
        return $this->customName ?? $this->defaultName;
    }
}
