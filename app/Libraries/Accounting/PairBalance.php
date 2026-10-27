<?php

namespace App\Libraries\Accounting;

/**
 * What one branch is owed by another and what it owes it, from the journals of the first branch
 * alone. A pair agrees when what the other branch says it is owed or owes is the same amount the
 * other way round.
 */
final readonly class PairBalance
{
    public const RECORDED = 'recorded';
    public const DERIVED  = 'derived';
    public const MIXED    = 'mixed';

    /**
     * @param numeric-string $receivable What the other branch owes this one, from the journals that debited the account of what is owed.
     * @param numeric-string $payable    What this branch owes the other, from the journals that credited the account of what is owed.
     * @param numeric-string $owed       Receivable less payable: positive when the other branch owes this one, negative when this one owes it.
     * @param self::*        $basis      Whether the other branch of each journal was recorded with it, or read from the transfer it belongs to.
     */
    public function __construct(
        public int $branchId,
        public string $code,
        public int $counterpartId,
        public string $counterpartCode,
        public string $receivable,
        public string $payable,
        public string $owed,
        public string $basis,
        public bool $agrees,
    ) {
    }

    /**
     * The rule of the balances screen and of the settlement: what one branch is owed is what the
     * other owes it, and what it owes is what the other is owed, each on its own.
     *
     * @param numeric-string $receivable
     * @param numeric-string $payable
     * @param numeric-string $otherReceivable
     * @param numeric-string $otherPayable
     */
    public static function agree(string $receivable, string $payable, string $otherReceivable, string $otherPayable): bool
    {
        return bccomp($receivable, $otherPayable, SaleAmounts::SCALE) === 0 && bccomp($payable, $otherReceivable, SaleAmounts::SCALE) === 0;
    }
}
