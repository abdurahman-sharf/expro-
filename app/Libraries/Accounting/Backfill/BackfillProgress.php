<?php

namespace App\Libraries\Accounting\Backfill;

/**
 * Where the approval of a batch stands: whether its documents are still being checked against the
 * preview, its journals are being posted or it is done, and how many of how many are through.
 */
final readonly class BackfillProgress
{
    public const IDLE      = 'idle';
    public const VERIFYING = 'verifying';
    public const POSTING   = 'posting';
    public const DONE      = 'done';

    public function __construct(public string $phase, public int $done, public int $total)
    {
    }

    public function isFinished(): bool
    {
        return $this->phase === self::DONE;
    }
}
