<?php

namespace App\Libraries\Stock;

/**
 * A stock permit with what posting needs to know about it. The branch is the one of the stock location
 * the stock leaves or enters, and a transfer between locations of two branches names the other branch
 * too, with the codes of the two for the description of the journals.
 */
final readonly class StockPermitDocument
{
    /**
     * @param list<StockPermitLine> $lines
     * @param array<int, string>    $branchCodes
     */
    public function __construct(
        public int $permitId,
        public StockPermitType $type,
        public string $date,
        public int $branchId,
        public ?int $toBranchId,
        public int $locationId,
        public ?int $toLocationId,
        public ?int $accountId,
        public string $label,
        public array $lines,
        public array $branchCodes = [],
    ) {
    }
}
