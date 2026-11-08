<?php

namespace App\Libraries\Documents;

/**
 * What the document layer says about the code of a document: the text to print, or why there is none. A
 * document that never carries a code has neither.
 */
final readonly class QrResult
{
    public function __construct(
        public ?string $payload,
        public ?DocumentError $refusal = null,
    ) {
    }

    public static function none(): self
    {
        return new self(null);
    }
}
