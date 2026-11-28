<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The figures of a purchase and of a purchase return, for the posting engine. They decide no account and
 * no side. A figure the code would leave out of the journal (nothing or less) is given as nothing, and a
 * journal the code would not write because it comes to fewer than two lines is given as nothing at all.
 *
 * A purchase gives what each stock location receives, the cost of what is not stocked, the tax and the
 * total, with the rounding of the total on the part worth most, all in the currency of the purchase: the
 * rules bring a foreign journal to the base. A return gives its figures in the base already, as the code
 * values the stock it gives back at the cost it stands at, and the total and the tax in the currency of the
 * return beside them.
 */
final class PurchaseFacts
{
    private const SCALE = ReceivingAmounts::SCALE;

    /**
     * @throws PostingException
     */
    public static function receive(ReceivingDocument $purchase, int $revision, ?int $employeeId, int $baseDecimals): PostingFacts
    {
        $totals   = ReceivingAmounts::totals($purchase->lines, $purchase->currency?->decimals ?? 2);
        $rounding = bcsub($totals['total'], bcadd($totals['net'], $totals['tax'], self::SCALE), self::SCALE);
        $parts    = [];

        foreach ($purchase->lines as $index => $line) {
            $key         = $line->hasStock ? 's' . $index : 'e';
            $parts[$key] = bcadd($parts[$key] ?? '0', ReceivingAmounts::net($line), self::SCALE);
        }

        $parts      = self::withRounding($parts, $rounding);
        $byLocation = [];

        foreach ($purchase->lines as $index => $line) {
            if ($line->hasStock) {
                $byLocation[$line->locationId] = bcadd($byLocation[$line->locationId] ?? '0', $parts['s' . $index], self::SCALE);
            }
        }

        $locations = [];

        foreach ($byLocation as $locationId => $value) {
            $locations[] = ['fields' => ['location_id' => $locationId], 'figures' => ['value' => self::positive($value)]];
        }

        $figures = ['net' => self::positive($parts['e'] ?? '0'), 'tax' => self::positive($totals['tax']), 'total' => self::positive($totals['total'])];
        $count   = count(array_filter([...array_column(array_column($locations, 'figures'), 'value'), ...array_values($figures)], static fn (string $amount): bool => bccomp($amount, '0', self::SCALE) > 0));

        if ($count < 2) {
            [$locations, $figures] = [[], ['net' => '0', 'tax' => '0', 'total' => '0']];
        }

        return new PostingFacts(
            operation: 'purchase',
            sourceId: $purchase->receivingId,
            date: $purchase->date,
            branchId: $purchase->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: $figures,
            fields: self::creditorFields($purchase),
            flags: self::creditorFlags($purchase),
            lists: ['locations' => $locations],
            currencyCode: $purchase->currency?->code,
            description: 'Purchase #' . $purchase->receivingId,
            currencyRate: $purchase->currency?->rate,
            baseDecimals: $baseDecimals,
        );
    }

    /**
     * @param list<StockMovement> $movements The movements the code gives back the stock with, at the cost it stands at.
     *
     * @throws PostingException
     */
    public static function giveBack(ReceivingDocument $purchase, array $movements, int $revision, ?int $employeeId, int $baseDecimals): PostingFacts
    {
        $totals     = ReceivingAmounts::totals($purchase->lines, $purchase->currency?->decimals ?? 2);
        $notStocked = '0';

        foreach ($purchase->lines as $line) {
            if (! $line->hasStock) {
                $notStocked = bcadd($notStocked, SaleAmounts::absolute(ReceivingAmounts::net($line)), self::SCALE);
            }
        }

        $byLocation = [];
        $inventory  = '0';

        foreach ($movements as $movement) {
            $value                             = SaleAmounts::absolute($movement->value);
            $byLocation[$movement->locationId] = bcadd($byLocation[$movement->locationId] ?? '0', $value, self::SCALE);
            $inventory                         = bcadd($inventory, $value, self::SCALE);
        }

        $foreign    = $purchase->currency;
        $total      = $foreign === null ? $totals['total'] : Money::toBase($totals['total'], $foreign->rate, $baseDecimals);
        $tax        = $foreign === null ? $totals['tax'] : Money::toBase($totals['tax'], $foreign->rate, $baseDecimals);
        $notStocked = $foreign === null ? $notStocked : Money::toBase($notStocked, $foreign->rate, $baseDecimals);
        $variance   = bcsub(bcsub(bcsub($total, $inventory, self::SCALE), $tax, self::SCALE), $notStocked, self::SCALE);
        $hasTotal   = bccomp($totals['total'], '0', self::SCALE) > 0;
        $hasTax     = bccomp($totals['tax'], '0', self::SCALE) > 0;

        $locations = [];

        foreach ($byLocation as $locationId => $value) {
            $locations[] = ['fields' => ['location_id' => $locationId], 'figures' => ['value' => self::positive($value)]];
        }

        $figures = [
            'total'         => $hasTotal ? $total : '0',
            'foreign_total' => $hasTotal ? bcadd($totals['total'], '0', self::SCALE) : '0',
            'tax'           => $hasTax ? $tax : '0',
            'foreign_tax'   => $hasTax ? bcadd($totals['tax'], '0', self::SCALE) : '0',
            'net'           => self::positive($notStocked),
            'variance'      => $variance,
        ];

        $count = count(array_filter([$figures['total'], $figures['tax'], $figures['net'], ...array_column(array_column($locations, 'figures'), 'value')], static fn (string $amount): bool => bccomp($amount, '0', self::SCALE) > 0))
            + (bccomp($variance, '0', self::SCALE) === 0 ? 0 : 1);

        if ($count < 2) {
            [$locations, $figures] = [[], array_fill_keys(array_keys($figures), '0')];
        }

        return new PostingFacts(
            operation: 'purchase_return',
            sourceId: $purchase->receivingId,
            date: $purchase->date,
            branchId: $purchase->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: $figures,
            fields: self::creditorFields($purchase),
            flags: self::creditorFlags($purchase),
            lists: ['locations' => $locations],
            currencyCode: $foreign?->code,
            description: 'Purchase return #' . $purchase->receivingId,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private static function creditorFields(ReceivingDocument $purchase): array
    {
        return ['supplier_id' => $purchase->supplierId, 'treasury_id' => $purchase->treasuryId, 'payment_type' => $purchase->paymentType];
    }

    /**
     * Whom the goods are owed to: the supplier, or with no supplier the treasury or the way the purchase was
     * paid. A way a purchase is never paid with is refused as it always was.
     *
     * @return array<string, bool>
     *
     * @throws PostingException
     */
    private static function creditorFlags(ReceivingDocument $purchase): array
    {
        $type       = $purchase->supplierId === null && $purchase->paymentType !== null ? SupplierPaymentType::of($purchase->paymentType) : null;
        $byTreasury = $type !== null && $purchase->treasuryId !== null && $type->usesTreasury();

        return ['has_supplier' => $purchase->supplierId !== null, 'by_treasury' => $byTreasury, 'by_payment' => $type !== null && ! $byTreasury];
    }

    /**
     * The rounding of the total on the part worth most, the first of the largest, as the code puts it.
     *
     * @param array<string, numeric-string> $parts
     *
     * @return array<string, numeric-string>
     *
     * @throws PostingException
     */
    private static function withRounding(array $parts, string $rounding): array
    {
        if ($parts === [] || bccomp($rounding, '0', self::SCALE) === 0) {
            return $parts;
        }

        $largest = array_key_first($parts);

        foreach ($parts as $key => $amount) {
            if (bccomp($amount, $parts[$largest], self::SCALE) > 0) {
                $largest = $key;
            }
        }

        $parts[$largest] = bcadd($parts[$largest], $rounding, self::SCALE);

        if (bccomp($parts[$largest], '0', self::SCALE) < 0) {
            throw new PostingException(PostingError::PurchaseNotPostable, 'The purchase is too small for its total to be kept to two decimals.');
        }

        return $parts;
    }

    private static function positive(string $amount): string
    {
        return bccomp($amount, '0', self::SCALE) > 0 ? bcadd($amount, '0', self::SCALE) : '0';
    }
}
