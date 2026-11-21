<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * What asks ZATCA for the certificates of a unit: the compliance certificate for a request and the one-time password
 * of the Fatoora portal, then the production certificate for the compliance request. As with ZatcaClient, the only
 * implementation in Expro is the fake one of the tests.
 */
interface ZatcaOnboardingClient
{
    public function complianceCsid(string $csr, #[SensitiveParameter] string $otp): ZatcaCsid;

    public function productionCsid(string $complianceRequestId): ZatcaCsid;
}
