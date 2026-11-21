<?php

namespace App\Libraries\Accounting;

/**
 * A line of a saved purchase as posting reads it. The quantity counts packs and the units of a
 * pack multiply it, as the purchase screen does. Amounts are strings so that nothing is rounded
 * before posting does it. The branch is the one the stock location of the line belongs to. The
 * invoice discount is what the line takes of the discount on the whole purchase, always positive,
 * and nothing for a line saved without one. A line of a purchase saved under the Saudi rules says so, and
 * its purchase rounds its input VAT to the halala once for each rate.
 */
final readonly class ReceivingLine
{
    public function __construct(
        public int $itemId,
        public int $line,
        public string $quantity,
        public string $unitsPerPack,
        public string $unitPrice,
        public string $discount,
        public int $discountType,
        public string $taxPercent,
        public int $locationId,
        public bool $hasStock,
        public string $itemCost,
        public ?int $branchId = null,
        public string $invoiceDiscount = '0',
        public bool $vatWorkedOnce = false,
    ) {
    }

    /**
     * A line of the cart of the purchase screen, as it will be stored: prices and discounts to the
     * cents they are kept at, quantities and rates to the thousandths.
     *
     * @param array<string, mixed> $item
     */
    public static function ofCart(array $item, string $invoiceDiscount = '0', bool $vatWorkedOnce = false): self
    {
        return new self(
            itemId: (int) ($item['item_id'] ?? 0),
            line: (int) ($item['line'] ?? 0),
            quantity: sprintf('%.3F', (float) $item['quantity']),
            unitsPerPack: sprintf('%.3F', (float) ($item['receiving_quantity'] ?? 1)),
            unitPrice: sprintf('%.2F', (float) $item['price']),
            discount: sprintf('%.2F', (float) ($item['discount'] ?? 0)),
            discountType: (int) ($item['discount_type'] ?? PERCENT),
            taxPercent: sprintf('%.3F', (float) ($item['tax_percent'] ?? 0)),
            locationId: (int) ($item['item_location'] ?? 0),
            hasStock: true,
            itemCost: '0',
            invoiceDiscount: $invoiceDiscount,
            vatWorkedOnce: $vatWorkedOnce,
        );
    }

    /**
     * The units of the line, with the sign of its quantity.
     *
     * @return numeric-string
     */
    public function units(): string
    {
        return bccomp($this->unitsPerPack, '0', 3) === 0 ? bcadd($this->quantity, '0', 3) : bcmul($this->quantity, $this->unitsPerPack, 3);
    }
}
