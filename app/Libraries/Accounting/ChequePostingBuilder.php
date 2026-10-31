<?php

namespace App\Libraries\Accounting;

/**
 * The journals a cheque needs when its status changes, as drafts. It writes nothing. A received
 * cheque is held in notes receivable against the customer, moves to funds under collection when it
 * is deposited and leaves for the bank when it is collected, or goes back to the customer when it
 * is rejected. A cheque paid to a supplier posts nothing when it is issued, is held in notes payable
 * against the supplier when it is delivered, and leaves the bank when it is collected or goes back
 * to the supplier when it is rejected.
 */
final class ChequePostingBuilder
{
    public function __construct(private readonly RoutingResolver $resolver)
    {
    }

    /**
     * @param numeric-string $fees What the bank kept when it collected a deposited cheque.
     *
     * @return list<JournalDraft> One journal, or two for a deposited cheque that is rejected, in the order they are posted.
     *
     * @throws AccountingException|PostingException
     */
    public function drafts(ChequeDocument $cheque, ChequeTransition $transition, string $date, string $fees, int $firstRevision, ?int $employeeId): array
    {
        $source = $transition->source();

        if ($source === null) {
            return [];
        }

        $legs = match ($transition) {
            ChequeTransition::Receive        => [[$this->notesReceivable(), $this->customer($cheque)]],
            ChequeTransition::Deposit        => [[$this->underCollection(), $this->notesReceivable()]],
            ChequeTransition::Collect        => [[$this->bank($cheque), $this->notesReceivable()]],
            ChequeTransition::DepositCollect => [[null, $this->underCollection()]],
            ChequeTransition::Reject         => [[$this->customer($cheque), $this->notesReceivable()]],
            ChequeTransition::DepositReject  => [[$this->customer($cheque), $this->notesReceivable()], [$this->notesReceivable(), $this->underCollection()]],
            ChequeTransition::Deliver        => [[$this->supplier($cheque), $this->notesPayable()]],
            ChequeTransition::PayCollect     => [[$this->notesPayable(), $this->bank($cheque)]],
            ChequeTransition::PayReject      => [[$this->notesPayable(), $this->supplier($cheque)]],
            default                          => [],
        };

        $drafts = [];

        foreach ($legs as $index => [$debited, $credited]) {
            $lines = $transition === ChequeTransition::DepositCollect
                ? $this->collectedAfterDeposit($cheque, $fees, $credited)
                : [new JournalLineDraft($debited, debit: $cheque->amount), new JournalLineDraft($credited, credit: $cheque->amount)];

            $drafts[] = new JournalDraft(
                date: $date,
                lines: $lines,
                sourceType: $source,
                sourceId: $cheque->chequeId,
                revision: $firstRevision + $index,
                origin: JournalOrigin::System,
                employeeId: $employeeId,
                description: 'Cheque ' . $cheque->chequeNumber . ' ' . $transition->toStatus()->value,
                branchId: $cheque->branchId,
                entryType: JournalEntryType::General,
            );
        }

        return $drafts;
    }

    /**
     * @param numeric-string $fees
     *
     * @return list<JournalLineDraft>
     *
     * @throws AccountingException|PostingException
     */
    private function collectedAfterDeposit(ChequeDocument $cheque, string $fees, int $underCollection): array
    {
        if (bccomp($fees, '0', SaleAmounts::SCALE) < 0 || bccomp($fees, $cheque->amount, SaleAmounts::SCALE) > 0) {
            throw new AccountingException(AccountingError::ChequeFeesInvalid, 'The deposit fees are not less than zero or more than the cheque.', ['fees' => $fees]);
        }

        $net   = bcsub($cheque->amount, $fees, SaleAmounts::SCALE);
        $lines = [];

        if (bccomp($net, '0', SaleAmounts::SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->bank($cheque), debit: $net);
        }

        if (bccomp($fees, '0', SaleAmounts::SCALE) > 0) {
            $lines[] = new JournalLineDraft($this->required(RoutingKey::ChequeDepositFees), debit: $fees);
        }

        $lines[] = new JournalLineDraft($underCollection, credit: $cheque->amount);

        return $lines;
    }

    /**
     * @throws PostingException
     */
    private function customer(ChequeDocument $cheque): int
    {
        return $this->entityAccount(RoutingKey::Customers, $cheque->partyId);
    }

    /**
     * @throws PostingException
     */
    private function supplier(ChequeDocument $cheque): int
    {
        return $this->entityAccount(RoutingKey::Suppliers, $cheque->partyId);
    }

    /**
     * @throws PostingException
     */
    private function bank(ChequeDocument $cheque): int
    {
        return $this->entityAccount(RoutingKey::Treasuries, $cheque->treasuryId);
    }

    /**
     * @throws PostingException
     */
    private function notesReceivable(): int
    {
        return $this->required(RoutingKey::NotesReceivable);
    }

    /**
     * @throws PostingException
     */
    private function underCollection(): int
    {
        return $this->required(RoutingKey::ChequesUnderCollection);
    }

    /**
     * @throws PostingException
     */
    private function notesPayable(): int
    {
        return $this->required(RoutingKey::NotesPayable);
    }

    /**
     * @throws PostingException
     */
    private function entityAccount(RoutingKey $key, int $entityId): int
    {
        return $this->required($key, $entityId);
    }

    /**
     * @throws PostingException
     */
    private function required(RoutingKey $key, ?int $entityId = null): int
    {
        $account = $this->resolver->accountFor($key, $entityId);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a cheque cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }
}
