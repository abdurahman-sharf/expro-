<?php

namespace App\Libraries\EInvoicing;

/**
 * The document the standard asks for, as the books state it: the figures are those of the sale or the note
 * with the roundings the standard asks for, never worked out a second way. Every amount is positive whatever
 * the kind (a credit note is told by its type code), with two decimals. The counter, the hash of the document
 * before it and the identifier are given when the document is issued, under the lock of the counter.
 */
final readonly class EInvoiceDocument
{
    /**
     * @param list<EInvoiceLine>       $lines
     * @param list<EInvoiceTaxSubtotal> $taxSubtotals
     * @param list<string>             $paymentMeansCodes
     */
    public function __construct(
        public EInvoiceKind $kind,
        public EInvoiceSubtype $subtype,
        public string $number,
        public string $issueDate,
        public string $issueTime,
        public string $supplyDate,
        public EInvoiceParty $seller,
        public EInvoiceParty $buyer,
        public array $lines,
        public array $taxSubtotals,
        public string $lineExtension,
        public string $taxExclusive,
        public string $taxAmount,
        public string $taxInclusive,
        public array $paymentMeansCodes = [],
        public string $reason = '',
        public ?string $billingReference = null,
        public string $currency = 'SAR',
        public string $uuid = '',
        public int $icv = 0,
        public string $previousHash = '',
    ) {
    }

    public function chained(string $uuid, int $icv, string $previousHash): self
    {
        return new self(
            $this->kind,
            $this->subtype,
            $this->number,
            $this->issueDate,
            $this->issueTime,
            $this->supplyDate,
            $this->seller,
            $this->buyer,
            $this->lines,
            $this->taxSubtotals,
            $this->lineExtension,
            $this->taxExclusive,
            $this->taxAmount,
            $this->taxInclusive,
            $this->paymentMeansCodes,
            $this->reason,
            $this->billingReference,
            $this->currency,
            $uuid,
            $icv,
            $previousHash,
        );
    }

    public function timestamp(): string
    {
        return $this->issueDate . 'T' . $this->issueTime . 'Z';
    }
}
