<?php

namespace App\Libraries\Accounting;

/**
 * A correction of one line of a saved receiving: the line is taken out, or some of what it holds
 * changes. A line is named by its item and its line number, as it is stored. What a correction
 * leaves out stays as it was stored, and what it holds is only accepted as a plain number or text
 * of the size the line keeps, so that nothing is rounded in silence into a different line.
 */
final readonly class ReceivingLineEdit
{
    public const DESCRIPTION_LENGTH = 30;

    private function __construct(
        public int $itemId,
        public int $line,
        public bool $remove,
        public ?string $quantity = null,
        public ?string $price = null,
        public ?string $discount = null,
        public ?int $discountType = null,
        public ?string $taxPercent = null,
        public ?string $description = null,
        public ?string $serialnumber = null,
    ) {
    }

    public static function removing(int $itemId, int $line): self
    {
        return new self($itemId, $line, true);
    }

    /**
     * What a person entered for a line, or null when it is not a line that can be saved: the
     * quantity is more than nothing, the price and the discount are nothing or more, a percentage
     * is at most a hundred, and the tax is between nothing and a hundred. Fields that are not given
     * are left as stored.
     *
     * @param array<string, mixed> $fields quantity, price, discount, discount_type, tax_percent, description, serialnumber
     */
    public static function changing(int $itemId, int $line, array $fields): ?self
    {
        $quantity     = self::number($fields, 'quantity', 3, true);
        $price        = self::number($fields, 'price', 2);
        $discount     = self::number($fields, 'discount', 2);
        $taxPercent   = self::number($fields, 'tax_percent', 3);
        $description  = self::text($fields, 'description');
        $serialnumber = self::text($fields, 'serialnumber');
        $type         = null;

        if (isset($fields['discount_type'])) {
            $type = filter_var($fields['discount_type'], FILTER_VALIDATE_INT);

            if ($type === false || ! in_array($type, [PERCENT, FIXED, FIXED_LINE], true)) {
                return null;
            }
        }

        if ($quantity === false || $price === false || $discount === false || $taxPercent === false || $description === false || $serialnumber === false) {
            return null;
        }

        if ($taxPercent !== null && bccomp($taxPercent, '100', 3) > 0) {
            return null;
        }

        return new self($itemId, $line, false, $quantity, $price, $discount, $type, $taxPercent, $description, $serialnumber);
    }

    /**
     * The line as it stands after the correction, in the form the lines are compared in, or null
     * when what it comes to is not a line: a percentage over a hundred.
     *
     * @param array<string, mixed> $stored A row of receivings_items.
     *
     * @return array{quantity_purchased: string, item_unit_price: string, discount: string, discount_type: int, tax_percent: string, description: ?string, serialnumber: ?string}|null
     */
    public function applyTo(array $stored): ?array
    {
        $line = self::fieldsOf($stored);

        $line['quantity_purchased'] = $this->quantity ?? $line['quantity_purchased'];
        $line['item_unit_price']    = $this->price ?? $line['item_unit_price'];
        $line['discount']           = $this->discount ?? $line['discount'];
        $line['discount_type']      = $this->discountType ?? $line['discount_type'];
        $line['tax_percent']        = $this->taxPercent ?? $line['tax_percent'];
        $line['description']        = $this->description ?? $line['description'];
        $line['serialnumber']       = $this->serialnumber ?? $line['serialnumber'];

        if ($line['discount_type'] === PERCENT && bccomp($line['discount'], '100', 2) > 0) {
            return null;
        }

        return $line;
    }

    /**
     * The fields a correction can change, of a stored row, as they are compared.
     *
     * @param array<string, mixed> $row
     *
     * @return array{quantity_purchased: string, item_unit_price: string, discount: string, discount_type: int, tax_percent: string, description: ?string, serialnumber: ?string}
     */
    public static function fieldsOf(array $row): array
    {
        return [
            'quantity_purchased' => bcadd((string) $row['quantity_purchased'], '0', 3),
            'item_unit_price'    => bcadd((string) $row['item_unit_price'], '0', 2),
            'discount'           => bcadd((string) $row['discount'], '0', 2),
            'discount_type'      => (int) $row['discount_type'],
            'tax_percent'        => bcadd((string) $row['tax_percent'], '0', 3),
            'description'        => $row['description'] === null ? null : (string) $row['description'],
            'serialnumber'       => $row['serialnumber'] === null ? null : (string) $row['serialnumber'],
        ];
    }

    /**
     * A number given as plain digits with a point, rounded to the decimals the line keeps, null
     * when it is not given and false when it is not a number of nothing or more.
     *
     * @param array<string, mixed> $fields
     *
     * @return numeric-string|false|null
     */
    private static function number(array $fields, string $name, int $decimals, bool $positive = false): string|false|null
    {
        if (! isset($fields[$name]) || $fields[$name] === '') {
            return null;
        }

        $raw = $fields[$name];

        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return false;
        }

        $text = is_float($raw) ? rtrim(rtrim(sprintf('%.9F', $raw), '0'), '.') : trim((string) $raw);

        if (preg_match('/^\d{1,11}(\.\d{1,9})?$/', $text) !== 1) {
            return false;
        }

        $number = SaleAmounts::round($text, $decimals);

        return $positive && bccomp($number, '0', $decimals) <= 0 ? false : $number;
    }

    /**
     * Text of the length a line keeps, null when it is not given and false when it is too long.
     *
     * @param array<string, mixed> $fields
     */
    private static function text(array $fields, string $name): string|false|null
    {
        if (! array_key_exists($name, $fields) || $fields[$name] === null) {
            return null;
        }

        return is_string($fields[$name]) && mb_strlen($fields[$name]) <= self::DESCRIPTION_LENGTH ? $fields[$name] : false;
    }
}
