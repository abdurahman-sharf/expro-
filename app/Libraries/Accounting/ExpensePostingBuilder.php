<?php

namespace App\Libraries\Accounting;

/**
 * The journal a saved expense needs, as a draft. It writes nothing. The expense is paid out of a
 * treasury straight away and owes nobody: what the expense cost goes to its account, the tax in it
 * goes to the account of tax paid, and the whole amount comes out of the treasury it was paid from.
 * An expense that is not paid yet is owed to other creditors until it is paid, which changing its way
 * of payment settles. Whatever cannot be decided with certainty is refused with a named failure.
 */
final class ExpensePostingBuilder
{
    public const SOURCE         = 'expense';
    private const OTHER_PAYABLE = 'other_payables';

    public function __construct(private readonly RoutingResolver $resolver)
    {
    }

    /**
     * The journal of the expense, or null when it comes to nothing.
     *
     * @throws PostingException
     */
    public function expense(ExpenseDocument $expense, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        $sign = bccomp($expense->amount, '0', SaleAmounts::SCALE);

        if ($sign === 0) {
            return null;
        }

        $gross   = SaleAmounts::absolute($expense->amount);
        $tax     = SaleAmounts::absolute($expense->taxAmount);
        $taxSign = bccomp($expense->taxAmount, '0', SaleAmounts::SCALE);

        if (($taxSign !== 0 && $taxSign !== $sign) || bccomp($tax, $gross, SaleAmounts::SCALE) > 0) {
            throw new PostingException(PostingError::ExpenseTaxInvalid, 'The tax of an expense has the sign of its amount and is not more than it.', ['amount' => $expense->amount, 'tax' => $expense->taxAmount]);
        }

        $net   = bcsub($gross, $tax, SaleAmounts::SCALE);
        $paid  = $this->paidAccount($expense);
        $costs = [];

        if (bccomp($net, '0', SaleAmounts::SCALE) > 0) {
            $costs[] = [$this->expenseAccount($expense), $net];
        }

        if (bccomp($tax, '0', SaleAmounts::SCALE) > 0) {
            $costs[] = [$this->required(RoutingKey::TaxInput), $tax];
        }

        $lines = [];

        foreach ($costs as [$account, $amount]) {
            $lines[] = $sign > 0 ? new JournalLineDraft($account, debit: $amount) : new JournalLineDraft($account, credit: $amount);
        }

        $lines[] = $sign > 0 ? new JournalLineDraft($paid, credit: $gross) : new JournalLineDraft($paid, debit: $gross);

        return new JournalDraft(
            date: $expense->date,
            lines: $lines,
            sourceType: self::SOURCE,
            sourceId: $expense->expenseId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: 'Expense #' . $expense->expenseId,
            branchId: $expense->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * The account the cost goes to: the account of the category of the expense, or else the
     * account of expenses the routing names.
     *
     * @throws PostingException
     */
    private function expenseAccount(ExpenseDocument $expense): int
    {
        return $expense->categoryAccountId ?? $this->required(RoutingKey::ExpensesDefault);
    }

    /**
     * Where the money came from: the treasury the expense names, or else the account its way of
     * payment is routed to. An expense that is due is owed to other creditors.
     *
     * @throws PostingException
     */
    private function paidAccount(ExpenseDocument $expense): int
    {
        $type = PaymentType::fromLabel($expense->paymentType);

        if ($type === null || $type === PaymentType::Giftcard || $type === PaymentType::Rewards) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$expense->paymentType} is not one an expense is paid with.", ['payment_type' => $expense->paymentType]);
        }

        if ($type === PaymentType::Due) {
            return $this->resolver->systemAccount(self::OTHER_PAYABLE);
        }

        $account = $expense->treasuryId !== null
            ? $this->resolver->accountFor(RoutingKey::Treasuries, $expense->treasuryId)
            : $this->resolver->accountForPayment($type);

        if ($account === null) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$expense->paymentType} has no account to be paid from.", ['payment_type' => $expense->paymentType]);
        }

        return $account;
    }

    /**
     * @throws PostingException
     */
    private function required(RoutingKey $key): int
    {
        $account = $this->resolver->accountFor($key);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but an expense cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }
}
