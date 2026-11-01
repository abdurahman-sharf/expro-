<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;

final readonly class TieCheck
{
    /**
     * @param numeric-string $reported What the report adds up to.
     * @param numeric-string $book     What the books say for the same figure.
     */
    public function __construct(
        public string $label,
        public string $reported,
        public string $book,
    ) {
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
        return bccomp($this->difference(), '0', SaleAmounts::SCALE) === 0;
    }
}
