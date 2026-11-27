<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The exchange difference a payment of a foreign sale leaves, for the posting engine: what the rate of
 * the document says the balance of the customer falls by, the payments before it and this one added
 * together, less what the money moved at the rate of the payment. A positive difference is a loss, a
 * negative one a gain. It reads every earlier payment of the sale and whether each books any money,
 * which the rules cannot see, so the difference is worked out here and the rules only place it.
 */
final class SalePaymentDifferenceFacts
{
    /**
     * @throws PostingException
     */
    public static function of(SaleDocument $sale, SalePayment $payment, int $revision, ?int $employeeId, int $baseDecimals, RoutingResolver $resolver): PostingFacts
    {
        $difference = '0';
        $net        = $sale->currency === null ? null : self::bookedNet($payment, $resolver);

        if ($net !== null) {
            if ($payment->rate === null) {
                throw new PostingException(PostingError::ForeignRateMismatch, 'The payment of a document in a foreign currency has no rate.', ['payment_id' => $payment->paymentId]);
            }

            $before = '0';

            foreach ($sale->payments as $earlier) {
                if ($earlier->paymentId >= $payment->paymentId) {
                    break;
                }

                $before = bcadd($before, self::bookedNet($earlier, $resolver) ?? '0', SaleAmounts::SCALE);
            }

            $after      = bcadd($before, $net, SaleAmounts::SCALE);
            $settled    = bcsub(Money::toBase($after, $sale->currency->rate, $baseDecimals), Money::toBase($before, $sale->currency->rate, $baseDecimals), SaleAmounts::SCALE);
            $difference = bcsub($settled, Money::toBase($net, $payment->rate, $baseDecimals), SaleAmounts::SCALE);
        }

        return new PostingFacts(
            operation: SalePostingBuilder::SOURCE_PAYMENT_FX,
            sourceId: $payment->paymentId,
            date: $payment->date,
            branchId: $sale->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: ['fx_diff' => $difference],
            fields: ['customer_id' => $sale->customerId],
            flags: [
                'has_customer' => $sale->customerId !== null,
                'counter'      => $sale->customerId === null,
                'is_loss'      => bccomp($difference, '0', SaleAmounts::SCALE) > 0,
                'is_gain'      => bccomp($difference, '0', SaleAmounts::SCALE) < 0,
            ],
            description: 'Exchange difference of payment #' . $payment->paymentId . ' of sale #' . $sale->saleId,
        );
    }

    /**
     * What a payment moves in the currency of its document, or null when it books nothing.
     *
     * @return numeric-string|null
     *
     * @throws PostingException
     */
    private static function bookedNet(SalePayment $payment, RoutingResolver $resolver): ?string
    {
        if (! $payment->status->posts() || $payment->cashAdjustment) {
            return null;
        }

        $type = PaymentType::fromLabel($payment->type);

        if ($type === null) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$payment->type} is not one posting knows.", ['payment_type' => $payment->type]);
        }

        $account = $payment->treasuryId !== null && $type->usesTreasury()
            ? $resolver->accountFor(RoutingKey::Treasuries, $payment->treasuryId)
            : $resolver->accountForPayment($type);

        $net = bcsub($payment->amount, $payment->cashRefund, SaleAmounts::SCALE);

        return $account === null || bccomp($net, '0', SaleAmounts::SCALE) === 0 ? null : $net;
    }
}
