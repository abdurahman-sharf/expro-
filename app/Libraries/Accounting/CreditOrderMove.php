<?php

namespace App\Libraries\Accounting;

/**
 * What stands on one open item of a customer (an invoice, an opening balance or a sales debit note) from
 * credit, by the credit it came from, and what would stand on it afterwards.
 */
final readonly class CreditOrderMove
{
    public const INVOICE    = 'invoice';
    public const OPENING    = 'opening';
    public const DEBIT_NOTE = 'debit_note';

    /**
     * @param array<string, numeric-string> $before By source, as "type:id".
     * @param array<string, numeric-string> $after
     */
    public function __construct(
        public int $key,
        public string $kind,
        public int $documentId,
        public string $date,
        public array $before,
        public array $after,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function beforeTotal(): string
    {
        return self::total($this->before);
    }

    /**
     * @return numeric-string
     */
    public function afterTotal(): string
    {
        return self::total($this->after);
    }

    /**
     * @param array<string, numeric-string> $amounts
     *
     * @return numeric-string
     */
    private static function total(array $amounts): string
    {
        return array_reduce($amounts, static fn (string $sum, string $amount): string => bcadd($sum, $amount, SaleAmounts::SCALE), '0.0000');
    }
}
