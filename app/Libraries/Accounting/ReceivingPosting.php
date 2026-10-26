<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Posts what happens to a purchase: it is saved, or deleted. It runs inside the transaction of
 * the change that caused it, so the purchase, its stock and its journals stand or fall together.
 * A journal that is already there is never written twice, and neither are the stock movements
 * that go with it.
 *
 * The journals are the purchase itself and, when it was paid at once, one for the payment, and
 * one for each payment made against it later. After anything that changes what is owed to a
 * supplier the credit held with them is settled (SupplierSettlement), inside the same
 * transaction. See ReceivingPostingBuilder for what each journal holds.
 */
final class ReceivingPosting
{
    use RunsInTransaction;

    private BaseConnection $db;
    private PostingService $posting;
    private JournalService $journals;
    private ReceivingLoader $loader;
    private ReceivingPostingBuilder $builder;
    private StockLedger $ledger;
    private AccountingSettings $settings;
    private PeriodGuard $periods;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->posting  = new PostingService($this->db);
        $this->journals = new JournalService($this->db);
        $this->loader   = new ReceivingLoader($this->db);
        $this->ledger   = new StockLedger($this->db);
        $this->settings = new AccountingSettings($this->db);
        $this->periods  = new PeriodGuard($this->db);
        $this->builder  = new ReceivingPostingBuilder(new RoutingResolver($this->db), $this->ledger);
    }

    /**
     * Whether a purchase is one the books follow: accounting is on and the purchase is dated on
     * or after the day accounting starts.
     */
    public function follows(?ReceivingDocument $purchase): bool
    {
        if ($purchase === null || ! $this->settings->isEnabled()) {
            return false;
        }

        $start = $this->settings->startDate();

        return $start === null || $purchase->date >= $start;
    }

    /**
     * Posts a purchase that has just been saved: its journal and its stock movements, and the
     * journal of its payment. It must run before the stock of the purchase is changed, because
     * what stock leaving is worth is what the books say before it leaves.
     *
     * @throws AccountingException|PostingException
     */
    public function postCompleted(int $receivingId, ?int $employeeId = null): void
    {
        $purchase = $this->loader->load($receivingId);

        if (! $this->follows($purchase)) {
            return;
        }

        $this->transactional(function () use ($purchase, $employeeId): void {
            $this->postPurchase($purchase, $employeeId);
            $this->postPayment($purchase, $employeeId);
            $this->settle($purchase->supplierId, $employeeId);
        });
    }

    /**
     * Brings the journals of the payments made against a purchase in line with the payments as
     * they are now: new ones are posted, changed ones reversed and posted again, and unchanged
     * ones left alone. Then the credit of the supplier is settled.
     *
     * @throws AccountingException|PostingException
     */
    public function syncPayments(int $receivingId, ?int $employeeId = null): void
    {
        $purchase = $this->loader->load($receivingId, false);

        if (! $this->follows($purchase) || $this->activeJournal(ReceivingPostingBuilder::SOURCE_PURCHASE, $receivingId, JournalEntryType::General) === null) {
            return;
        }

        $this->transactional(function () use ($purchase, $employeeId): void {
            foreach ($purchase->payments as $payment) {
                $this->postSupplierPayment($purchase, $payment, $employeeId);
            }

            $this->settle($purchase->supplierId, $employeeId);
        });
    }

    /**
     * Takes back the journal of a payment that is about to be removed.
     *
     * @throws AccountingException|PostingException
     */
    public function reversePayment(int $paymentId, ?int $employeeId = null): void
    {
        $active = $this->activeJournal(ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, $paymentId, JournalEntryType::General);

        if ($active !== null) {
            (new TreasuryScopes($this->db))->assertMayReverse((int) $active->journal_id, $employeeId);

            $this->reverse($active, $employeeId, 'Payment removed');
        }
    }

    /**
     * Takes back the journals of a purchase that is being deleted, and its stock movements. A
     * purchase that was never posted has none, and nothing happens.
     *
     * @throws AccountingException|PostingException
     */
    public function cancel(int $receivingId, ?int $employeeId = null): void
    {
        $this->transactional(function () use ($receivingId, $employeeId): void {
            $reason   = 'Purchase #' . $receivingId . ' deleted';
            $date     = date('Y-m-d');
            $purchase = $this->activeJournal(ReceivingPostingBuilder::SOURCE_PURCHASE, $receivingId, JournalEntryType::General);
            $payment  = $this->activeJournal(ReceivingPostingBuilder::SOURCE_PAYMENT, $receivingId, JournalEntryType::General);
            $reversed = null;
            $document = $this->loader->load($receivingId, false);
            $later    = [];

            foreach ($this->paymentIds($receivingId) as $paymentId) {
                $journal = $this->activeJournal(ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, $paymentId, JournalEntryType::General);

                if ($journal !== null) {
                    $later[] = $journal;
                }
            }

            $scopes = new TreasuryScopes($this->db);

            foreach ([...$later, $payment, $purchase] as $journal) {
                if ($journal !== null) {
                    $scopes->assertMayReverse((int) $journal->journal_id, $employeeId);
                }
            }

            foreach ([...$later, $payment, $purchase] as $journal) {
                if ($journal === null) {
                    continue;
                }

                [$reversalId, $date] = $this->reverse($journal, $employeeId, $reason);

                if ($journal === $purchase) {
                    $reversed = $reversalId;
                }
            }

            $this->ledger->reverseSource(ReceivingPostingBuilder::SOURCE_PURCHASE, $receivingId, JournalEntryType::General, $date, $reversed);

            if ($purchase !== null || $payment !== null || $later !== []) {
                $this->settle($document?->supplierId, $employeeId);
            }
        });
    }

    /**
     * Whether the purchase has reached the books: a journal that stands, or stock movements.
     */
    public function isPosted(int $receivingId): bool
    {
        return $this->activeJournal(ReceivingPostingBuilder::SOURCE_PURCHASE, $receivingId, JournalEntryType::General) !== null
            || $this->db->table('accounting_stock_entries')->where('source_type', ReceivingPostingBuilder::SOURCE_PURCHASE)->where('source_id', $receivingId)->countAllResults() > 0;
    }

    /**
     * A purchase that has been posted keeps its supplier and its date, as an invoice that has
     * been issued does. Only the changes named are compared.
     *
     * @param array{supplier_id?: ?int, date?: string} $changes
     *
     * @throws AccountingException
     */
    public function assertEditable(int $receivingId, array $changes): void
    {
        if ($changes === [] || ! $this->isPosted($receivingId)) {
            return;
        }

        $purchase = $this->loader->load($receivingId, false);

        $supplierChanged = array_key_exists('supplier_id', $changes) && ($purchase === null || $purchase->supplierId !== $changes['supplier_id']);
        $dateChanged     = array_key_exists('date', $changes) && ($purchase === null || $purchase->date !== $changes['date']);

        if ($supplierChanged || $dateChanged) {
            throw new AccountingException(AccountingError::PurchaseLocked, 'The supplier and the date of a purchase that has been posted cannot change. Correct it with a return.', ['receiving_id' => $receivingId]);
        }
    }

    /**
     * The journals of a purchase and of its payment, oldest first.
     *
     * @return list<object>
     */
    public function journalsOf(int $receivingId): array
    {
        $builder = $this->db->table('accounting_journals')
            ->select('journal_id, number, journal_date, status, source_type, source_id, entry_type, revision, total_debit')
            ->groupStart()
            ->groupStart()
            ->whereIn('source_type', [ReceivingPostingBuilder::SOURCE_PURCHASE, ReceivingPostingBuilder::SOURCE_PAYMENT])
            ->where('source_id', $receivingId)
            ->groupEnd();

        $paymentIds = $this->paymentIds($receivingId);

        if ($paymentIds !== []) {
            $builder->orGroupStart()
                ->where('source_type', ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT)
                ->whereIn('source_id', $paymentIds)
                ->groupEnd();
        }

        return $builder->groupEnd()->orderBy('journal_id', 'ASC')->get()->getResult();
    }

    /**
     * Writes down why a purchase could not be posted. It must run after the purchase's
     * transaction has rolled back, or the row would go with it.
     */
    public function recordFailure(?int $receivingId, AccountingException|PostingException $failure, ?int $employeeId = null, ?int $branchId = null): void
    {
        $posting = $failure instanceof PostingException
            ? $failure
            : new PostingException(PostingError::WriteFailed, $failure->getMessage(), $failure->context, $failure);

        (new PostingFailureLog($this->db))->recordUnbuilt(ReceivingPostingBuilder::SOURCE_PURCHASE, $receivingId, 1, JournalEntryType::General, $posting, $employeeId, $branchId);
    }

    private function settle(?int $supplierId, ?int $employeeId): void
    {
        if ($supplierId !== null) {
            (new SupplierSettlement($this->db))->settle($supplierId, $employeeId);
        }
    }

    /**
     * @throws PostingException
     */
    private function postPurchase(ReceivingDocument $purchase, ?int $employeeId): void
    {
        $revision = $this->nextRevision(ReceivingPostingBuilder::SOURCE_PURCHASE, $purchase->receivingId);

        if ($this->activeJournal(ReceivingPostingBuilder::SOURCE_PURCHASE, $purchase->receivingId, JournalEntryType::General) !== null) {
            return;
        }

        $plan      = $this->builder->purchase($purchase, $revision, $employeeId);
        $journalId = $plan->journal === null ? null : $this->posting->post($plan->journal)->journalId;

        $this->ledger->record(ReceivingPostingBuilder::SOURCE_PURCHASE, $purchase->receivingId, JournalEntryType::General, $revision, $purchase->date, $purchase->branchId, $plan->movements, $journalId);
    }

    /**
     * @throws PostingException
     */
    private function postPayment(ReceivingDocument $purchase, ?int $employeeId): void
    {
        if ($this->activeJournal(ReceivingPostingBuilder::SOURCE_PAYMENT, $purchase->receivingId, JournalEntryType::General) !== null) {
            return;
        }

        $draft = $this->builder->payment($purchase, $this->nextRevision(ReceivingPostingBuilder::SOURCE_PAYMENT, $purchase->receivingId), $employeeId);

        if ($draft !== null) {
            $this->posting->post($draft);
        }
    }

    /**
     * @throws AccountingException|PostingException
     */
    private function postSupplierPayment(ReceivingDocument $purchase, SupplierPayment $payment, ?int $employeeId): void
    {
        $active = $this->activeJournal(ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, $payment->paymentId, JournalEntryType::General);
        $draft  = $this->builder->supplierPayment($purchase, $payment, $this->nextRevision(ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, $payment->paymentId), $employeeId);

        if ($active !== null) {
            if ($draft !== null && $this->holds($active, $draft)) {
                return;
            }

            $this->reverse($active, $employeeId, 'Payment #' . $payment->paymentId . ' changed');
        }

        if ($draft !== null) {
            $this->posting->post($draft);
        }
    }

    /**
     * Whether a journal that stands has the date and lines of a draft.
     */
    private function holds(object $journal, JournalDraft $draft): bool
    {
        if ($journal->journal_date !== $draft->date) {
            return false;
        }

        $stored = array_map(
            static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', SaleAmounts::SCALE) . ':' . bcadd($line->credit, '0', SaleAmounts::SCALE),
            $this->db->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->get()->getResult(),
        );

        $wanted = array_map(
            static fn (JournalLineDraft $line): string => $line->accountId . ':' . bcadd($line->debit, '0', SaleAmounts::SCALE) . ':' . bcadd($line->credit, '0', SaleAmounts::SCALE),
            $draft->lines,
        );

        sort($stored);
        sort($wanted);

        return $stored === $wanted;
    }

    /**
     * @return list<int>
     */
    private function paymentIds(int $receivingId): array
    {
        return array_map('intval', array_column($this->db->table('receivings_payments')->select('payment_id')->where('receiving_id', $receivingId)->get()->getResultArray(), 'payment_id'));
    }

    /**
     * @return array{int, string} The reversal and the date it was made on.
     *
     * @throws AccountingException|PostingException
     */
    private function reverse(object $journal, ?int $employeeId, string $reason): array
    {
        $cutoff = $this->periods->effectiveCutoff((int) $journal->branch_id, true);
        $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

        return [$this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId), $date];
    }

    private function activeJournal(string $sourceType, int $sourceId, JournalEntryType $entryType): ?object
    {
        return $this->db->table('accounting_journals')
            ->select('journal_id, journal_date, branch_id, revision')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', $entryType->value)
            ->where('status', JournalStatus::Posted->value)
            ->orderBy('revision', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();
    }

    private function nextRevision(string $sourceType, int $sourceId): int
    {
        $row = $this->db->table('accounting_journals')
            ->selectMax('revision', 'last')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', JournalEntryType::General->value)
            ->get()
            ->getRow();

        return ((int) ($row->last ?? 0)) + 1;
    }
}
