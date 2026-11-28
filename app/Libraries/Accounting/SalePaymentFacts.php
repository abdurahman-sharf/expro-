<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The figures of one payment of a sale, for the posting engine: what it received (less the change given
 * back, negative for a refund) or, for the rounding of cash, what the rounding comes to, each in the base
 * currency and, on a foreign document, in the currency of the document too, with the fields and flags the
 * rules choose the accounts by. A payment that is not completed posts nothing. The rounding of cash on a
 * foreign document and a way of paying posting does not know are refused as they always were.
 */
final class SalePaymentFacts
{
    /**
     * @throws PostingException
     */
    public static function of(SaleDocument $sale, SalePayment $payment, int $revision, ?int $employeeId, int $baseDecimals): PostingFacts
    {
        $type     = null;
        $received = '0';
        $adjusted = '0';
        $foreign  = '0';

        if ($payment->status->posts()) {
            if ($sale->currency !== null && $payment->cashAdjustment) {
                throw new PostingException(PostingError::FxNotSupported, 'The rounding of cash is not supported on a document in a foreign currency.', ['what' => 'cash_adjustment', 'sale_id' => $sale->saleId]);
            }

            if ($payment->cashAdjustment) {
                $adjusted = bcmul($payment->amount, '-1', SaleAmounts::SCALE);
            } else {
                $type = PaymentType::fromLabel($payment->type);

                if ($type === null) {
                    throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$payment->type} is not one posting knows.", ['payment_type' => $payment->type]);
                }

                $foreign  = bcsub($payment->amount, $payment->cashRefund, SaleAmounts::SCALE);
                $received = $sale->currency === null ? $foreign : self::toBase($sale, $payment, $foreign, $baseDecimals);
            }
        }

        $byTreasury = $type !== null && $payment->treasuryId !== null && $type->usesTreasury();

        return new PostingFacts(
            operation: SalePostingBuilder::SOURCE_PAYMENT,
            sourceId: $payment->paymentId,
            date: $payment->date,
            branchId: $sale->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: ['paid' => $received, 'adjustment' => $adjusted, 'amount' => $foreign],
            fields: [
                'customer_id'  => $sale->customerId,
                'treasury_id'  => $byTreasury ? $payment->treasuryId : null,
                'payment_type' => $payment->type,
            ],
            flags: [
                'on_credit'    => $type === PaymentType::Due,
                'by_treasury'  => $byTreasury,
                'by_payment'   => $type !== null && $type !== PaymentType::Due && ! $byTreasury,
                'has_customer' => $sale->customerId !== null,
                'counter'      => $sale->customerId === null,
            ],
            currencyCode: $sale->currency?->code,
            description: 'Payment #' . $payment->paymentId . ' of sale #' . $sale->saleId,
        );
    }

    /**
     * A foreign amount at the rate of the payment, its size converted and its sign kept. An amount that
     * comes to nothing in the base currency is refused, as the conversion of the journal refuses it.
     *
     * @throws PostingException
     */
    private static function toBase(SaleDocument $sale, SalePayment $payment, string $amount, int $baseDecimals): string
    {
        if ($payment->rate === null) {
            throw new PostingException(PostingError::ForeignRateMismatch, 'The payment of a document in a foreign currency has no rate.', ['payment_id' => $payment->paymentId]);
        }

        $base = Money::toBase(SaleAmounts::absolute($amount), $payment->rate, $baseDecimals);

        if (bccomp($base, '0', SaleAmounts::SCALE) === 0 && bccomp($amount, '0', SaleAmounts::SCALE) !== 0) {
            throw new PostingException(PostingError::ForeignRoundingTooBig, 'The line that balances the journal would come to nothing.', ['line' => 0]);
        }

        return bccomp($amount, '0', SaleAmounts::SCALE) < 0 ? bcmul($base, '-1', SaleAmounts::SCALE) : $base;
    }
}
