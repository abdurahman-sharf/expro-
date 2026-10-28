<?php

namespace App\Libraries\Accounting;

use DateTimeImmutable;

/**
 * The date a person enters for a payment to a supplier: a real day, no later than today and, while
 * the books are on, no earlier than the day they start. A payment records money that has gone out,
 * so it is not post-dated; a payment that is due later is a cheque, which has its own cycle. The
 * books then post it on that day, and the closing of the period it falls in is asked by the posting,
 * as for every other journal. A payment saved before there was a date to enter has none, and is
 * shown, and was posted, on the day it was saved.
 */
final class SupplierPaymentDate
{
    /**
     * @param string|null $booksStart The day the books start, when they are on and have one.
     *
     * @throws AccountingException
     */
    public static function entered(mixed $value, ?string $today = null, ?string $booksStart = null): string
    {
        $text = is_string($value) ? trim($value) : '';
        $day  = DateTimeImmutable::createFromFormat('!Y-m-d', $text);

        if ($day === false || $day->format('Y-m-d') !== $text) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date of the payment is not a valid date.');
        }

        if ($text > ($today ?? date('Y-m-d'))) {
            throw new AccountingException(AccountingError::PaymentDateInFuture, 'A payment cannot be dated after today.', ['date' => $text]);
        }

        if ($booksStart !== null && $text < $booksStart) {
            throw new AccountingException(AccountingError::PaymentDateBeforeBooks, 'A payment cannot be dated before the day the books start.', ['date' => $text, 'until' => $booksStart]);
        }

        return $text;
    }

    /**
     * The day a payment made against a purchase is posted on and shown on: the date entered, or for
     * a payment saved before one could be entered, the day it was saved.
     */
    public static function of(?string $entered, string $savedAt): string
    {
        return $entered !== null && $entered !== '' ? substr($entered, 0, 10) : substr($savedAt, 0, 10);
    }
}
