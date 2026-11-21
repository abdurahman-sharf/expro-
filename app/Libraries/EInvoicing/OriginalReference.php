<?php

namespace App\Libraries\EInvoicing;

/**
 * What a credit note or a debit note refers to (BT-25), written in the note: an issued document, or, for a sale
 * completed before the Saudi rules and never issued, the number printed for that sale and the sale itself. Its
 * subtype is the note's, because a note is of the same kind of tax invoice as what it corrects. A note refers to
 * one or the other, never to nothing: the rule is held here, since a server that keeps no checks would not hold it.
 */
final readonly class OriginalReference
{
    /**
     * @throws EInvoiceException
     */
    public function __construct(
        public ?int $documentId,
        public string $number,
        public EInvoiceSubtype $subtype,
        public ?int $saleId = null,
    ) {
        if ($documentId === null && ($saleId === null || trim($number) === '')) {
            throw new EInvoiceException(EInvoiceError::OriginalNotIssued, 'A note refers to an issued document or to the printed number of a sale made before the Saudi rules.');
        }
    }

    /**
     * @throws EInvoiceException
     */
    public static function printed(int $saleId, string $number, EInvoiceSubtype $subtype): self
    {
        return new self(null, $number, $subtype, $saleId);
    }

    public function isPrinted(): bool
    {
        return $this->documentId === null;
    }
}
