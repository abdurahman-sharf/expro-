<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;

/**
 * What every document that moves cash for a customer or a supplier without an invoice or a purchase
 * needs before it posts: an amount, a way of payment the books can place, the treasury and its scope
 * for the direction the money goes, the branch it belongs to, and the taking back of its journal. The
 * scope of the treasury is the one place that says who may put money in and who may take it out, so an
 * advance, a refund and their reversals all ask it here.
 */
final class CashMovement
{
    private const REASON_MAX = 255;

    public function __construct(private readonly BaseConnection $db, private readonly BranchGuard $branches)
    {
    }

    /**
     * @return numeric-string
     *
     * @throws AccountingException
     */
    public function amount(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $value) !== 1 || bccomp($value, '0', SaleAmounts::SCALE) <= 0) {
            throw new AccountingException(AccountingError::AdvanceAmountInvalid, 'The amount must be more than zero.');
        }

        return bcadd($value, '0', SaleAmounts::SCALE);
    }

    /**
     * @throws AccountingException
     */
    public function paymentType(string $label): PaymentType
    {
        $type = PaymentType::fromLabel($label);

        if ($type === null || $type === PaymentType::Due || $type === PaymentType::Giftcard || $type === PaymentType::Rewards) {
            throw new AccountingException(AccountingError::CreditPaymentUnknown, "The payment type {$label} cannot be used here.", ['payment_type' => $label]);
        }

        return $type;
    }

    /**
     * Where the money goes in or comes out: the treasury named when the way of payment uses one, or
     * else the account the way of payment is routed to, whose treasury then governs. The employee must
     * be allowed that direction of the treasury and must work in its branch.
     *
     * @param TreasuryScopes::DEPOSIT|TreasuryScopes::WITHDRAW $direction
     *
     * @return array{treasury: ?int, branch: int, account: int}
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function place(PaymentType $type, ?int $treasuryId, string $direction, ?int $employeeId): array
    {
        $treasury = $treasuryId !== null && $treasuryId > 0 && $type->usesTreasury() ? $this->treasury($treasuryId) : null;
        $scopes   = new TreasuryScopes($this->db, $this->branches);

        if ($treasury !== null) {
            $scopes->assertAllows((int) $treasury->treasury_id, $direction, $employeeId);
        } else {
            $scopes->assertRoutedAllows($type, $direction, $employeeId);
        }

        $branchId = $treasury === null ? $this->branches->defaultBranchId($employeeId) : (int) $treasury->branch_id;

        $this->branches->requireBranch($branchId, $employeeId);

        $resolver = new RoutingResolver($this->db);
        $account  = $treasury === null ? $resolver->accountForPayment($type) : $resolver->accountFor(RoutingKey::Treasuries, (int) $treasury->treasury_id);

        if ($account === null) {
            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$type->value} has no account to be paid through.", ['payment_type' => $type->value]);
        }

        return ['treasury' => $treasury === null ? null : (int) $treasury->treasury_id, 'branch' => $branchId, 'account' => $account];
    }

    /**
     * Takes the journal of a document back: the treasuries it touched must let the employee make the
     * opposite movement, and a closed period moves the reversal to today instead of rewriting it.
     *
     * @throws AccountingException|PostingException
     */
    public function reverse(string $source, int $sourceId, int $branchId, string $reason, ?int $employeeId): void
    {
        $journal = $this->db->table('accounting_journals')
            ->select('journal_id, journal_date')
            ->where('source_type', $source)
            ->where('source_id', $sourceId)
            ->where('entry_type', JournalEntryType::General->value)
            ->where('status', JournalStatus::Posted->value)
            ->orderBy('revision', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();

        if ($journal === null) {
            throw new AccountingException(AccountingError::AdvanceAlreadyCancelled, 'There is nothing posted to cancel.', ['source_id' => $sourceId]);
        }

        (new TreasuryScopes($this->db, $this->branches))->assertMayReverse((int) $journal->journal_id, $employeeId);

        $cutoff = (new PeriodGuard($this->db))->effectiveCutoff($branchId, true);
        $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

        (new JournalService($this->db))->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        return $reason;
    }

    /**
     * A key the form carries so that sending it twice saves one document: empty is none.
     */
    public function requestKey(?string $key): ?string
    {
        $key = $key === null ? '' : mb_substr(trim($key), 0, 64);

        return $key === '' ? null : $key;
    }

    /**
     * An active treasury.
     *
     * @throws AccountingException
     */
    private function treasury(int $treasuryId): object
    {
        $treasury = (new TreasuryService($this->db))->find($treasuryId);

        if ($treasury === null) {
            throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$treasuryId} does not exist.", ['treasury_id' => $treasuryId]);
        }

        if ((int) $treasury->active === 0) {
            throw new AccountingException(AccountingError::TreasuryInactive, 'A payment cannot go through a deactivated treasury.', ['treasury_id' => $treasuryId]);
        }

        return $treasury;
    }
}
