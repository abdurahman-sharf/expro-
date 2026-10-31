<?php

namespace App\Libraries\Accounting;

use DateTimeImmutable;

/**
 * What an invoice carries about time besides the moment it was saved: the date typed on it, the
 * payment terms in days and the due date they give. A date is typed as a whole day, is not later
 * than today and not before the books start, since the journal of the document takes that day.
 * Terms are a whole number of days, and none (or nothing typed) means the invoice is due on its
 * own date.
 */
final class InvoiceTiming
{
    public const MAX_TERMS_DAYS = 3650;

    /**
     * @throws AccountingException
     */
    public static function date(?string $typed, string $today, ?string $booksStart = null): ?string
    {
        $typed = trim((string) $typed);

        if ($typed === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $typed);

        if ($parsed === false || $parsed->format('Y-m-d') !== $typed || $typed > $today) {
            throw new AccountingException(AccountingError::InvoiceDateInvalid, "The invoice date {$typed} is not a day up to today.", ['date' => $typed]);
        }

        if ($booksStart !== null && $typed < $booksStart) {
            throw new AccountingException(AccountingError::InvoiceDateBeforeStart, "The invoice date {$typed} is before the books start on {$booksStart}.", ['date' => $typed, 'min' => $booksStart]);
        }

        return $typed;
    }

    /**
     * @throws AccountingException
     */
    public static function terms(int|string|null $typed): ?int
    {
        if ($typed === null || (is_string($typed) && trim($typed) === '')) {
            return null;
        }

        $text = is_int($typed) ? (string) $typed : trim($typed);

        if (! ctype_digit($text) || strlen($text) > 5 || (int) $text > self::MAX_TERMS_DAYS) {
            throw new AccountingException(AccountingError::PaymentTermsInvalid, 'The payment terms are a number of days.', ['max' => self::MAX_TERMS_DAYS]);
        }

        return (int) $text === 0 ? null : (int) $text;
    }

    public static function dueDate(string $invoiceDate, ?int $terms): string
    {
        return $terms === null ? $invoiceDate : (new DateTimeImmutable($invoiceDate))->modify("+{$terms} days")->format('Y-m-d');
    }

    /**
     * Whether an invoice with something still owed is late: it is when its due date is before
     * the day, and an invoice that falls due today is not.
     */
    public static function isOverdue(string $dueDate, string $today, bool $owing): bool
    {
        return $owing && $dueDate < $today;
    }
}
