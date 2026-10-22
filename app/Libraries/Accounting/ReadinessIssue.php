<?php

namespace App\Libraries\Accounting;

final readonly class ReadinessIssue
{
    public function __construct(
        public ReadinessCode $code,
        public ?string $subject = null,
    ) {
    }
}
