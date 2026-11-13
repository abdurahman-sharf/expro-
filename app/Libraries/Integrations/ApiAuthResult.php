<?php

namespace App\Libraries\Integrations;

final readonly class ApiAuthResult
{
    public function __construct(
        public ?ApiKey $key,
        public ?ApiFailure $failure,
        public int $retryAfter = 0,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->key !== null;
    }
}
