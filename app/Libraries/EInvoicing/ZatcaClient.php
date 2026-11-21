<?php

namespace App\Libraries\EInvoicing;

/**
 * What talks to ZATCA. No implementation in Expro holds an address of ZATCA: the environments and their addresses
 * are settings filled in when a unit is onboarded (T10), after a separate approval for each environment. Until then
 * the only client is the fake one the tests use.
 */
interface ZatcaClient
{
    public function name(): string;

    public function submit(ZatcaRequest $request): ZatcaResponse;
}
