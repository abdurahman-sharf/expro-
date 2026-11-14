<?php

namespace App\Libraries\Accounting;

/**
 * What the order of E57 does to the credit of one customer, read and not written. The moves are what the
 * next settlement run of the customer would change, which is nothing for a customer whose credit and open
 * items are settled. The differences are the items whose standing applications are not what the order would
 * give if the credit were applied from nothing, which a run does not rewrite. A customer whose run the books
 * would refuse has the refusal and no moves.
 */
final readonly class CreditOrderPlan
{
    /**
     * @param list<CreditOrderMove> $moves
     * @param list<CreditOrderMove> $differences
     */
    public function __construct(
        public int $customerId,
        public string $customerName,
        public array $moves,
        public array $differences,
        public ?AccountingError $blocked = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->moves === [] && $this->differences === [] && $this->blocked === null;
    }
}
