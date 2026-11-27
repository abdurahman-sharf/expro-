<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The figures of a saved receipt voucher, for the posting engine: what was received, the tax in it and
 * what is left of it, and the fields and flags the rules choose accounts by. It decides no account and
 * no side. A voucher of nothing or less posts nothing and is not checked; a tax that does not fit the
 * amount, and a way of payment that receives no money, are refused as they always were.
 */
final class RevenueFacts
{
    /**
     * @throws PostingException
     */
    public static function of(RevenueDocument $revenue, int $revision, ?int $employeeId): PostingFacts
    {
        $posts = bccomp($revenue->amount, '0', SaleAmounts::SCALE) > 0;
        $type  = PaymentType::fromLabel($revenue->paymentType);

        if ($posts && (bccomp($revenue->taxAmount, '0', SaleAmounts::SCALE) < 0 || bccomp($revenue->taxAmount, $revenue->amount, SaleAmounts::SCALE) > 0)) {
            throw new PostingException(PostingError::RevenueTaxInvalid, 'The tax of a receipt voucher is not less than zero or more than its amount.', ['amount' => $revenue->amount, 'tax' => $revenue->taxAmount]);
        }

        if ($posts && ($type === null || $type === PaymentType::Giftcard || $type === PaymentType::Rewards || $type === PaymentType::Due)) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$revenue->paymentType} is not one a receipt voucher is received with.", ['payment_type' => $revenue->paymentType]);
        }

        return new PostingFacts(
            operation: RevenuePostingBuilder::SOURCE,
            sourceId: $revenue->revenueId,
            date: $revenue->date,
            branchId: $revenue->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: $posts ? [
                'gross' => bcadd($revenue->amount, '0', SaleAmounts::SCALE),
                'tax'   => bcadd($revenue->taxAmount, '0', SaleAmounts::SCALE),
                'net'   => bcsub($revenue->amount, $revenue->taxAmount, SaleAmounts::SCALE),
            ] : ['gross' => '0', 'tax' => '0', 'net' => '0'],
            fields: [
                'account_id'          => $revenue->accountId,
                'category_account_id' => $revenue->categoryAccountId,
                'treasury_id'         => $revenue->treasuryId,
                'payment_type'        => $revenue->paymentType,
            ],
            flags: [
                'has_treasury' => $revenue->treasuryId !== null,
                'no_treasury'  => $revenue->treasuryId === null,
            ],
        );
    }
}
