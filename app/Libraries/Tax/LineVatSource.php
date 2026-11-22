<?php

namespace App\Libraries\Tax;

/**
 * Where the calculation of a sale learns whether the Saudi rules apply, what a classified item is sold under on a
 * day, and what a line of a saved sale was sold under.
 */
interface LineVatSource
{
    public function appliesNow(): bool;

    /**
     * @throws VatClassificationException When the item is classified but its class has no rate on that day.
     */
    public function lineVat(int $itemId, string $date): ?LineVat;

    public function soldUnder(int $saleId, int $itemId, int $line): ?LineVat;
}
