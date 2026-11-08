<?php

namespace App\Libraries\Documents;

/**
 * What a voucher or a receipt prints: its title and number, the party, the amount in figures and in
 * words, the rows that say what it was for, and the signatures it asks for. It is read from a document
 * that exists and holds nothing the document does not hold.
 */
final readonly class VoucherDocument
{
    public const PAYMENT_VOUCHER  = 'payment_voucher';
    public const RECEIPT_VOUCHER  = 'receipt_voucher';
    public const CUSTOMER_RECEIPT = 'customer_receipt';
    public const SUPPLIER_RECEIPT = 'supplier_receipt';

    /**
     * @param numeric-string                    $amount     What the document came to, as a positive amount.
     * @param list<array{0: string, 1: string}> $rows       A label and a value each.
     * @param list<string>                      $signatures
     */
    public function __construct(
        public string $kind,
        public string $title,
        public string $number,
        public string $date,
        public string $partyLabel,
        public string $partyName,
        public string $amount,
        public string $amountText,
        public string $currencyCode,
        public array $rows,
        public array $signatures,
        public ?string $amountWords,
        public int $branchId,
    ) {
    }
}
