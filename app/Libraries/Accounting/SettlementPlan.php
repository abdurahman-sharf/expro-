<?php

namespace App\Libraries\Accounting;

/**
 * What settling two branches on a day would do, worked out from their journals and written
 * nothing. Each branch clears what it is owed and what it owes the other. What is left between
 * them is paid in cash by the branch that owes it, or is nothing when the two offset exactly.
 */
final readonly class SettlementPlan
{
    public const CASH    = 'cash';
    public const NETTING = 'netting';

    /**
     * @param numeric-string $firstReceivable  What the first branch is owed by the second.
     * @param numeric-string $firstPayable     What the first branch owes the second.
     * @param numeric-string $secondReceivable What the second branch is owed by the first.
     * @param numeric-string $secondPayable    What the second branch owes the first.
     * @param numeric-string $amount           The cash that goes from one treasury to the other, nothing for a netting.
     * @param self::*|null   $kind             Null when there is nothing to settle.
     */
    public function __construct(
        public int $firstBranchId,
        public string $firstCode,
        public int $secondBranchId,
        public string $secondCode,
        public string $date,
        public string $firstReceivable,
        public string $firstPayable,
        public string $secondReceivable,
        public string $secondPayable,
        public bool $agrees,
        public ?string $kind,
        public string $amount,
        public ?int $payerBranchId,
        public ?int $payeeBranchId,
    ) {
    }

    public function canBeSettled(): bool
    {
        return $this->agrees && $this->kind !== null;
    }
}
