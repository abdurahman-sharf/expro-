<?php

namespace App\Libraries\Purchases;

use App\Libraries\Accounting\SaleAmounts;

/**
 * What the purchases of a filter add up to, one set of figures for each currency, because an amount in one
 * currency is never added to an amount in another. The base currency is the one named with an empty string.
 */
final readonly class PurchaseSums
{
    /**
     * @param array<string, array{count: int, decimals: int|null, net: numeric-string, returns: numeric-string, paid: numeric-string, due: numeric-string}> $byCurrency Decimals are those of a foreign currency and null for the base.
     * @param string|null                                                                                                                                   $refusal    The language key of why nothing was added up, when it was not.
     */
    public function __construct(
        public array $byCurrency,
        public ?string $refusal = null,
    ) {
    }

    /**
     * @param list<PurchaseInvoiceRow> $rows
     */
    public static function of(array $rows): self
    {
        $sums = [];

        foreach ($rows as $row) {
            $key = $row->currencyCode ?? '';
            $sums[$key] ??= ['count' => 0, 'decimals' => $row->isForeign() ? $row->decimals : null, 'net' => '0', 'returns' => '0', 'paid' => '0', 'due' => '0'];

            $sums[$key]['count']++;
            $sums[$key]['net'] = bcadd($sums[$key]['net'], $row->signedTotal(), SaleAmounts::SCALE);

            if ($row->isReturn()) {
                $sums[$key]['returns'] = bcadd($sums[$key]['returns'], $row->total, SaleAmounts::SCALE);

                continue;
            }

            $sums[$key]['paid'] = bcadd($sums[$key]['paid'], $row->paid, SaleAmounts::SCALE);
            $sums[$key]['due']  = bcadd($sums[$key]['due'], $row->due, SaleAmounts::SCALE);
        }

        ksort($sums);

        return new self($sums);
    }
}
