<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;

/**
 * A document line entered in a larger unit, turned into the base units everything else reads. The
 * quantity in base units is the quantity entered times the factor of the unit at the time, and it must
 * be exact to the thousandths the stock is kept in. The price per base unit is the price entered divided
 * by the factor, and it must be exact to the precision the price is stored in: a line whose total would
 * change by a rounding of the price is refused, never rounded, so that the stock, the cost and the
 * journals of the line add up to what was typed.
 */
final readonly class UnitLine
{
    public const QUANTITY_SCALE = 3;
    public const PRICE_SCALE    = 2;
    public const COST_SCALE     = 6;
    public const FACTOR_SCALE   = 4;
    private const WORKING_SCALE = 8;

    public function __construct(
        public ?int $unitId,
        public string $factor,
        public string $enteredQuantity,
        public string $enteredPrice,
        public string $baseQuantity,
        public string $basePrice,
    ) {
    }

    public static function base(string $quantity, string $price): self
    {
        return new self(null, '1.0000', $quantity, $price, $quantity, $price);
    }

    /**
     * @param numeric-string $factor
     * @param numeric-string $enteredQuantity
     * @param numeric-string $enteredPrice
     *
     * @throws AccountingException
     */
    public static function convert(int $unitId, string $factor, string $enteredQuantity, string $enteredPrice, int $line = 0, int $priceScale = self::PRICE_SCALE): self
    {
        $baseQuantity = bcmul($enteredQuantity, $factor, self::WORKING_SCALE);

        if (bccomp(bcadd($baseQuantity, '0', self::QUANTITY_SCALE), $baseQuantity, self::WORKING_SCALE) !== 0) {
            throw new AccountingException(AccountingError::UnitQuantityInexact, 'The quantity does not convert exactly to base units.', ['line' => $line]);
        }

        $basePrice = bcdiv($enteredPrice, $factor, $priceScale);

        if (bccomp(bcmul($basePrice, $factor, self::WORKING_SCALE), bcadd($enteredPrice, '0', self::WORKING_SCALE), self::WORKING_SCALE) !== 0) {
            throw new AccountingException(AccountingError::UnitPriceInexact, 'The price does not divide exactly into a price per base unit.', ['line' => $line]);
        }

        return new self($unitId, bcadd($factor, '0', self::FACTOR_SCALE), $enteredQuantity, bcadd($enteredPrice, '0', self::COST_SCALE), bcadd($baseQuantity, '0', self::QUANTITY_SCALE), $basePrice);
    }

    /**
     * @return array<string, int|string|null> The columns of a stored line that remember the unit.
     */
    public function columns(string $priceColumn): array
    {
        if ($this->unitId === null) {
            return ['unit_id' => null, 'unit_factor' => null, 'unit_quantity' => null, $priceColumn => null];
        }

        return [
            'unit_id'       => $this->unitId,
            'unit_factor'   => $this->factor,
            'unit_quantity' => bcadd($this->enteredQuantity, '0', self::QUANTITY_SCALE),
            $priceColumn    => $this->enteredPrice,
        ];
    }
}
