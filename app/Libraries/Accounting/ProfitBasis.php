<?php

namespace App\Libraries\Accounting;

/**
 * The way a profit and loss statement recognises revenue and expense: on the books as they stand
 * (accrual), or on the day money moved through a treasury or a bank (cash). Daftra also counts by
 * the day an invoice is issued, for all invoices or for the paid ones; those two are named here only
 * so that asking for them is refused in words, because their rule has not been fully observed.
 */
enum ProfitBasis: string
{
    case Accrual = 'accrual';
    case Cash    = 'cash';

    private const ALIASES = ['payment' => self::Cash];

    /**
     * @param mixed $value What the request holds under the name of the basis: nothing or an empty text
     *                     is the accrual basis, `payment` is another name of the cash basis, and
     *                     anything else is refused, a list as well, which is how a hand-made address
     *                     names a basis twice.
     *
     * @throws AccountingException
     */
    public static function fromRequest(mixed $value): self
    {
        if ($value === null) {
            return self::Accrual;
        }

        $text  = is_string($value) ? trim($value) : null;
        $basis = $text === '' ? self::Accrual : ($text === null ? null : (self::tryFrom($text) ?? self::ALIASES[$text] ?? null));

        if ($basis === null) {
            throw new AccountingException(AccountingError::ProfitBasisUnavailable, 'The basis asked for is not one the profit and loss statement knows.', ['basis' => $text ?? get_debug_type($value)]);
        }

        return $basis;
    }
}
