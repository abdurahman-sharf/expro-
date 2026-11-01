<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\JournalDraft;

final readonly class BackfillStep
{
    public function __construct(
        public ?JournalDraft $draft,
        public ?BackfillStock $stock = null,
    ) {
    }
}
