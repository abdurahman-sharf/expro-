<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The exchange difference a payment of a foreign purchase leaves, for the posting engine: what the rate
 * of the purchase says the balance of the supplier falls by, what was paid when it was saved and the
 * payments before this one added together, less what the money moved at the rate of the payment. A
 * positive difference is a gain, a negative one a loss. It reads the earlier payments of the purchase and
 * whether each moves any money, which the rules cannot see, so the difference is worked out here and the
 * rules only place it.
 */
final class SupplierPaymentDifferenceFacts
{
    /**
     * @throws PostingException
     */
    public static function of(ReceivingDocument $purchase, SupplierPayment $payment, int $revision, ?int $employeeId, int $baseDecimals, RoutingResolver $resolver): PostingFacts
    {
        $difference = '0';
        $net        = $purchase->currency === null || $purchase->supplierId === null ? null : self::bookedNet($payment, $resolver);

        if ($net !== null) {
            if ($payment->rate === null) {
                throw new PostingException(PostingError::ForeignRateMismatch, 'The payment of a purchase in a foreign currency has no rate.', ['payment_id' => $payment->paymentId]);
            }

            $before = $purchase->type === ReceivingType::Receive && SupplierPaymentType::movesMoney($purchase->paymentType, $purchase->treasuryId, $resolver)
                ? ReceivingAmounts::totals($purchase->lines, $purchase->currency->decimals)['total']
                : '0';

            foreach ($purchase->payments as $earlier) {
                if ($earlier->paymentId >= $payment->paymentId) {
                    break;
                }

                $before = bcadd($before, self::bookedNet($earlier, $resolver) ?? '0', SaleAmounts::SCALE);
            }

            $after      = bcadd($before, $net, SaleAmounts::SCALE);
            $settled    = bcsub(Money::toBase($after, $purchase->currency->rate, $baseDecimals), Money::toBase($before, $purchase->currency->rate, $baseDecimals), SaleAmounts::SCALE);
            $difference = bcsub($settled, Money::toBase($net, $payment->rate, $baseDecimals), SaleAmounts::SCALE);
        }

        return new PostingFacts(
            operation: ReceivingPostingBuilder::SOURCE_SUPPLIER_FX,
            sourceId: $payment->paymentId,
            date: $payment->date,
            branchId: $purchase->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: ['fx_diff' => $difference],
            fields: ['supplier_id' => $purchase->supplierId],
            flags: [
                'is_gain' => bccomp($difference, '0', SaleAmounts::SCALE) > 0,
                'is_loss' => bccomp($difference, '0', SaleAmounts::SCALE) < 0,
            ],
            description: 'Exchange difference of payment #' . $payment->paymentId . ' of purchase #' . $purchase->receivingId,
        );
    }

    /**
     * What a payment moves in the currency of its purchase, or null when it moves nothing.
     *
     * @return numeric-string|null
     *
     * @throws PostingException
     */
    private static function bookedNet(SupplierPayment $payment, RoutingResolver $resolver): ?string
    {
        if (! $payment->status->posts() || ! SupplierPaymentType::movesMoney($payment->type, $payment->treasuryId, $resolver)) {
            return null;
        }

        return bccomp($payment->amount, '0', SaleAmounts::SCALE) === 0 ? null : bcadd($payment->amount, '0', SaleAmounts::SCALE);
    }
}
