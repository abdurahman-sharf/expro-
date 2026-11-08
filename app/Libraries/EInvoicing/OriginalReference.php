<?php

namespace App\Libraries\EInvoicing;

/**
 * The issued document a credit note or a debit note refers to (BT-25): its number is written in the note, and
 * its subtype is the note's, because a note is of the same kind of tax invoice as the document it corrects.
 */
final readonly class OriginalReference
{
    public function __construct(
        public int $documentId,
        public string $number,
        public EInvoiceSubtype $subtype,
    ) {
    }
}
