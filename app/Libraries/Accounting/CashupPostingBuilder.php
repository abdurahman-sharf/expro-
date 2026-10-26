<?php

namespace App\Libraries\Accounting;

/**
 * The journal a saved cash-up needs, as a draft. It writes nothing. A cash-up that counts exactly
 * what was expected posts nothing, because the books already hold what the sales and the expenses
 * put in the treasury. Only the difference is posted: cash that is short takes the cash difference
 * account against the treasury, and cash that is over puts it back the other way round.
 */
final class CashupPostingBuilder
{
    public const SOURCE = 'cashup';

    public function __construct(private readonly RoutingResolver $resolver)
    {
    }

    /**
     * The journal of the difference of the cash-up, or null when there is none to post.
     *
     * @throws PostingException
     */
    public function difference(CashupDocument $cashup, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        if ($cashup->difference === null) {
            return null;
        }

        $sign = bccomp($cashup->difference, '0', SaleAmounts::SCALE);

        if ($sign === 0) {
            return null;
        }

        $amount   = SaleAmounts::absolute($cashup->difference);
        $drawer   = $this->drawerAccount($cashup);
        $balancer = $this->resolver->accountFor(RoutingKey::CashDifference);

        if ($balancer === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, 'The routing of the cash difference is cancelled, but a cash-up cannot be posted without it.', ['routing_key' => RoutingKey::CashDifference->value]);
        }

        $lines = $sign < 0
            ? [new JournalLineDraft($balancer, debit: $amount), new JournalLineDraft($drawer, credit: $amount)]
            : [new JournalLineDraft($drawer, debit: $amount), new JournalLineDraft($balancer, credit: $amount)];

        return new JournalDraft(
            date: $cashup->date,
            lines: $lines,
            sourceType: self::SOURCE,
            sourceId: $cashup->cashupId,
            revision: $revision,
            origin: JournalOrigin::System,
            employeeId: $employeeId,
            description: 'Cash-up #' . $cashup->cashupId . ($sign < 0 ? ': cash short' : ': cash over'),
            branchId: $cashup->branchId,
            entryType: JournalEntryType::General,
        );
    }

    /**
     * The treasury the cash was counted in, or else the account cash payments are routed to.
     *
     * @throws PostingException
     */
    private function drawerAccount(CashupDocument $cashup): int
    {
        $account = $cashup->treasuryId !== null
            ? $this->resolver->accountFor(RoutingKey::Treasuries, $cashup->treasuryId)
            : $this->resolver->accountForPayment(PaymentType::Cash);

        if ($account === null) {
            throw new PostingException(PostingError::PaymentTypeUnknown, 'Cash has no account to be counted in.', ['payment_type' => PaymentType::Cash->value]);
        }

        return $account;
    }
}
