<?php

namespace App\Libraries\Accounting;

/**
 * One account of a statement drawn account by account: where it stood when the period opened, its
 * lines with the balance after each, what the period debited and credited, and where it ends. The
 * balances are debit minus credit, so a credit balance is below zero.
 */
final readonly class LedgerBlock
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param numeric-string             $opening
     * @param numeric-string             $debit
     * @param numeric-string             $credit
     * @param numeric-string             $closing
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public bool $isEntity,
        public array $rows,
        public string $opening,
        public string $debit,
        public string $credit,
        public string $closing,
    ) {
    }
}
