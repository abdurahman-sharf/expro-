<?php

namespace App\Libraries\Numbering;

final readonly class NumberingSequence
{
    public function __construct(
        public DocumentType $type,
        public bool $enabled,
        public string $prefix,
        public int $padding,
        public int $nextNumber,
        public bool $preventDuplicates,
    ) {
    }

    public static function defaultFor(DocumentType $type, int $nextNumber = 1): self
    {
        return new self($type, false, '', 0, $nextNumber, true);
    }

    public function format(int $number): string
    {
        return $this->prefix . ($this->padding > 0 ? str_pad((string) $number, $this->padding, '0', STR_PAD_LEFT) : (string) $number);
    }

    public function preview(): string
    {
        return $this->format($this->nextNumber);
    }
}
