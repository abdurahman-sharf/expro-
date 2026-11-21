<?php

namespace App\Libraries\Tax;

/**
 * The VAT of a document worked out once: each line by its key, and each category.
 */
final readonly class VatResult
{
    /**
     * @param array<string, VatLineResult>    $lines
     * @param array<string, VatCategoryTotal> $categories By category key.
     */
    public function __construct(
        public array $lines,
        public array $categories,
    ) {
    }

    public function taxable(): string
    {
        return array_reduce($this->categories, static fn (string $sum, VatCategoryTotal $category): string => bcadd($sum, $category->taxable, 2), '0.00');
    }

    public function vat(): string
    {
        return array_reduce($this->categories, static fn (string $sum, VatCategoryTotal $category): string => bcadd($sum, $category->vat, 2), '0.00');
    }

    /**
     * BT-112: the document without VAT and its VAT.
     */
    public function totalWithVat(): string
    {
        return bcadd($this->taxable(), $this->vat(), 2);
    }
}
