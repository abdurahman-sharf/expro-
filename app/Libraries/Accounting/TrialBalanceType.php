<?php

namespace App\Libraries\Accounting;

/**
 * The shapes the trial balance is read in. Balances shows what each account stands at. Totals and
 * balances adds what was debited and credited in the period. Movement starts from where the
 * period opened, as a net amount. Review totals starts from what each side had added up to when the
 * period opened and ends at what each side adds up to now, the way a review of the accounts is
 * usually drawn.
 */
enum TrialBalanceType: string
{
    case Balances          = 'balances';
    case TotalsAndBalances = 'totals_balances';
    case Movement          = 'movement';
    case ReviewTotals      = 'review_totals';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::TotalsAndBalances;
    }

    public function showsOpening(): bool
    {
        return $this === self::Movement || $this === self::ReviewTotals;
    }

    public function showsMovement(): bool
    {
        return $this !== self::Balances;
    }

    public function isGross(): bool
    {
        return $this === self::ReviewTotals;
    }
}
