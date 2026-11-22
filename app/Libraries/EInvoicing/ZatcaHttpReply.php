<?php

namespace App\Libraries\EInvoicing;

final readonly class ZatcaHttpReply
{
    public function __construct(
        public int $status,
        public string $body,
    ) {
    }

    /**
     * @return array<string, mixed> The body read as JSON, nothing when it is not.
     */
    public function json(): array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
