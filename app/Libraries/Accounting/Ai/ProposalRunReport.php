<?php

namespace App\Libraries\Accounting\Ai;

final readonly class ProposalRunReport
{
    /**
     * @param array<string, int>          $created    The proposals made, by proposer.
     * @param array<string, int>          $duplicates The suggestions that were standing already, by proposer.
     * @param list<array{proposer: string, key: string, reason: string}> $refused What the checks of a manual journal refused, with the reason.
     */
    public function __construct(
        public int $expired,
        public array $created,
        public array $duplicates,
        public array $refused,
    ) {
    }

    public function createdCount(): int
    {
        return array_sum($this->created);
    }

    public function duplicateCount(): int
    {
        return array_sum($this->duplicates);
    }
}
