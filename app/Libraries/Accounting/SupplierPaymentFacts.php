<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The figures of a payment made against a purchase after it was saved, for the posting engine: what was
 * paid to the supplier, negative for money the supplier gave back, in the base currency at the rate of
 * the payment and in the currency of the purchase too, with the supplier and the treasury or the way of
 * paying. A payment that is not completed posts nothing. A payment against a return, against a purchase
 * with no supplier, or in a way a supplier is never paid with, is refused as it always was.
 */
final class SupplierPaymentFacts
{
    /**
     * @throws PostingException
     */
    public static function of(ReceivingDocument $purchase, SupplierPayment $payment, int $revision, ?int $employeeId, int $baseDecimals): PostingFacts
    {
        $type   = null;
        $amount = '0';
        $paid   = '0';

        if ($payment->status->posts()) {
            if ($purchase->type !== ReceivingType::Receive) {
                throw new PostingException(PostingError::PurchaseNotPostable, 'A payment is made against a purchase, not against a return or a transfer.', ['receiving_id' => $purchase->receivingId]);
            }

            if ($purchase->supplierId === null) {
                throw new PostingException(PostingError::SupplierRequired, 'A payment to a supplier needs the purchase to have a supplier.', ['receiving_id' => $purchase->receivingId]);
            }

            $type   = SupplierPaymentType::of($payment->type);
            $amount = bcadd($payment->amount, '0', SaleAmounts::SCALE);

            // The code refuses a way of paying that moves no money before it looks at the amount; a
            // payment of nothing would otherwise reach no line of the rules that could refuse it.
            if ($type === PaymentType::Due && bccomp($amount, '0', SaleAmounts::SCALE) === 0) {
                throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$payment->type} is not one a supplier can be paid with.", ['payment_type' => $payment->type]);
            }
            $paid = $amount;

            if ($purchase->currency !== null && bccomp($amount, '0', SaleAmounts::SCALE) !== 0) {
                if ($payment->rate === null) {
                    throw new PostingException(PostingError::ForeignRateMismatch, 'The payment of a purchase in a foreign currency has no rate.', ['payment_id' => $payment->paymentId]);
                }

                $paid = SupplierPaymentType::toBase($amount, $payment->rate, $baseDecimals);
            }
        }

        $byTreasury = $type !== null && $payment->treasuryId !== null && $type->usesTreasury();

        return new PostingFacts(
            operation: ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT,
            sourceId: $payment->paymentId,
            date: $payment->date,
            branchId: $purchase->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: ['paid' => $paid, 'amount' => $amount],
            fields: ['supplier_id' => $purchase->supplierId, 'treasury_id' => $byTreasury ? $payment->treasuryId : null, 'payment_type' => $payment->type],
            flags: ['by_treasury' => $byTreasury, 'by_payment' => $type !== null && ! $byTreasury],
            currencyCode: $purchase->currency?->code,
            description: 'Payment #' . $payment->paymentId . ' of purchase #' . $purchase->receivingId,
        );
    }
}
