<?php

namespace App\Libraries\Accounting;

/**
 * A saved expense in the shape posting needs. The amount is what was paid, tax included, and the
 * tax is the part of it that is tax. Both carry the sign of the expense: a negative expense is
 * money received back.
 */
final readonly class ExpenseDocument
{
    /**
     * @param numeric-string $amount
     * @param numeric-string $taxAmount
     */
    public function __construct(
        public int $expenseId,
        public string $date,
        public string $amount,
        public string $taxAmount,
        public string $paymentType,
        public ?int $categoryAccountId,
        public ?int $treasuryId,
        public int $branchId,
        public bool $deleted,
        public ?int $treasuryBranchId = null,
    ) {
    }
}
