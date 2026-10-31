<?php

namespace App\Libraries\Accounting;

final readonly class ChequeDocument
{
    /**
     * @param numeric-string $amount
     */
    public function __construct(
        public int $chequeId,
        public ChequeDirection $direction,
        public ChequeStatus $status,
        public int $partyId,
        public int $treasuryId,
        public int $branchId,
        public string $chequeNumber,
        public string $amount,
        public string $issueDate,
        public string $dueDate,
    ) {
    }
}
