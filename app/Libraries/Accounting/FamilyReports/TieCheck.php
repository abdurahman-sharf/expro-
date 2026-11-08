<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;

final readonly class TieCheck
{
    /**
     * @param numeric-string $reported What the report adds up to.
     * @param numeric-string $book     What the books say for the same figure.
     * @param string|null    $refusal  Why the check was not made, when the two are not in one currency.
     */
    public function __construct(
        public string $label,
        public string $reported,
        public string $book,
        public ?string $refusal = null,
    ) {
    }

    public static function refused(string $label, string $refusal): self
    {
        return new self($label, '0.0000', '0.0000', $refusal);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    /**
     * @return numeric-string
     */
    public function difference(): string
    {
        return bcsub($this->reported, $this->book, SaleAmounts::SCALE);
    }

    public function ties(): bool
    {
        return ! $this->isRefused() && bccomp($this->difference(), '0', SaleAmounts::SCALE) === 0;
    }
}
