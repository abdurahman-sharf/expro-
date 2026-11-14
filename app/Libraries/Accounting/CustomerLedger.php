<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reads what a customer owes from the journals, not from the sale rows: the total of an invoice
 * is what its journal debited the customer, a payment is what its journal credited, and a credit
 * application is the only thing the books do not carry as a journal. So the figures here are the
 * figures of the account of the customer, split by invoice.
 */
final class CustomerLedger
{
    private const SALE_TYPES = [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Where a customer stands now, or on a day of the past: what the invoices dated by then and the
     * payments and credit applied by then say, which adds up to the balance of the account on that day.
     */
    public function position(int $customerId, ?string $asOf = null): CustomerPosition
    {
        $accountId = (new EntityAccountService($this->db))->accountFor(EntityAccountService::CUSTOMER, $customerId);

        if ($accountId === null) {
            return new CustomerPosition($customerId, null, '0.0000', [], [], $asOf);
        }

        $sales    = $this->sales($customerId, $accountId, $asOf);
        $paid     = $this->paid($customerId, $accountId, $asOf);
        $applied  = $this->applied($customerId, $asOf);
        $returned = [];
        $credits  = [];
        $invoices = [];
        $currency = [];

        foreach ($sales as $sale) {
            if ((int) $sale->sale_type !== SALE_TYPE_RETURN) {
                continue;
            }

            $currency[CreditSource::CREDIT_NOTE . ':' . $sale->sale_id] = self::currencyOf($sale);

            $amount = bcadd(bcsub('0', $sale->total, SaleAmounts::SCALE), $paid[(int) $sale->sale_id] ?? '0', SaleAmounts::SCALE);
            $origin = $sale->return_of_sale_id === null ? null : (int) $sale->return_of_sale_id;

            if ($origin !== null) {
                $returned[$origin] = bcadd($returned[$origin] ?? '0', bcsub('0', $sale->total, SaleAmounts::SCALE), SaleAmounts::SCALE);
            }

            $credits[(int) $sale->sale_id] = [CreditSource::CREDIT_NOTE, (int) $sale->sale_id, $origin, (string) $sale->doc_date, $amount];
        }

        $figures = [];

        foreach ($this->openings($customerId, $accountId, $asOf) as $opening) {
            $total = (string) $opening->total;
            $key   = -(int) $opening->opening_id;

            if (bccomp($total, '0', SaleAmounts::SCALE) > 0) {
                $figures[$key] = new InvoiceFigures($key, (string) $opening->doc_date, SALE_TYPE_INVOICE, bcadd($total, '0', SaleAmounts::SCALE), '0.0000', $applied['into'][$key] ?? '0.0000', '0.0000', (string) $opening->doc_date, null, true);
            } else {
                $credits['opening:' . $opening->opening_id] = [CreditSource::OPENING, (int) $opening->opening_id, null, (string) $opening->doc_date, bcsub('0', $total, SaleAmounts::SCALE)];
            }
        }

        foreach ($this->debitNotes($customerId, $accountId, $asOf) as $note) {
            $key           = SalesDebitNotes::keyOf((int) $note->debit_note_id);
            $figures[$key] = new InvoiceFigures($key, (string) $note->doc_date, SALE_TYPE_INVOICE, bcadd((string) $note->total, '0', SaleAmounts::SCALE), '0.0000', $applied['into'][$key] ?? '0.0000', '0.0000', (string) $note->doc_date, null, false, true);
        }

        foreach ($sales as $sale) {
            if ((int) $sale->sale_type === SALE_TYPE_RETURN) {
                continue;
            }

            $saleId           = (int) $sale->sale_id;
            [$code, $rate]    = self::currencyOf($sale);
            $figures[$saleId] = new InvoiceFigures(
                $saleId,
                (string) $sale->doc_date,
                (int) $sale->sale_type,
                bcadd($sale->total, '0', SaleAmounts::SCALE),
                $paid[$saleId] ?? '0.0000',
                $applied['into'][$saleId] ?? '0.0000',
                $returned[$saleId] ?? '0.0000',
                $sale->due_date === null ? (string) $sale->doc_date : (string) $sale->due_date,
                $sale->payment_terms_days === null ? null : (int) $sale->payment_terms_days,
                currencyCode: $code,
                currencyRate: $rate,
            );
        }

        foreach ($figures as $saleId => $invoice) {
            $invoices[] = $invoice;
            $excess     = $invoice->excess();

            if (bccomp($excess, '0', SaleAmounts::SCALE) > 0) {
                $credits[$saleId] = [CreditSource::INVOICE_EXCESS, $saleId, null, $invoice->date, $excess];
            }
        }

        foreach ($this->advances($customerId, $accountId, $asOf) as $advance) {
            $credits['advance:' . $advance->advance_id] = [CreditSource::CUSTOMER_ADVANCE, (int) $advance->advance_id, null, (string) $advance->advance_date, bcadd((string) $advance->amount, '0', SaleAmounts::SCALE)];
        }

        foreach (ChequeCredits::ofCustomer($this->db, $customerId, $accountId, $asOf) as $cheque) {
            $credits['cheque:' . $cheque->cheque_id] = [CreditSource::CHEQUE, (int) $cheque->cheque_id, null, (string) $cheque->issue_date, bcadd((string) $cheque->amount, '0', SaleAmounts::SCALE)];
        }

        $refunded = $this->refunded($customerId, $asOf);
        $sources  = [];

        foreach ($credits as [$type, $sourceId, $origin, $date, $amount]) {
            [$code, $rate] = $currency[$type . ':' . $sourceId] ?? [null, null];

            $sources[] = new CreditSource($type, $sourceId, $origin, $date, $amount, $applied['from'][$type . ':' . $sourceId] ?? '0.0000', $refunded[$type . ':' . $sourceId] ?? '0.0000', $code, $rate);
        }

        // Credit is applied in this order: by the day an invoice is dated and, among invoices of one day,
        // by the order they were created in. The id is that order: it is given when the row is created,
        // while sale_time is rewritten by every later save of the same row.
        usort($sources, static fn (CreditSource $a, CreditSource $b): int => [$a->date, $a->sourceId] <=> [$b->date, $b->sourceId]);
        usort($invoices, static fn (InvoiceFigures $a, InvoiceFigures $b): int => [$a->date, $a->saleId] <=> [$b->date, $b->saleId]);

        return new CustomerPosition($customerId, $accountId, $this->accountBalance($accountId, $asOf), $invoices, $sources, $asOf);
    }

    /**
     * The invoices of several customers as position() holds them now, for the customers who have an account,
     * read in a few queries for all of them instead of a few for each: what each came to, what was paid into it,
     * what credit was applied to it and what was taken back by credit notes.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, array<int, InvoiceFigures>> By customer, then by sale.
     */
    public function invoicesOf(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_map('intval', $customerIds)));

        if ($customerIds === []) {
            return [];
        }

        $accounts = $this->db->prefixTable('accounting_accounts');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $idList   = implode(',', $customerIds);

        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', null);

        $sales = $this->db->query(
            'SELECT s.sale_id, s.customer_id, s.sale_type, s.sale_time, s.return_of_sale_id, s.due_date, s.payment_terms_days, s.currency_code, s.currency_rate,
                    COALESCE(s.invoice_date, DATE(s.sale_time)) AS doc_date,
                    (SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM ' . $lines . ' l WHERE l.journal_id = j.journal_id AND l.account_id = c.account_id) AS total
             FROM ' . $this->db->prefixTable('sales') . ' s
             JOIN ' . $accounts . ' c ON c.company_id = ? AND c.entity_type = ? AND c.entity_id = s.customer_id
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = s.sale_id AND j.entry_type = ? AND {$standing}
             WHERE s.customer_id IN ({$idList}) AND s.sale_type IN (" . implode(',', self::SALE_TYPES) . ') AND s.sale_status = ?
             ORDER BY doc_date ASC, s.sale_id ASC',
            [CompanyContext::id(), EntityAccountService::CUSTOMER, SalePostingBuilder::SOURCE_SALE, JournalEntryType::Revenue->value, ...$standingBindings, COMPLETED],
        )->getResult();

        $paid = [];

        foreach ($this->db->query(
            'SELECT p.sale_id, SUM(l.credit - l.debit) AS paid
             FROM ' . $this->db->prefixTable('sales_payments') . ' p
             JOIN ' . $this->db->prefixTable('sales') . " s ON s.sale_id = p.sale_id AND s.customer_id IN ({$idList})
             JOIN {$accounts} c ON c.company_id = ? AND c.entity_type = ? AND c.entity_id = s.customer_id
             JOIN " . $this->db->prefixTable('accounting_journals') . " j ON j.source_type IN (?, ?) AND j.source_id = p.payment_id AND {$standing}
             JOIN {$lines} l ON l.journal_id = j.journal_id AND l.account_id = c.account_id
             GROUP BY p.sale_id",
            [CompanyContext::id(), EntityAccountService::CUSTOMER, SalePostingBuilder::SOURCE_PAYMENT, SalePostingBuilder::SOURCE_PAYMENT_FX, ...$standingBindings],
        )->getResult() as $row) {
            $paid[(int) $row->sale_id] = bcadd($row->paid, '0', SaleAmounts::SCALE);
        }

        $applied = [];

        foreach ($this->db->table('customer_credit_applications')->whereIn('customer_id', $customerIds)->where('released_at', null)->orderBy('application_id', 'ASC')->get()->getResult() as $application) {
            $invoice           = self::targetOf($application);
            $applied[$invoice] = bcadd($applied[$invoice] ?? '0', $application->amount, SaleAmounts::SCALE);
        }

        $returned = [];

        foreach ($sales as $sale) {
            if ((int) $sale->sale_type === SALE_TYPE_RETURN && $sale->return_of_sale_id !== null) {
                $returned[(int) $sale->return_of_sale_id] = bcadd($returned[(int) $sale->return_of_sale_id] ?? '0', bcsub('0', $sale->total, SaleAmounts::SCALE), SaleAmounts::SCALE);
            }
        }

        $invoices = [];

        foreach ($sales as $sale) {
            if ((int) $sale->sale_type === SALE_TYPE_RETURN) {
                continue;
            }

            $saleId        = (int) $sale->sale_id;
            [$code, $rate] = self::currencyOf($sale);

            $invoices[(int) $sale->customer_id][$saleId] = new InvoiceFigures(
                $saleId,
                (string) $sale->doc_date,
                (int) $sale->sale_type,
                bcadd($sale->total, '0', SaleAmounts::SCALE),
                $paid[$saleId] ?? '0.0000',
                $applied[$saleId] ?? '0.0000',
                $returned[$saleId] ?? '0.0000',
                $sale->due_date === null ? (string) $sale->doc_date : (string) $sale->due_date,
                $sale->payment_terms_days === null ? null : (int) $sale->payment_terms_days,
                currencyCode: $code,
                currencyRate: $rate,
            );
        }

        return $invoices;
    }

    /**
     * The currency a sale was made in and the rate it was captured at, or nothing for a sale in the base currency.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function currencyOf(object $sale): array
    {
        if ($sale->currency_code === null) {
            return [null, null];
        }

        return [(string) $sale->currency_code, $sale->currency_rate === null ? null : (string) $sale->currency_rate];
    }

    /**
     * What the customers owe on a day, aged by the account: the payments and credit notes go to
     * the oldest invoice first and what is left of each invoice is aged by its own day, in buckets of
     * a period of days. Only the customers who owe something or hold credit are listed.
     *
     * @return list<AgedLedgerRow>
     */
    public function agedLedger(string $asOf, int $period = AgingBuckets::DEFAULT_PERIOD, ?int $customerId = null): array
    {
        return (new AgedLedger($this->db))->build(EntityAccountService::CUSTOMER, $asOf, $period, $customerId);
    }

    /**
     * The applications that stand for a customer, oldest first, or all of them with the ones
     * that were released.
     *
     * @return list<object>
     */
    public function applications(int $customerId, bool $withReleased = false): array
    {
        $builder = $this->db->table('customer_credit_applications')->where('customer_id', $customerId);

        if (! $withReleased) {
            $builder->where('released_at', null);
        }

        return $builder->orderBy('application_id', 'ASC')->get()->getResult();
    }

    /**
     * What an application of credit was spent on: the sale of an invoice, minus the number of an opening
     * balance, or the key of a sales debit note, which is how the invoices of a position are numbered.
     */
    public static function targetOf(object $application): int
    {
        if ($application->invoice_sale_id !== null) {
            return (int) $application->invoice_sale_id;
        }

        return ($application->debit_note_id ?? null) !== null ? SalesDebitNotes::keyOf((int) $application->debit_note_id) : -(int) $application->opening_id;
    }

    /**
     * The name the account of a customer goes by, which is the name of the customer.
     */
    public function accountName(int $customerId): string
    {
        $row = $this->db->table('accounting_accounts')->select('name')->where(['entity_type' => EntityAccountService::CUSTOMER, 'entity_id' => $customerId])->get()->getRow();

        return $row === null ? '' : (string) $row->name;
    }

    /**
     * The customers whose account holds something, by what it holds. A balance is what the
     * customer owes, or minus what they hold in credit.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function customersWithBalance(string $search = '', string $sort = 'balance', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $accounts = $this->db->prefixTable('accounting_accounts');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $journals = $this->db->prefixTable('accounting_journals');
        $bindings = [JournalStatus::Posted->value, JournalStatus::Reversed->value, EntityAccountService::CUSTOMER];
        $filter   = '';

        if ($search !== '') {
            $filter     = ' AND a.name LIKE ?';
            $bindings[] = '%' . $this->db->escapeLikeString($search) . '%';
        }

        $grouped = "SELECT a.entity_id AS customer_id, a.name AS customer_name, a.account_id,
                           SUM(l.debit) - SUM(l.credit) AS balance
                    FROM {$accounts} a
                    JOIN {$lines} l ON l.account_id = a.account_id
                    JOIN {$journals} j ON j.journal_id = l.journal_id AND j.status IN (?, ?)
                    WHERE a.entity_type = ?{$filter}
                    GROUP BY a.account_id, a.entity_id, a.name
                    HAVING balance <> 0";

        $column = $sort === 'customer_name' ? 'customer_name' : 'balance';
        $way    = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $all    = $this->db->query("{$grouped} ORDER BY {$column} {$way}, customer_id ASC", $bindings)->getResult();

        return ['total' => count($all), 'rows' => array_slice($all, max(0, $offset), max(1, $limit))];
    }

    /**
     * The sales of a customer that are in the books, with what their invoice journal debited the
     * account of the customer, the day they are dated and their due date. A sale with no date typed
     * is dated the day it was saved and falls due that same day.
     *
     * @return list<object>
     */
    private function sales(int $customerId, int $accountId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $bindings = [$accountId, SalePostingBuilder::SOURCE_SALE, JournalEntryType::Revenue->value, ...$standingBindings, $customerId];
        $where    = '';

        if ($asOf === null) {
            $where      = ' AND s.sale_status = ?';
            $bindings[] = COMPLETED;
        } else {
            $where      = ' AND COALESCE(s.invoice_date, DATE(s.sale_time)) <= ?';
            $bindings[] = $asOf;
        }

        return $this->db->query(
            'SELECT s.sale_id, s.sale_type, s.sale_time, s.return_of_sale_id, s.due_date, s.payment_terms_days, s.currency_code, s.currency_rate,
                    COALESCE(s.invoice_date, DATE(s.sale_time)) AS doc_date,
                    (SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l WHERE l.journal_id = j.journal_id AND l.account_id = ?) AS total
             FROM ' . $this->db->prefixTable('sales') . ' s
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = s.sale_id AND j.entry_type = ? AND {$standing}
             WHERE s.customer_id = ? AND s.sale_type IN (" . implode(',', self::SALE_TYPES) . "){$where}
             ORDER BY doc_date ASC, s.sale_id ASC",
            $bindings,
        )->getResult();
    }

    /**
     * The opening balances of a customer that stand, with what their journal debited the account of
     * the customer: a debit is what the customer owes, a credit is what they hold with us. On a day of
     * the past it is the ones dated by then that had not been reversed by then.
     *
     * @return list<object>
     */
    private function openings(int $customerId, int $accountId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $bindings = [$accountId, CustomerOpening::SOURCE, JournalEntryType::General->value, ...$standingBindings, $customerId];

        return $this->db->query(
            'SELECT o.opening_id, o.opening_date AS doc_date,
                    (SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l WHERE l.journal_id = j.journal_id AND l.account_id = ?) AS total
             FROM ' . $this->db->prefixTable('customer_openings') . ' o
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = o.customer_id AND j.entry_type = ? AND j.revision = o.revision AND {$standing}
             WHERE o.customer_id = ?
             ORDER BY o.opening_date ASC, o.opening_id ASC",
            $bindings,
        )->getResult();
    }

    /**
     * The sales debit notes of a customer that stand, with what their journal debited the account of the
     * customer. On a day of the past it is the ones dated by then that had not been reversed by then.
     *
     * @return list<object>
     */
    private function debitNotes(int $customerId, int $accountId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $bindings = [$accountId, SalesDebitNotes::SOURCE, JournalEntryType::Revenue->value, ...$standingBindings, $customerId];

        return $this->db->query(
            'SELECT n.debit_note_id, n.note_date AS doc_date,
                    (SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l WHERE l.journal_id = j.journal_id AND l.account_id = ?) AS total
             FROM ' . $this->db->prefixTable('sales_debit_notes') . ' n
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = n.debit_note_id AND j.entry_type = ? AND {$standing}
             WHERE n.customer_id = ?
             ORDER BY n.note_date ASC, n.debit_note_id ASC",
            $bindings,
        )->getResult();
    }

    /**
     * What the payments that stand credited the customer, by sale. A refund is negative.
     *
     * @return array<int, numeric-string>
     */
    private function paid(int $customerId, int $accountId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $rows = $this->db->query(
            'SELECT p.sale_id, SUM(l.credit - l.debit) AS paid
             FROM ' . $this->db->prefixTable('sales_payments') . ' p
             JOIN ' . $this->db->prefixTable('sales') . ' s ON s.sale_id = p.sale_id AND s.customer_id = ?
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type IN (?, ?) AND j.source_id = p.payment_id AND {$standing}
             JOIN " . $this->db->prefixTable('accounting_journal_lines') . ' l ON l.journal_id = j.journal_id AND l.account_id = ?
             GROUP BY p.sale_id',
            [$customerId, SalePostingBuilder::SOURCE_PAYMENT, SalePostingBuilder::SOURCE_PAYMENT_FX, ...$standingBindings, $accountId],
        )->getResult();

        $paid = [];

        foreach ($rows as $row) {
            $paid[(int) $row->sale_id] = bcadd($row->paid, '0', SaleAmounts::SCALE);
        }

        return $paid;
    }

    /**
     * The payments of customers that belong to no invoice that stand, with what their journal credited
     * the account of the customer.
     *
     * @return list<object>
     */
    private function advances(int $customerId, int $accountId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $bindings = [$accountId, CustomerAdvances::SOURCE, JournalEntryType::General->value, ...$standingBindings, $customerId];
        $status   = '';

        if ($asOf === null) {
            $status     = ' AND a.status = ?';
            $bindings[] = 'completed';
        }

        return $this->db->query(
            'SELECT a.advance_id, a.advance_date,
                    (SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l WHERE l.journal_id = j.journal_id AND l.account_id = ?) AS amount
             FROM ' . $this->db->prefixTable('customer_advances') . ' a
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = a.advance_id AND j.entry_type = ? AND {$standing}
             WHERE a.customer_id = ?{$status}",
            $bindings,
        )->getResult();
    }

    /**
     * What the cash refunds that stand took from each credit, by source. A refund that was cancelled
     * has no journal that stands, so it takes nothing.
     *
     * @return array<string, numeric-string>
     */
    private function refunded(int $customerId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $rows = $this->db->query(
            'SELECT s.source_type, s.source_id, SUM(s.amount) AS refunded
             FROM ' . $this->db->prefixTable('customer_credit_refund_sources') . ' s
             JOIN ' . $this->db->prefixTable('customer_credit_refunds') . ' r ON r.refund_id = s.refund_id AND r.customer_id = ?
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = r.refund_id AND j.entry_type = ? AND {$standing}
             GROUP BY s.source_type, s.source_id",
            [$customerId, CreditRefunds::CUSTOMER_SOURCE, JournalEntryType::General->value, ...$standingBindings],
        )->getResult();

        $refunded = [];

        foreach ($rows as $row) {
            $refunded[$row->source_type . ':' . $row->source_id] = bcadd((string) $row->refunded, '0', SaleAmounts::SCALE);
        }

        return $refunded;
    }

    /**
     * The credits that stand paid back in cash, by source, now.
     *
     * @return array<string, numeric-string>
     */
    public function refundedSources(int $customerId): array
    {
        return $this->refunded($customerId, null);
    }

    /**
     * What the applications of credit stand for, by invoice and by source. On a day of the past it is
     * the ones made by then and not yet released by then.
     *
     * @return array{into: array<int, numeric-string>, from: array<string, numeric-string>}
     */
    private function applied(int $customerId, ?string $asOf): array
    {
        $into = [];
        $from = [];

        foreach ($this->applications($customerId, $asOf !== null) as $application) {
            if ($asOf !== null && (substr((string) $application->created_at, 0, 10) > $asOf || ($application->released_at !== null && substr((string) $application->released_at, 0, 10) <= $asOf))) {
                continue;
            }

            $invoice = self::targetOf($application);
            $source  = $application->source_type . ':' . $application->source_id;

            $into[$invoice] = bcadd($into[$invoice] ?? '0', $application->amount, SaleAmounts::SCALE);
            $from[$source]  = bcadd($from[$source] ?? '0', $application->amount, SaleAmounts::SCALE);
        }

        return ['into' => $into, 'from' => $from];
    }

    /**
     * Debit less credit of the account of the customer over everything posted, reversals
     * included, which is how every balance in the books is read; up to a day when one is given.
     *
     * @return numeric-string
     */
    private function accountBalance(int $accountId, ?string $asOf): string
    {
        $bindings = [JournalStatus::Posted->value, JournalStatus::Reversed->value, $accountId];
        $until    = '';

        if ($asOf !== null) {
            $until      = ' AND j.journal_date <= ?';
            $bindings[] = $asOf;
        }

        $row = $this->db->query(
            'SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance
             FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.journal_id = l.journal_id
             WHERE j.status IN (?, ?) AND l.account_id = ?{$until}",
            $bindings,
        )->getRow();

        return bcadd((string) $row->balance, '0', SaleAmounts::SCALE);
    }
}
