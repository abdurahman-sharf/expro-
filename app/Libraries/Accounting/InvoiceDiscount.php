<?php

namespace App\Libraries\Accounting;

/**
 * The discount on the whole of a purchase, as it was entered: a percentage of what the lines come to
 * after their own discounts, or an amount. What it comes to is kept to the cent, like the total of the
 * purchase. What each line takes of it is worked out once, when the purchase is saved
 * (InvoiceDiscountShares), and kept on the line, so the discount is only ever read back from there.
 */
final readonly class InvoiceDiscount
{
    public const SCALE        = ReceivingAmounts::SCALE;
    public const AMOUNT_SCALE = 2;

    /**
     * @param numeric-string $value
     */
    private function __construct(
        public int $type,
        public string $value,
    ) {
    }

    public static function none(): self
    {
        return new self(PERCENT, '0.0000');
    }

    /**
     * What a person entered, or null when it is not a discount: a type that is neither a percentage
     * nor an amount, or a value that is not a plain number of nothing or more.
     */
    public static function of(int $type, string $value): ?self
    {
        $value = trim($value);

        if (($type !== PERCENT && $type !== FIXED) || preg_match('/^\d{1,11}(\.\d{1,9})?$/', $value) !== 1) {
            return null;
        }

        return new self($type, SaleAmounts::round($value, $type === FIXED ? self::AMOUNT_SCALE : self::SCALE));
    }

    /**
     * What a purchase kept: nothing for one saved without a discount.
     */
    public static function stored(?int $type, ?string $value): self
    {
        if ($type === null || $value === null) {
            return self::none();
        }

        return self::of($type, $value) ?? self::none();
    }

    public function isNone(): bool
    {
        return bccomp($this->value, '0', self::SCALE) === 0;
    }

    /**
     * Whether the discount can be taken from lines that come to the base: a percentage is at most the
     * whole of it and an amount is no more than it.
     *
     * @param numeric-string $base
     */
    public function fitsOn(string $base): bool
    {
        return $this->type === PERCENT
            ? bccomp($this->value, '100', self::SCALE) <= 0
            : bccomp($this->value, $base, self::SCALE) <= 0;
    }

    /**
     * What the discount comes to on lines that come to the base, to the cent and never more than the
     * base.
     *
     * @param numeric-string $base
     *
     * @return numeric-string
     */
    public function amountOn(string $base): string
    {
        if ($this->isNone() || bccomp($base, '0', self::SCALE) <= 0) {
            return '0.0000';
        }

        $amount = $this->type === PERCENT
            ? SaleAmounts::round(bcmul($base, bcdiv($this->value, '100', 8), 8), self::AMOUNT_SCALE)
            : $this->value;

        return bccomp($amount, $base, self::SCALE) > 0 ? bcadd($base, '0', self::SCALE) : bcadd($amount, '0', self::SCALE);
    }
}
