<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingFacts;

/**
 * The figures of a saved expense, for the posting engine: what was paid, the tax in it and what is
 * left of it, each with the sign of the expense, and the fields and flags the rules choose accounts
 * by. It decides no account and no side. An expense whose tax does not fit its amount, or whose way
 * of payment pays no expense, is refused here as it always was; an expense of nothing posts nothing
 * and is not checked.
 */
final class ExpenseFacts
{
    /**
     * @throws PostingException
     */
    public static function of(ExpenseDocument $expense, int $revision, ?int $employeeId): PostingFacts
    {
        $sign    = bccomp($expense->amount, '0', SaleAmounts::SCALE);
        $taxSign = bccomp($expense->taxAmount, '0', SaleAmounts::SCALE);

        if ($sign !== 0 && (($taxSign !== 0 && $taxSign !== $sign) || bccomp(SaleAmounts::absolute($expense->taxAmount), SaleAmounts::absolute($expense->amount), SaleAmounts::SCALE) > 0)) {
            throw new PostingException(PostingError::ExpenseTaxInvalid, 'The tax of an expense has the sign of its amount and is not more than it.', ['amount' => $expense->amount, 'tax' => $expense->taxAmount]);
        }

        $type = PaymentType::fromLabel($expense->paymentType);

        if ($sign !== 0 && ($type === null || $type === PaymentType::Giftcard || $type === PaymentType::Rewards)) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$expense->paymentType} is not one an expense is paid with.", ['payment_type' => $expense->paymentType]);
        }

        $due = $type === PaymentType::Due;

        return new PostingFacts(
            operation: ExpensePostingBuilder::SOURCE,
            sourceId: $expense->expenseId,
            date: $expense->date,
            branchId: $expense->branchId,
            revision: $revision,
            employeeId: $employeeId,
            figures: $sign === 0 ? ['gross' => '0', 'tax' => '0', 'net' => '0'] : [
                'gross' => bcadd($expense->amount, '0', SaleAmounts::SCALE),
                'tax'   => bcadd($expense->taxAmount, '0', SaleAmounts::SCALE),
                'net'   => bcsub($expense->amount, $expense->taxAmount, SaleAmounts::SCALE),
            ],
            fields: [
                'category_account_id' => $expense->categoryAccountId,
                'treasury_id'         => $expense->treasuryId,
                'payment_type'        => $expense->paymentType,
            ],
            flags: [
                'is_due'       => $due,
                'has_treasury' => ! $due && $expense->treasuryId !== null,
                'no_treasury'  => ! $due && $expense->treasuryId === null,
            ],
        );
    }
}
