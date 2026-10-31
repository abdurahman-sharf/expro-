<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\StockMovement;

/**
 * The stock movements that go with a journal, written when the journal is approved and not before,
 * so that the ledger of stock never holds a movement that no posted journal stands behind.
 */
final readonly class BackfillStock
{
    /**
     * @param list<StockMovement> $movements
     */
    public function __construct(
        public string $source,
        public int $sourceId,
        public JournalEntryType $entryType,
        public int $revision,
        public string $date,
        public int $branchId,
        public array $movements,
    ) {
    }
}
