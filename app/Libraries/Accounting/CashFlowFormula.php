<?php

namespace App\Libraries\Accounting;

/**
 * What a line of the cash flow report takes from its accounts in the range: what was credited less
 * what was debited, what was debited less what was credited, only what was credited, or minus what
 * was debited. The first two are the ones the reference system offers.
 */
enum CashFlowFormula: string
{
    case CreditInPeriod      = 'credit_in_period';
    case DebitInPeriod       = 'debit_in_period';
    case CreditsInPeriod     = 'credits_in_period';
    case MinusDebitsInPeriod = 'minus_debits_in_period';

    /**
     * @return numeric-string What an account adds to a line that uses the formula.
     */
    public function of(string $debit, string $credit): string
    {
        return match ($this) {
            self::CreditInPeriod      => bcsub($credit, $debit, 4),
            self::DebitInPeriod       => bcsub($debit, $credit, 4),
            self::CreditsInPeriod     => bcadd($credit, '0', 4),
            self::MinusDebitsInPeriod => bcsub('0', $debit, 4),
        };
    }
}
