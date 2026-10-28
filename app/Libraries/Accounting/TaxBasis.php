<?php

namespace App\Libraries\Accounting;

/**
 * The day a document counts in the tax report: the accrual basis counts it on the day it is issued,
 * and the cash basis would count it on the day it is paid. Only the first exists. The second is
 * named so that asking for it is refused in words, not answered with the first.
 */
enum TaxBasis: string
{
    case Accrual = 'accrual';
    case Cash    = 'cash';

    /**
     * @throws AccountingException
     */
    public static function fromRequest(string $value): self
    {
        $value = trim($value);
        $basis = $value === '' ? self::Accrual : self::tryFrom($value);

        if ($basis === null) {
            throw new AccountingException(AccountingError::TaxBasisUnavailable, 'The basis asked for is not one the tax report knows.', ['basis' => $value]);
        }

        return $basis;
    }
}
