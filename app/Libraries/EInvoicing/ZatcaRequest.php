<?php

namespace App\Libraries\EInvoicing;

/**
 * One document as it is sent to ZATCA: reported (a simplified invoice and its notes), cleared (a tax invoice and its
 * notes) or checked for compliance. The same document is always sent as the same request, to the byte, so a request
 * sent again after no answer is told by ZATCA from a new one by its hash.
 */
final readonly class ZatcaRequest
{
    public const REPORTING  = 'reporting';
    public const CLEARANCE  = 'clearance';
    public const COMPLIANCE = 'compliance';

    /**
     * @param int|null $unitId The unit that issued the document, whose certificate it is sent with.
     */
    public function __construct(
        public string $kind,
        public string $environment,
        public string $uuid,
        public string $invoiceHash,
        public string $invoice,
        public ?int $unitId = null,
    ) {
    }

    /**
     * The same document sent another way: a tax invoice is reported when clearance is switched off.
     */
    public function as(string $kind): self
    {
        return new self($kind, $this->environment, $this->uuid, $this->invoiceHash, $this->invoice, $this->unitId);
    }

    /**
     * @return array{invoiceHash: string, uuid: string, invoice: string}
     */
    public function body(): array
    {
        return ['invoiceHash' => $this->invoiceHash, 'uuid' => $this->uuid, 'invoice' => base64_encode($this->invoice)];
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->kind . "\n" . json_encode($this->body(), JSON_THROW_ON_ERROR));
    }
}
