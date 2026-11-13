<?php

namespace App\Libraries\EInvoicing;

/**
 * The transport that is in place: it sends nothing and says so, so that the record of a document always
 * says that it has not been submitted.
 */
final class NullEInvoiceTransport implements EInvoiceTransport
{
    public function name(): string
    {
        return 'null';
    }

    public function submit(IssuedEInvoice $document): TransportResult
    {
        return new TransportResult(TransportResult::NOT_SUBMITTED, 'No transport is configured: nothing was sent.');
    }
}
