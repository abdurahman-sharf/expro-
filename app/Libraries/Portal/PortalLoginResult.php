<?php

namespace App\Libraries\Portal;

final readonly class PortalLoginResult
{
    public function __construct(
        public PortalLoginOutcome $outcome,
        public ?object $account = null,
    ) {
    }
}
