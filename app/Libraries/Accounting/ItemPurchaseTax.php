<?php

namespace App\Libraries\Accounting;

/**
 * The tax a receiving line takes by default from its item. An item has two purchase taxes, as it has
 * two sales taxes. A purchase tax that is not set follows the sales tax of the same number, and one
 * set to nothing means no tax on purchases. An item that sets neither purchase tax is read as it
 * always was: the sum of its sales taxes.
 */
final class ItemPurchaseTax
{
    public const SLOTS = 2;

    /**
     * @param array<string, mixed>|object                       $item       A row of items.
     * @param list<array{name?: string, percent: float|string}> $salesTaxes The sales taxes of the item, in the order the form shows them.
     *
     * @return numeric-string
     */
    public static function defaultPercent(array|object $item, array $salesTaxes): string
    {
        $set = array_filter(self::percents($item), static fn (?string $percent): bool => $percent !== null);

        if ($set === []) {
            return array_reduce($salesTaxes, static fn (string $sum, array $tax): string => bcadd($sum, (string) $tax['percent'], 3), '0');
        }

        $sum = '0';

        foreach (self::percents($item) as $index => $percent) {
            $sum = bcadd($sum, $percent ?? (string) ($salesTaxes[$index]['percent'] ?? '0'), 3);
        }

        return $sum;
    }

    /**
     * What the item sets for each purchase tax, nothing for one that is not set.
     *
     * @param array<string, mixed>|object $item
     *
     * @return list<numeric-string|null>
     */
    public static function percents(array|object $item): array
    {
        $row      = (array) $item;
        $percents = [];

        for ($slot = 1; $slot <= self::SLOTS; $slot++) {
            $value      = $row["purchase_tax_{$slot}_percent"] ?? null;
            $percents[] = $value === null || $value === '' ? null : bcadd((string) $value, '0', 3);
        }

        return $percents;
    }
}
