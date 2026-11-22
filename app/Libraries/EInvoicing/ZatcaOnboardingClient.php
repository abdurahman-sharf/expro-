<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * What asks ZATCA for the certificates of a unit: the compliance certificate for a request and the one-time password
 * of the Fatoora portal, the production certificate for the compliance request (with the compliance certificate), and
 * a new production certificate for a new request and a new password (with the production certificate it replaces).
 */
interface ZatcaOnboardingClient
{
    /**
     * @throws EInvoiceException When ZATCA refuses, or nothing may be sent.
     */
    public function complianceCsid(string $csr, #[SensitiveParameter] string $otp): ZatcaCsid;

    /**
     * @throws EInvoiceException
     */
    public function productionCsid(string $complianceRequestId, #[SensitiveParameter] ZatcaAuth $compliance): ZatcaCsid;

    /**
     * @throws EInvoiceException
     */
    public function renewProductionCsid(string $csr, #[SensitiveParameter] string $otp, #[SensitiveParameter] ZatcaAuth $production): ZatcaCsid;
}
