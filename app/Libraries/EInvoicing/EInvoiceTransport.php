<?php

namespace App\Libraries\EInvoicing;

/**
 * What would carry an issued document to the authority for reporting or clearance, and the one place such a
 * thing would be put. None is: no call is made to any outside service, no certificate is requested and no
 * document is submitted (decision E47, which forbids integrations that send data outside, paid services
 * and real submissions to the tax authority). An owner-approved implementation would implement this
 * interface and be given to the EInvoiceService in place of the null one, in the one line of
 * EInvoiceService::defaultTransport(), after the owner has approved the integration in writing, the
 * onboarding of the unit (the CSID) has been done outside this code, and the official sandbox has
 * accepted the documents. Until then every document stays "not submitted".
 */
interface EInvoiceTransport
{
    public function name(): string;

    public function submit(IssuedEInvoice $document): TransportResult;
}
