<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The figures of what a purchase was paid when it was saved, or what the supplier paid back on a return,
 * for the posting engine: the total of the purchase in the base currency, negative on a return, and in
 * the currency of the purchase too, with the supplier and the treasury or the way of paying. A requisition,
 * a purchase with no supplier and one left with no way of paying post nothing. A way of paying a purchase
 * is never paid with is refused as it always was.
 */
final class PurchasePaymentFacts
{
    /**
     * @throws PostingException
     */
    public static function of(ReceivingDocument $purchase, int $revision, ?int $employeeId, int $baseDecimals): PostingFacts
    {
        $posts = $purchase->type !== ReceivingType::Requisition && $purchase->supplierId !== null && $purchase->paymentType !== null;
        $type  = $posts ? SupplierPaymentType::of($purchase->paymentType) : null;
        $total = $posts ? ReceivingAmounts::totals($purchase->lines, $purchase->currency?->decimals ?? 2)['total'] : '0';
        $base  = $purchase->currency === null ? $total : SupplierPaymentType::toBase($total, $purchase->currency->rate, $baseDecimals);
        $sign  = $purchase->type === ReceivingType::Receive ? '1' : '-1';

        $byTreasury = $type !== null && $purchase->treasuryId !== null && $type->usesTreasury();

        return new PostingFacts(
            operation: ReceivingPostingBuilder::SOURCE_PAYMENT,
            sourceId: $purchase->receivingId,
            date: $purchase->savedOn ?? $purchase->date,
            branchId: $purchase->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: ['paid' => bcmul($base, $sign, SaleAmounts::SCALE), 'amount' => bcmul($total, $sign, SaleAmounts::SCALE)],
            fields: ['supplier_id' => $purchase->supplierId, 'treasury_id' => $byTreasury ? $purchase->treasuryId : null, 'payment_type' => $purchase->paymentType],
            flags: ['by_treasury' => $byTreasury, 'by_payment' => $type !== null && ! $byTreasury],
            currencyCode: $purchase->currency?->code,
            description: ($purchase->type === ReceivingType::Receive ? 'Payment of purchase #' : 'Refund of purchase return #') . $purchase->receivingId,
        );
    }
}
