<?php

namespace App\Libraries\Accounting;

/**
 * What a posting of stock writes: the journal, which is null when the goods are worth nothing in
 * total, and the movements that give the journal its stock lines. What the journal puts on the
 * account of a stock location is the sum of the values of the movements in that location. Stock
 * that goes from one branch to another has a second journal, the receipt in the branch it goes to,
 * with the movements that came in there.
 */
final readonly class StockPostingPlan
{
    /**
     * @param list<StockMovement> $movements
     * @param list<StockMovement> $receiptMovements
     */
    public function __construct(
        public ?JournalDraft $journal,
        public array $movements,
        public ?JournalDraft $receipt = null,
        public array $receiptMovements = [],
    ) {
    }
}
