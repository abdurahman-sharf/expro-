<?php

namespace App\Libraries\EInvoicing;

/**
 * What carries a request to ZATCA and brings back its answer.
 */
interface ZatcaHttp
{
    /**
     * @throws EInvoiceException When the request may not leave the server (ZatcaNetworkGuard).
     * @throws ZatcaHttpFailure  When no answer came.
     */
    public function send(ZatcaHttpRequest $request): ZatcaHttpReply;
}
