<?php

namespace App\Libraries\Accounting;

/**
 * A saved receipt voucher in the shape posting needs. The amount is what was received, tax included,
 * and the tax is the part of it that is tax.
 */
final readonly class RevenueDocument
{
    /**
     * @param numeric-string $amount
     * @param numeric-string $taxAmount
     */
    public function __construct(
        public int $revenueId,
        public string $date,
        public string $amount,
        public string $taxAmount,
        public string $paymentType,
        public ?int $accountId,
        public ?int $categoryAccountId,
        public ?int $treasuryId,
        public int $branchId,
        public bool $deleted,
        public ?int $treasuryBranchId = null,
    ) {
    }
}
