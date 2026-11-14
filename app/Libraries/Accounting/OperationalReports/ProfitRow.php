<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * What one item of one sale document earned in a day it left or came back: the units, the price it sold
 * for before its discount and without tax, the discount, and what the movements of the books valued it
 * at. A return carries all of them as negatives, and its cost is the cost of the sale it takes back.
 */
final readonly class ProfitRow
{
    public function __construct(
        public int $saleId,
        public DocumentKind $kind,
        public string $number,
        public string $date,
        public int $branchId,
        public ?int $customerId,
        public string $customerName,
        public ?int $employeeId,
        public string $employeeName,
        public int $itemId,
        public string $itemName,
        public string $itemNumber,
        public string $category,
        public string $quantity,
        public string $cost,
        public string $salePrice,
        public string $discount,
        public ?string $currencyCode = null,
    ) {
    }

    /**
     * What the sale comes to after its discount and without tax.
     *
     * @return numeric-string
     */
    public function value(): string
    {
        return Amounts::sub($this->salePrice, $this->discount);
    }

    /**
     * @return numeric-string
     */
    public function profit(): string
    {
        return Amounts::sub($this->value(), $this->cost);
    }

    /**
     * @return numeric-string
     */
    public function unitCost(): string
    {
        return $this->perUnit($this->cost);
    }

    /**
     * @return numeric-string
     */
    public function unitPrice(): string
    {
        return $this->perUnit($this->salePrice);
    }

    /**
     * @return numeric-string
     */
    private function perUnit(string $amount): string
    {
        return bccomp($this->quantity, '0', 3) === 0 ? Amounts::ZERO : bcadd(bcdiv($amount, $this->quantity, 8), '0', 4);
    }
}
