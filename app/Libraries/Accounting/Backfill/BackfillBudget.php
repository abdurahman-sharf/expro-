<?php

namespace App\Libraries\Accounting\Backfill;

/**
 * How much work one run of an approval may do before it hands the rest to the next run: a number of
 * chunks, a number of seconds, or both, whichever is spent first. A budget that sets neither never runs out.
 */
final class BackfillBudget
{
    private int $spent = 0;
    private readonly float $startedAt;

    public function __construct(private readonly ?int $chunks = null, private readonly ?float $seconds = null)
    {
        $this->startedAt = microtime(true);
    }

    public function spend(): void
    {
        $this->spent++;
    }

    public function exhausted(): bool
    {
        return ($this->chunks !== null && $this->spent >= $this->chunks)
            || ($this->seconds !== null && microtime(true) - $this->startedAt >= $this->seconds);
    }
}
