<?php

namespace App\Libraries\EInvoicing;

/**
 * A document that was issued: the row as it was stored, which nothing changes afterwards.
 */
final readonly class IssuedEInvoice
{
    public function __construct(
        public int $documentId,
        public int $branchId,
        public string $sourceType,
        public int $sourceId,
        public EInvoiceKind $kind,
        public EInvoiceSubtype $subtype,
        public string $number,
        public string $uuid,
        public int $icv,
        public string $previousHash,
        public string $hash,
        public string $issueDate,
        public string $issueTime,
        public string $taxExclusive,
        public string $taxAmount,
        public string $taxInclusive,
        public ?int $originalDocumentId,
        public string $reason,
        public bool $signed,
        public string $createdAt,
        public string $xml = '',
        public string $qr = '',
    ) {
    }

    public function fileName(string $sellerVatNumber): string
    {
        return $sellerVatNumber . '_' . str_replace('-', '', $this->issueDate) . 'T' . str_replace(':', '', $this->issueTime) . '_' . preg_replace('/[^A-Za-z0-9]/', '-', $this->number) . '.xml';
    }
}
