<?php

namespace App\Libraries\Accounting;

use DateTimeImmutable;

/**
 * The days a report counts: from the first day of the period, or from the start of the books when
 * there is none, to the last.
 */
final readonly class ReportPeriod
{
    private function __construct(
        public ?string $from,
        public string $to,
    ) {
    }

    /**
     * @throws AccountingException
     */
    public static function of(?string $from, string $to): self
    {
        $from = $from === null || trim($from) === '' ? null : self::date($from);
        $to   = self::date($to);

        if ($from !== null && $from > $to) {
            throw new AccountingException(AccountingError::ReportPeriodInvalid, 'The period ends before it starts.', ['from' => $from, 'to' => $to]);
        }

        return new self($from, $to);
    }

    /**
     * @throws AccountingException
     */
    private static function date(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        if ($date === false || $date->format('Y-m-d') !== trim($value)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date is not a valid date.');
        }

        return $date->format('Y-m-d');
    }
}
