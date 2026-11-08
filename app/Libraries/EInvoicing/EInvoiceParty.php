<?php

namespace App\Libraries\EInvoicing;

final readonly class EInvoiceParty
{
    public function __construct(
        public string $name,
        public string $vatNumber = '',
        public ?PartyIdentificationType $identificationType = null,
        public string $identificationNumber = '',
        public ?EInvoiceAddress $address = null,
    ) {
    }
}
