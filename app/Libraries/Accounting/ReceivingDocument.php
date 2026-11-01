<?php

namespace App\Libraries\Accounting;

/**
 * A saved purchase, with what posting needs to know about it. Its date is the day its journals
 * take: the date typed on the invoice, or else the day it was saved. A return carries the purchase
 * it takes goods back from, because it can only give back what that purchase brought in. A purchase in a
 * foreign currency carries the currency and the rate it was captured at, and every amount of its lines is in
 * that currency; one without them is in the base currency.
 */
final readonly class ReceivingDocument
{
    /**
     * @param list<ReceivingLine>   $lines
     * @param list<SupplierPayment> $payments    The payments made against the purchase after it was saved.
     * @param int|null              $treasuryId  The treasury the purchase was paid from at once, when it was.
     * @param int|null              $toBranchId  The branch the stock of a transfer goes to when it leaves the branch of the document for another.
     * @param array<int, string>    $branchCodes The codes of the two branches of a transfer between branches, by branch.
     */
    public function __construct(
        public int $receivingId,
        public string $date,
        public ReceivingType $type,
        public ?int $supplierId,
        public int $branchId,
        public ?string $paymentType,
        public ?int $returnOfReceivingId,
        public array $lines,
        public ?self $origin = null,
        public array $payments = [],
        public ?int $treasuryId = null,
        public ?int $toBranchId = null,
        public array $branchCodes = [],
        public ?string $savedOn = null,
        public ?DocumentCurrency $currency = null,
    ) {
    }
}
