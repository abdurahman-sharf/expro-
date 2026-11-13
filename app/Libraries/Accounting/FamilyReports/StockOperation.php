<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Stock\StockPermitSources;

/**
 * What kind of operation a stock movement belongs to, by the document it came from and the way it
 * went. A reversal belongs to the operation it takes back, with its quantity taken off. The permits,
 * the stocktake, the adjustments and the opening stock are manual operations, each with a name of its
 * own in the report of movements; the transfer between two locations by permit counts as a transfer.
 */
final class StockOperation
{
    public const KEYS     = ['in_purchase', 'in_sale_return', 'in_transfer', 'in_manual', 'out_sale', 'out_purchase_return', 'out_transfer', 'out_manual'];
    private const SOURCES = [
        'purchase'                           => ['in_purchase', 'out_purchase_return'],
        'sale'                               => ['in_sale_return', 'out_sale'],
        'stock_transfer'                     => ['in_transfer', 'out_transfer'],
        'stock_transfer_receipt'             => ['in_transfer', 'out_transfer'],
        StockPermitSources::TRANSFER         => ['in_transfer', 'out_transfer'],
        StockPermitSources::TRANSFER_RECEIPT => ['in_transfer', 'out_transfer'],
    ];
    private const NAMED = [
        'purchase',
        'sale',
        'stock_adjustment',
        'opening_stock',
        'stock_transfer',
        'stock_transfer_receipt',
        StockPermitSources::PERMIT,
        StockPermitSources::STOCKTAKE,
        StockPermitSources::TRANSFER,
        StockPermitSources::TRANSFER_RECEIPT,
    ];

    /**
     * @param numeric-string $quantity What the movement moved: positive in, negative out.
     *
     * @return array{0: string, 1: numeric-string} The operation, and what it counts for under it: the quantity in, or the quantity out as a negative number.
     */
    public static function of(string $sourceType, string $kind, string $quantity): array
    {
        $reversal = $kind === 'reversal';
        $in       = bccomp($quantity, '0', 3) > 0 !== $reversal;
        $pair     = self::SOURCES[$sourceType] ?? ['in_manual', 'out_manual'];
        $key      = $in ? $pair[0] : $pair[1];
        $size     = ltrim($quantity, '-');
        $size     = $size === '' ? '0' : $size;
        $counts   = $reversal ? bcsub('0', $size, 3) : $size;

        return [$key, $in ? $counts : bcsub('0', $counts, 3)];
    }

    public static function label(string $sourceType, string $quantity, string $kind = 'posting'): string
    {
        $in    = bccomp($quantity, '0', 3) > 0;
        $known = in_array($sourceType, self::NAMED, true) ? $sourceType : 'other';
        $label = lang('Accounting_family_reports.op_' . $known . '_' . ($in ? 'in' : 'out'));

        return $kind === 'reversal' ? lang('Accounting_family_reports.op_reversal_of', [$label]) : $label;
    }
}
