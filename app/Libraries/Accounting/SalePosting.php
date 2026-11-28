<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Posts what happens to a sale: it is completed, its goods are delivered, a payment is added
 * or changed, it is cancelled. It runs inside the transaction of the change that caused it, so
 * the sale and its journals stand or fall together. A journal that is already there is never
 * written twice, and one that must change is reversed and written again as a new revision.
 *
 * The journals are the invoice (revenue), the cost of the goods delivered (cogs), and one for
 * every payment. See SalePostingBuilder for what each holds.
 */
final class SalePosting
{
    use RunsInTransaction;

    private const COMPLETED_TYPES = [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN];
    private const ISSUED_TYPES    = [SALE_TYPE_INVOICE, SALE_TYPE_RETURN];

    private BaseConnection $db;
    private PostingService $posting;
    private JournalService $journals;
    private SaleLoader $loader;
    private SalePostingBuilder $builder;
    private SalePaymentJournal $paymentJournal;
    private SaleJournal $saleJournal;
    private StockLedger $stock;
    private AccountingSettings $settings;
    private OperationsHold $hold;
    private PeriodGuard $periods;
    private CrossBranchPayments $crossBranch;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->posting  = new PostingService($this->db);
        $this->journals = new JournalService($this->db);
        $this->loader   = new SaleLoader($this->db);
        $this->stock    = new StockLedger($this->db);
        $this->settings = new AccountingSettings($this->db);
        $this->hold     = new OperationsHold($this->db);
        $this->periods  = new PeriodGuard($this->db);
        $this->builder  = SalePostingBuilder::forDatabase($this->db);

        $this->paymentJournal = SalePaymentJournal::forDatabase($this->db, $this->builder);
        $this->saleJournal    = SaleJournal::forDatabase($this->db, $this->builder);

        $this->crossBranch = new CrossBranchPayments($this->db);
    }

    /**
     * Whether a sale is one the books follow: accounting is on, the sale is a completed one and
     * it is dated on or after the day accounting starts.
     */
    public function follows(?SaleDocument $sale): bool
    {
        if ($sale === null || $sale->status !== COMPLETED || ! in_array($sale->saleType, self::COMPLETED_TYPES, true) || ! $this->settings->isEnabled()) {
            return false;
        }

        $start = $this->settings->startDate();

        return $start === null || $sale->date >= $start;
    }

    /**
     * Posts a sale that has just been completed: its invoice, a journal for each payment, and the
     * cost of its goods if they were delivered, in that order. The order only decides the numbers of
     * journals written from now on.
     *
     * @throws AccountingException|PostingException
     */
    public function postCompleted(int $saleId, ?int $employeeId = null): void
    {
        $sale = $this->loader->load($saleId);

        if (! $this->follows($sale)) {
            return;
        }

        $this->hold->assertOpen();

        $this->transactional(function () use ($sale, $employeeId): void {
            $sale = $this->withRates($sale);

            $this->builder->assertSettled($sale);
            $this->postInvoice($sale, $employeeId);

            foreach ($sale->payments as $payment) {
                $this->postPayment($sale, $payment, $employeeId);
            }

            $this->postCost($sale, $employeeId);
            $this->settle($sale->customerId, $employeeId);
        });
    }

    /**
     * Posts the cost of a sale whose goods have now been delivered: of the units that stayed sold, for
     * credit notes that were issued before the delivery took nothing out of the stock.
     *
     * @throws AccountingException|PostingException
     */
    public function postDelivery(int $saleId, ?int $employeeId = null): void
    {
        $sale = $this->loader->load($saleId);

        if (! $this->follows($sale) || $this->activeJournal(SalePostingBuilder::SOURCE_SALE, $saleId, JournalEntryType::Revenue) === null) {
            return;
        }

        $sale = $sale->withReturnedUnits((new UndeliveredReturns($this->db))->unitsOf($saleId));

        $this->transactional(function () use ($sale, $employeeId): void {
            $this->postCost($sale, $employeeId);
        });
    }

    /**
     * Brings the journals of the payments of a sale in line with the payments as they are now:
     * new ones are posted, changed ones reversed and posted again, and unchanged ones left alone.
     *
     * @throws AccountingException|PostingException
     */
    public function syncPayments(int $saleId, ?int $employeeId = null): void
    {
        $sale = $this->loader->load($saleId);

        if (! $this->follows($sale) || $this->activeJournal(SalePostingBuilder::SOURCE_SALE, $saleId, JournalEntryType::Revenue) === null) {
            return;
        }

        $this->hold->assertOpen();

        $this->transactional(function () use ($sale, $employeeId): void {
            $sale = $this->withRates($sale);

            $this->builder->assertSettled($sale);

            foreach ($sale->payments as $payment) {
                $this->postPayment($sale, $payment, $employeeId);
            }

            $this->settle($sale->customerId, $employeeId);
        });
    }

    /**
     * Takes back the journal of a payment that is about to be removed.
     *
     * @throws AccountingException|PostingException
     */
    public function reversePayment(int $paymentId, ?int $employeeId = null): void
    {
        $active = $this->activeJournal(SalePostingBuilder::SOURCE_PAYMENT, $paymentId, JournalEntryType::General);

        if ($active !== null) {
            (new TreasuryScopes($this->db))->assertMayReverse((int) $active->journal_id, $employeeId);

            $this->reverse($active, $employeeId, 'Payment removed');
        }

        $difference = $this->activeJournal(SalePostingBuilder::SOURCE_PAYMENT_FX, $paymentId, JournalEntryType::General);

        if ($difference !== null) {
            $this->reverse($difference, $employeeId, 'Payment removed');
        }
    }

    /**
     * Reverses every journal of a sale that has not been reversed: its invoice, its cost and
     * its payments. A sale that was never posted has none, and nothing happens. An invoice or a
     * credit note that has been issued is not cancelled: it is corrected by a credit note.
     *
     * @throws AccountingException|PostingException
     */
    public function cancel(int $saleId, ?int $employeeId = null): void
    {
        $this->transactional(function () use ($saleId, $employeeId): void {
            $sale = $this->loader->load($saleId, false);

            if ($sale !== null && in_array($sale->saleType, self::ISSUED_TYPES, true) && $this->isPosted($saleId)) {
                throw new AccountingException(AccountingError::IssuedDocumentLocked, 'An invoice or a credit note that has been issued cannot be cancelled. Correct it with a credit note.', ['sale_id' => $saleId]);
            }

            if ($this->db->tableExists($this->db->prefixTable('einvoice_documents'), false) && $this->db->table('einvoice_documents')->where(['source_type' => SalePostingBuilder::SOURCE_SALE, 'source_id' => $saleId])->countAllResults() > 0) {
                throw new AccountingException(AccountingError::ElectronicDocumentLocked, 'The electronic invoice of the sale has been issued: it is corrected by a credit note.', ['sale_id' => $saleId]);
            }

            $cost   = $this->activeJournal(SalePostingBuilder::SOURCE_SALE, $saleId, JournalEntryType::Cogs);
            $active = array_filter([
                $this->activeJournal(SalePostingBuilder::SOURCE_SALE, $saleId, JournalEntryType::Revenue),
                $cost,
            ]);

            foreach ($this->paymentIds($saleId) as $paymentId) {
                $payment = $this->activeJournal(SalePostingBuilder::SOURCE_PAYMENT, $paymentId, JournalEntryType::General);

                if ($payment !== null) {
                    $active[] = $payment;
                }

                $difference = $this->activeJournal(SalePostingBuilder::SOURCE_PAYMENT_FX, $paymentId, JournalEntryType::General);

                if ($difference !== null) {
                    $active[] = $difference;
                }
            }

            $stockDate    = date('Y-m-d');
            $stockJournal = null;
            $scopes       = new TreasuryScopes($this->db);

            foreach ($active as $journal) {
                $scopes->assertMayReverse((int) $journal->journal_id, $employeeId);
            }

            foreach ($active as $journal) {
                [$reversalId, $date] = $this->reverse($journal, $employeeId, 'Sale #' . $saleId . ' cancelled');

                if ($journal === $cost) {
                    $stockDate    = $date;
                    $stockJournal = $reversalId;
                }
            }

            $this->stock->reverseSource(SalePostingBuilder::SOURCE_SALE, $saleId, JournalEntryType::Cogs, $stockDate, $stockJournal);

            if ($active !== []) {
                $this->settle($sale?->customerId, $employeeId);
            }
        });
    }

    /**
     * Whether the sale has an invoice journal that stands.
     */
    public function isPosted(int $saleId): bool
    {
        return $this->activeJournal(SalePostingBuilder::SOURCE_SALE, $saleId, JournalEntryType::Revenue) !== null;
    }

    /**
     * A sale that has been posted keeps its customer and its date, as an invoice that has been
     * issued does. What it needs corrected is corrected by a return. The day typed on an invoice is
     * never changed after it was saved, whether its journal stands or not, because the journal takes
     * that day. Only the changes named are compared.
     *
     * @param array{customer_id?: ?int, date?: string, invoice_date?: string, currency_code?: ?string, currency_rate?: ?string} $changes
     *
     * @throws AccountingException
     */
    public function assertEditable(int $saleId, array $changes): void
    {
        if ($changes === []) {
            return;
        }

        $posted = $this->isPosted($saleId);

        if (! $posted && ! array_key_exists('invoice_date', $changes)) {
            return;
        }

        $sale = $this->loader->load($saleId, false);

        $currencyChanged = $posted && (array_key_exists('currency_code', $changes) || array_key_exists('currency_rate', $changes))
            && ($sale === null || ! DocumentCurrency::unchanged($sale->currency, array_intersect_key($changes, ['currency_code' => 1, 'currency_rate' => 1])));

        if ($currencyChanged) {
            throw new AccountingException(AccountingError::DocumentCurrencyLocked, 'The currency and the rate of a sale that has been posted cannot change. Reverse it and enter it again, or correct it with a credit note.', ['sale_id' => $saleId]);
        }

        $customerChanged = $posted && array_key_exists('customer_id', $changes) && ($sale === null || $sale->customerId !== $changes['customer_id']);
        $dateChanged     = $posted && array_key_exists('date', $changes) && ($sale === null || $sale->savedOn !== $changes['date']);
        $invoiceChanged  = array_key_exists('invoice_date', $changes) && ($sale === null || $sale->date !== $changes['invoice_date']);

        if ($customerChanged || $dateChanged || $invoiceChanged) {
            throw new AccountingException(AccountingError::SaleLocked, 'The customer and the date of a sale that has been posted cannot change. Correct it with a return.', ['sale_id' => $saleId]);
        }
    }

    /**
     * The journals of a sale and of its payments, oldest first.
     *
     * @return list<object>
     */
    public function journalsOf(int $saleId): array
    {
        $paymentIds = $this->paymentIds($saleId);
        $builder    = $this->db->table('accounting_journals')
            ->select('journal_id, number, journal_date, status, source_type, source_id, entry_type, revision, total_debit')
            ->where('status !=', JournalStatus::Preview->value)
            ->groupStart()
            ->groupStart()->where('source_type', SalePostingBuilder::SOURCE_SALE)->where('source_id', $saleId)->groupEnd();

        if ($paymentIds !== []) {
            $builder->orGroupStart()->whereIn('source_type', [SalePostingBuilder::SOURCE_PAYMENT, SalePostingBuilder::SOURCE_PAYMENT_FX, ...CrossBranchPayments::companionsOf(SalePostingBuilder::SOURCE_PAYMENT)])->whereIn('source_id', $paymentIds)->groupEnd();
        }

        return $builder->groupEnd()->orderBy('journal_id', 'ASC')->get()->getResult();
    }

    /**
     * Writes down why a sale could not be posted. It must run after the sale's transaction has
     * rolled back, or the row would go with it.
     */
    public function recordFailure(int $saleId, AccountingException|PostingException $failure, ?int $employeeId = null, ?int $branchId = null): void
    {
        $posting = $failure instanceof PostingException
            ? $failure
            : new PostingException(PostingError::WriteFailed, $failure->getMessage(), $failure->context, $failure);

        (new PostingFailureLog($this->db))->recordUnbuilt(SalePostingBuilder::SOURCE_SALE, $saleId, 1, JournalEntryType::Revenue, $posting, $employeeId, $branchId);
    }

    private function settle(?int $customerId, ?int $employeeId): void
    {
        if ($customerId !== null) {
            (new CreditSettlement($this->db))->settle($customerId, $employeeId);
        }
    }

    /**
     * @throws PostingException
     */
    private function postInvoice(SaleDocument $sale, ?int $employeeId): void
    {
        if ($this->activeJournal(SalePostingBuilder::SOURCE_SALE, $sale->saleId, JournalEntryType::Revenue) !== null) {
            return;
        }

        $draft = $this->saleJournal->invoice($sale, $this->nextRevision(SalePostingBuilder::SOURCE_SALE, $sale->saleId, JournalEntryType::Revenue), $employeeId);

        if ($draft !== null) {
            $this->posting->post((new JournalDescriptions($this->db))->ofSale($draft, $sale));
        }
    }

    /**
     * @throws PostingException
     */
    private function postCost(SaleDocument $sale, ?int $employeeId): void
    {
        if ($this->activeJournal(SalePostingBuilder::SOURCE_SALE, $sale->saleId, JournalEntryType::Cogs) !== null) {
            return;
        }

        $revision  = $this->nextRevision(SalePostingBuilder::SOURCE_SALE, $sale->saleId, JournalEntryType::Cogs);
        $plan      = $this->saleJournal->costPlan($sale, $revision, $employeeId);
        $journalId = $plan->journal === null ? null : $this->posting->post($plan->journal)->journalId;

        $this->stock->record(SalePostingBuilder::SOURCE_SALE, $sale->saleId, JournalEntryType::Cogs, $revision, $sale->date, $sale->branchId, $plan->movements, $journalId);
    }

    /**
     * The journal of a payment, and the exchange difference that goes with it when the document is in a
     * foreign currency.
     *
     * @throws AccountingException|PostingException
     */
    private function postPayment(SaleDocument $sale, SalePayment $payment, ?int $employeeId): void
    {
        $this->postPaymentJournal($sale, $payment, $employeeId);
        $this->postExchangeDifference($sale, $payment, $employeeId);
    }

    /**
     * Brings the exchange difference of a payment in line with what the payments of the document say it
     * is now: posted when there is none, left when it holds, and taken back and posted again when an
     * earlier payment of the document changed what this one settles.
     *
     * @throws AccountingException|PostingException
     */
    private function postExchangeDifference(SaleDocument $sale, SalePayment $payment, ?int $employeeId): void
    {
        if ($sale->currency === null) {
            return;
        }

        $active = $this->activeJournal(SalePostingBuilder::SOURCE_PAYMENT_FX, $payment->paymentId, JournalEntryType::General);
        $draft  = $this->paymentJournal->paymentDifference($sale, $payment, $this->nextRevision(SalePostingBuilder::SOURCE_PAYMENT_FX, $payment->paymentId, JournalEntryType::General), $employeeId);

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
     * The sale with the rate of every payment of a foreign document that has none yet: the rate of the
     * latest day on or before the day of the payment, written on the payment so that it is never taken
     * again. A foreign document has no rate to take a payment at when the table has none, and is not posted.
     *
     * @throws AccountingException
     */
    private function withRates(SaleDocument $sale): SaleDocument
    {
        if ($sale->currency === null) {
            return $sale;
        }

        $currencies = new Currencies($this->db);
        $captured   = false;

        foreach ($sale->payments as $payment) {
            if ($payment->rate !== null || ! $payment->status->posts()) {
                continue;
            }

            $rate = $currencies->rates()->rateOn($sale->currency->code, $payment->date);

            if ($rate === null) {
                throw new AccountingException(AccountingError::RateMissing, "Currency {$sale->currency->code} has no rate on or before {$payment->date}.", ['code' => $sale->currency->code, 'date' => $payment->date]);
            }

            $this->db->table('sales_payments')->where('payment_id', $payment->paymentId)->where('currency_rate', null)->update(['currency_code' => $sale->currency->code, 'currency_rate' => $rate]);
            $captured = true;
        }

        return $captured ? ($this->loader->load($sale->saleId) ?? $sale) : $sale;
    }

    /**
     * @throws AccountingException|PostingException
     */
    private function postPaymentJournal(SaleDocument $sale, SalePayment $payment, ?int $employeeId): void
    {
        if ($sale->currency !== null && $payment->treasuryBranchId !== null && $payment->treasuryBranchId !== $sale->branchId) {
            throw new PostingException(PostingError::FxNotSupported, 'A payment of a document in a foreign currency cannot go into a treasury of another branch yet.', ['what' => 'cross_branch', 'payment_id' => $payment->paymentId]);
        }

        $active = $this->activeJournal(SalePostingBuilder::SOURCE_PAYMENT, $payment->paymentId, JournalEntryType::General);
        $draft  = $this->paymentJournal->payment($sale, $payment, $this->nextRevision(SalePostingBuilder::SOURCE_PAYMENT, $payment->paymentId, JournalEntryType::General), $employeeId);
        $drafts = $draft === null ? [] : $this->crossBranch->drafts($draft, $payment->treasuryId, $payment->treasuryBranchId);

        if (count($drafts) > 1) {
            $this->crossBranch->sync($active, $drafts, $employeeId, fn (object $journal): array => $this->reverse($journal, $employeeId, 'Payment #' . $payment->paymentId . ' changed'));

            return;
        }

        if ($active !== null) {
            if ($draft !== null && $this->holds($active, $draft)) {
                return;
            }

            (new TreasuryScopes($this->db))->assertMayReplace((int) $active->journal_id, $draft, $employeeId);

            $this->reverse($active, $employeeId, 'Payment #' . $payment->paymentId . ' changed');
        } elseif ($draft !== null) {
            (new TreasuryScopes($this->db))->assertMayPost($draft->lines, $employeeId);
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

    /**
     * The journal of a source that has not been reversed, if there is one. A request that
     * changes a sale takes the lock of its row first, so that what this reads is what the
     * request before it left.
     */
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

    private function nextRevision(string $sourceType, int $sourceId, JournalEntryType $entryType): int
    {
        $row = $this->db->table('accounting_journals')
            ->selectMax('revision', 'last')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', $entryType->value)
            ->get()
            ->getRow();

        return ((int) ($row->last ?? 0)) + 1;
    }

    /**
     * @return list<int>
     */
    private function paymentIds(int $saleId): array
    {
        return array_map('intval', array_column($this->db->table('sales_payments')->select('payment_id')->where('sale_id', $saleId)->get()->getResultArray(), 'payment_id'));
    }
}
