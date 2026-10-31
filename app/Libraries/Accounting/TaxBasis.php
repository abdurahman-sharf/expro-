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
     * @param mixed $value What the request holds under the name of the basis: nothing or an empty text
     *                     is the accrual basis, and anything that is not the name of one is refused,
     *                     a list as well, which is how a hand-made address names a basis twice.
     *
     * @throws AccountingException
     */
    public static function fromRequest(mixed $value): self
    {
        if ($value === null) {
            return self::Accrual;
        }

        $text  = is_string($value) ? trim($value) : null;
        $basis = $text === '' ? self::Accrual : ($text === null ? null : self::tryFrom($text));

        if ($basis === null) {
            throw new AccountingException(AccountingError::TaxBasisUnavailable, 'The basis asked for is not one the tax report knows.', ['basis' => $text ?? get_debug_type($value)]);
        }

        return $basis;
    }
}
