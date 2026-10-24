<?php

namespace App\Libraries\Accounting;

/**
 * What a posting of stock writes: the journal, which is null when the goods are worth nothing in
 * total, and the movements that give the journal its stock lines. What the journal puts on the
 * account of a stock location is the sum of the values of the movements in that location.
 */
final readonly class StockPostingPlan
{
    /**
     * @param list<StockMovement> $movements
     */
    public function __construct(
        public ?JournalDraft $journal,
        public array $movements,
    ) {
    }
}
