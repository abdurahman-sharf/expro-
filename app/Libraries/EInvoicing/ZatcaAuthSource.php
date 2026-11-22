<?php

namespace App\Libraries\EInvoicing;

/**
 * Where the credentials of a unit come from: the certificate of the kind asked for, active, in the environment.
 */
interface ZatcaAuthSource
{
    /**
     * @param int|null $unitId The default unit of the company when none is given.
     *
     * @throws EInvoiceException When the unit has no such certificate, or the one it has ran out.
     */
    public function auth(?int $unitId, string $environment, string $kind, string $now): ZatcaAuth;
}
