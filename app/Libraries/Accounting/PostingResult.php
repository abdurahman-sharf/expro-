<?php

namespace App\Libraries\Accounting;

final readonly class PostingResult
{
    public function __construct(
        public int $journalId,
        public bool $created,
    ) {
    }
}
