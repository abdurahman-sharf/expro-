<?php

namespace App\Libraries\Accounting;

/**
 * The journal a saved receipt voucher needs, as a draft. It writes nothing. The whole amount goes into
 * the treasury it was received in, what the voucher came to without its tax is credited to the
 * account chosen on the voucher, or else the account of its category, or else the account of other
 * revenue the routing names, and the tax in it goes to the routed account of tax collected.
 */
final class RevenuePostingBuilder
{
    public const SOURCE = 'revenue';

    public function __construct(private readonly RoutingResolver $resolver)
    {
    }

    /**
     * @throws PostingException
     */
    public function revenue(RevenueDocument $revenue, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        if (bccomp($revenue->amount, '0', SaleAmounts::SCALE) <= 0) {
            return null;
        }

        $tax = $revenue->taxAmount;

        if (bccomp($tax, '0', SaleAmounts::SCALE) < 0 || bccomp($tax, $revenue->amount, SaleAmounts::SCALE) > 0) {
            throw new PostingException(PostingError::RevenueTaxInvalid, 'The tax of a receipt voucher is not less than zero or more than its amount.', ['amount' => $revenue->amount, 'tax' => $tax]);
        }

        $net   = bcsub($revenue->amount, $tax, SaleAmounts::SCALE);
        $lines = [new JournalLineDraft($this->receivedInto($revenue), debit: $revenue->amount)];

        if (bccomp($net, '0', SaleAmounts::SCALE) > 0) {
            $lines[] = new JournalLineDraft($revenue->accountId ?? $revenue->categoryAccountId ?? $this->required(RoutingKey::RevenuesDefault), credit: $net);
        }

        if (bccomp($tax, '0', SaleAmounts::SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->required(RoutingKey::TaxOutput), credit: $tax);
        }

        return new JournalDraft(
            date: $revenue->date,
            lines: $lines,
            sourceType: self::SOURCE,
            sourceId: $revenue->revenueId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: 'Receipt voucher #' . $revenue->revenueId,
            branchId: $revenue->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * Where the money went: the treasury the voucher names, or else the account its way of payment is
     * routed to.
     *
     * @throws PostingException
     */
    private function receivedInto(RevenueDocument $revenue): int
    {
        $type = PaymentType::fromLabel($revenue->paymentType);

        if ($type === null || $type === PaymentType::Giftcard || $type === PaymentType::Rewards || $type === PaymentType::Due) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$revenue->paymentType} is not one a receipt voucher is received with.", ['payment_type' => $revenue->paymentType]);
        }

        $account = $revenue->treasuryId !== null
            ? $this->resolver->accountFor(RoutingKey::Treasuries, $revenue->treasuryId)
            : $this->resolver->accountForPayment($type);

        if ($account === null) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$revenue->paymentType} has no account to be received into.", ['payment_type' => $revenue->paymentType]);
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
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a receipt voucher cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }
}
