<?php

namespace App\Libraries\Accounting;

/**
 * A saved cash-up in the shape posting needs. The difference is the cash that was counted less the
 * cash that was expected, so a shortage is negative. It is null while the cash-up is still open,
 * because nothing has been counted yet.
 */
final readonly class CashupDocument
{
    /**
     * @param numeric-string|null $difference
     */
    public function __construct(
        public int $cashupId,
        public string $date,
        public ?string $difference,
        public ?int $treasuryId,
        public int $branchId,
        public bool $deleted,
    ) {
    }
}
