<?php

namespace App\Libraries\EInvoicing;

final readonly class ZatcaResponse
{
    /**
     * @param list<string> $warnings
     * @param list<string> $errors
     * @param string|null  $clearedInvoice The file ZATCA cleared and stamped, for a tax invoice.
     * @param string|null  $kind           How the document was sent in the end, when not as it was asked (a tax invoice reported while clearance is switched off).
     */
    public function __construct(
        public ZatcaOutcome $outcome,
        public ?int $httpStatus = null,
        public array $warnings = [],
        public array $errors = [],
        public ?string $clearedInvoice = null,
        public ?string $kind = null,
    ) {
    }
}
