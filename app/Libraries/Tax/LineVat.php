<?php

namespace App\Libraries\Tax;

/**
 * What a line of a sale is sold under: its VAT class, the category of the standard, the rate in force on the day
 * of the supply, the reason a line without tax carries none, and the name of the tax it is charged as.
 */
final readonly class LineVat
{
    public function __construct(
        public ?int $classId,
        public string $category,
        public string $percent,
        public ?string $exemptionCode,
        public ?string $exemptionText,
        public string $taxName,
    ) {
    }
}
