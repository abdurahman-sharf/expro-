<?php

namespace App\Libraries\Accounting;

/**
 * The shapes the trial balance is read in. Balances shows what each account stands at. Totals and
 * balances adds what was debited and credited in the period. Movement starts from where the
 * period opened.
 */
enum TrialBalanceType: string
{
    case Balances          = 'balances';
    case TotalsAndBalances = 'totals_balances';
    case Movement          = 'movement';

    public static function fromRequest(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::TotalsAndBalances;
    }

    public function showsOpening(): bool
    {
        return $this === self::Movement;
    }

    public function showsMovement(): bool
    {
        return $this !== self::Balances;
    }
}
