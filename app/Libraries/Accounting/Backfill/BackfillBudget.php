<?php

namespace App\Libraries\Accounting\Backfill;

/**
 * How much work one run of an approval may do before it hands the rest to the next run: a number of
 * chunks, a number of seconds, or both, whichever is spent first. A run does not start a chunk that
 * would end after the seconds it has, judging by the slowest chunk it has done so far. A budget that sets
 * neither never runs out.
 */
final class BackfillBudget
{
    private int $spent = 0;
    private readonly float $startedAt;
    private float $lastChunkEndedAt;
    private float $slowest = 0.0;

    public function __construct(private readonly ?int $chunks = null, private readonly ?float $seconds = null)
    {
        $this->startedAt        = microtime(true);
        $this->lastChunkEndedAt = $this->startedAt;
    }

    public function spend(): void
    {
        $now = microtime(true);

        $this->slowest          = max($this->slowest, $now - $this->lastChunkEndedAt);
        $this->lastChunkEndedAt = $now;

        $this->spent++;
    }

    public function exhausted(): bool
    {
        return ($this->chunks !== null && $this->spent >= $this->chunks)
            || ($this->seconds !== null && microtime(true) - $this->startedAt + $this->slowest >= $this->seconds);
    }
}
