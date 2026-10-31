<?php

namespace App\Libraries\Accounting;

/**
 * A line of a profit and loss statement by cash: what the customers paid, what was paid to the
 * suppliers, the tax paid on expenses, or what came in through an account of the chart. An amount
 * is what the line adds to the revenue, or to the expenses, or to the other movements.
 */
final readonly class CashProfitLine
{
    public const CUSTOMERS = 'customers';
    public const SUPPLIERS = 'suppliers';
    public const TAX_INPUT = 'tax_input';
    public const ACCOUNT   = 'account';

    /**
     * @param numeric-string $amount At four decimals.
     */
    public function __construct(
        public string $kind,
        public string $amount,
        public ?int $accountId = null,
        public ?string $code = null,
        public ?string $name = null,
    ) {
    }
}
