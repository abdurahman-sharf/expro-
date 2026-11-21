<?php

namespace App\Libraries\EInvoicing;

final readonly class ZatcaResponse
{
    /**
     * @param list<string> $warnings
     * @param list<string> $errors
     * @param string|null  $clearedInvoice The file ZATCA cleared and stamped, for a tax invoice.
     */
    public function __construct(
        public ZatcaOutcome $outcome,
        public ?int $httpStatus = null,
        public array $warnings = [],
        public array $errors = [],
        public ?string $clearedInvoice = null,
    ) {
    }
}
