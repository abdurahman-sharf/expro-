<?php

namespace App\Libraries\EInvoicing;

/**
 * The national address of a party in the structured form the standard needs.
 */
final readonly class EInvoiceAddress
{
    public function __construct(
        public string $street,
        public string $buildingNumber,
        public string $district,
        public string $city,
        public string $postalCode,
        public string $countryCode,
        public string $additionalStreet = '',
        public string $additionalNumber = '',
        public string $province = '',
    ) {
    }

    public function isSaudi(): bool
    {
        return $this->countryCode === 'SA';
    }
}
