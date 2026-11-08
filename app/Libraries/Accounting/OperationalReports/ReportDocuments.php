<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\AccountType;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\InvoiceFigures;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Accounting\StandingJournals;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The sales documents that stand in the books for the branches and the days of a report: the invoices,
 * the credit notes and the refund invoices, and the debit notes when they are asked for. A document is
 * in the report when its journal stands and the journal falls in the days, so a document that was cancelled
 * is not there and one that was changed is there as it now stands. What each came to is worked out from
 * the sale as posting worked it out, and beside it what its journal holds is read from the journal alone.
 * What has been paid on an invoice and what has been taken back from it are as of today, whatever the
 * days of the report.
 */
final class ReportDocuments
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<DocumentKind> $kinds Empty for the documents that make up the sales.
     *
     * @return list<ReportDocument> Oldest first.
     */
    public function read(ReportScope $scope, ?string $from, string $to, array $kinds = [], ?int $customerId = null, ?int $employeeId = null, bool $withLines = false): array
    {
        $kinds = $kinds === [] ? DocumentKind::sales() : $kinds;

        if ($scope->isEmpty()) {
            return [];
        }

        $saleKinds = array_values(array_filter($kinds, static fn (DocumentKind $kind): bool => $kind !== DocumentKind::DebitNote));
        $documents = $saleKinds === [] ? [] : $this->sales($scope, $from, $to, $saleKinds, $customerId, $employeeId, $withLines);

        if (in_array(DocumentKind::DebitNote, $kinds, true)) {
            $documents = [...$documents, ...$this->debitNotes($scope, $from, $to, $customerId, $employeeId)];
        }

        usort($documents, static fn (ReportDocument $a, ReportDocument $b): int => [$a->date, $a->id, $a->kind->value] <=> [$b->date, $b->id, $b->kind->value]);

        return $documents;
    }

    /**
     * The documents of the sales given, whatever their days, for the reports that find their sales
     * by something other than the day of the document.
     *
     * @param list<int> $saleIds
     *
     * @return array<int, ReportDocument> By sale.
     */
    public function byIds(ReportScope $scope, array $saleIds, bool $withLines = false): array
    {
        if ($scope->isEmpty() || $saleIds === []) {
            return [];
        }

        $documents = [];

        foreach (array_chunk(array_values(array_unique($saleIds)), 400) as $chunk) {
            foreach ($this->sales($scope, null, null, DocumentKind::sales(), null, null, $withLines, $chunk) as $document) {
                $documents[$document->id] = $document;
            }
        }

        return $documents;
    }

    /**
     * The credit notes and refund invoices that stand for each of the invoices given, in the order they
     * were made, whatever their days.
     *
     * @param list<int> $invoiceIds
     *
     * @return array<int, list<ReportDocument>> By the invoice they take back.
     */
    public function returnsOf(ReportScope $scope, array $invoiceIds): array
    {
        $returns = [];

        if ($scope->isEmpty() || $invoiceIds === []) {
            return $returns;
        }

        foreach (array_chunk(array_values(array_unique($invoiceIds)), 400) as $chunk) {
            foreach ($this->sales($scope, null, null, [DocumentKind::CreditNote, DocumentKind::RefundInvoice], null, null, false, null, $chunk) as $document) {
                if ($document->originId !== null) {
                    $returns[$document->originId][] = $document;
                }
            }
        }

        return $returns;
    }

    /**
     * The credit notes and refund invoices that stand for no invoice, in the days of a report.
     *
     * @return list<ReportDocument>
     */
    public function orphanReturns(ReportScope $scope, ?string $from, string $to, ?int $customerId = null): array
    {
        return array_values(array_filter(
            $this->read($scope, $from, $to, [DocumentKind::CreditNote, DocumentKind::RefundInvoice], $customerId),
            static fn (ReportDocument $document): bool => $document->originId === null,
        ));
    }

    public function counterAccountId(): int
    {
        try {
            return (new RoutingResolver($this->db))->accountFor(RoutingKey::CounterCustomers) ?? 0;
        } catch (PostingException) {
            return 0;
        }
    }

    /**
     * @param list<DocumentKind> $kinds
     *
     * @return list<ReportDocument>
     */
    private function sales(ReportScope $scope, ?string $from, ?string $to, array $kinds, ?int $customerId, ?int $employeeId, bool $withLines, ?array $saleIds = null, ?array $originIds = null): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', null);

        $join         = ['j.source_type = ?', 'j.entry_type = ?', $standing];
        $joinBindings = [SalePostingBuilder::SOURCE_SALE, JournalEntryType::Revenue->value, ...$standingBindings];
        $where        = ['s.sale_status = ?', 'j.branch_id IN (' . implode(',', array_map('intval', $scope->ids)) . ')', $this->kindCondition($kinds)];
        $bindings     = [COMPLETED];

        if ($to !== null) {
            $where[]    = 'j.journal_date <= ?';
            $bindings[] = $to;
        }

        if ($saleIds !== null) {
            $where[] = 's.sale_id IN (' . implode(',', array_map('intval', $saleIds)) . ')';
        }

        if ($originIds !== null) {
            $where[] = 's.return_of_sale_id IN (' . implode(',', array_map('intval', $originIds)) . ')';
        }

        if ($from !== null) {
            $where[]    = 'j.journal_date >= ?';
            $bindings[] = $from;
        }

        if ($customerId !== null) {
            $where[]    = 's.customer_id = ?';
            $bindings[] = $customerId;
        }

        if ($employeeId !== null) {
            $where[]    = 's.employee_id = ?';
            $bindings[] = $employeeId;
        }

        $rows = $this->db->query(
            'SELECT s.sale_id, s.sale_type, s.return_document, s.return_of_sale_id, s.customer_id, s.employee_id, s.invoice_number, s.due_date, s.payment_terms_days,
                    j.journal_date AS doc_date, j.branch_id, b.code AS branch_code,
                    ' . $this->ledgerFigures() . '
             FROM ' . $this->db->prefixTable('sales') . ' s
             JOIN ' . $this->db->prefixTable('accounting_journals') . ' j ON j.source_id = s.sale_id AND ' . implode(' AND ', $join) . '
             JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = j.branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY j.journal_date ASC, s.sale_id ASC',
            [$this->counterAccountId(), ...$joinBindings, ...$bindings],
        )->getResult();

        return $this->documents($rows, $withLines);
    }

    /**
     * @param list<DocumentKind> $kinds
     */
    private function kindCondition(array $kinds): string
    {
        return '(' . implode(' OR ', array_map(static fn (DocumentKind $kind): string => match ($kind) {
            DocumentKind::Invoice    => 's.sale_type IN (' . SALE_TYPE_POS . ', ' . SALE_TYPE_INVOICE . ')',
            DocumentKind::CreditNote => 's.sale_type = ' . SALE_TYPE_RETURN . ' AND s.return_document IS NULL',
            default                  => 's.sale_type = ' . SALE_TYPE_RETURN . " AND s.return_document = '" . ReturnDocument::RefundInvoice->value . "'",
        }, $kinds)) . ')';
    }

    /**
     * The three figures of the journal of a document read from its lines alone, by the type of the
     * account: what it put on the account of the customer, on the accounts of tax, and on the accounts
     * of revenue and expense. The first placeholder is the counter account.
     */
    private function ledgerFigures(): string
    {
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $accounts = $this->db->prefixTable('accounting_accounts');
        $sum      = static fn (string $expression, string $condition): string => "(SELECT COALESCE(SUM({$expression}), 0) FROM {$lines} l JOIN {$accounts} a ON a.account_id = l.account_id WHERE l.journal_id = j.journal_id AND {$condition})";

        return $sum('l.debit - l.credit', "(a.entity_type = '" . EntityAccountService::CUSTOMER . "' OR a.account_id = ?)") . ' AS ledger_total, '
            . $sum('l.credit - l.debit', "a.account_type = '" . AccountType::Liability->value . "'") . ' AS ledger_tax, '
            . $sum('l.credit - l.debit', "a.account_type IN ('" . AccountType::Revenue->value . "', '" . AccountType::Expense->value . "')") . ' AS ledger_revenue';
    }

    /**
     * @param list<object> $rows
     *
     * @return list<ReportDocument>
     */
    private function documents(array $rows, bool $withLines): array
    {
        if ($rows === []) {
            return [];
        }

        $ids           = array_map(static fn (object $row): int => (int) $row->sale_id, $rows);
        $sales         = (new SaleReader($this->db))->documents($ids);
        $paid          = $this->paid($ids);
        $applied       = $this->applied($ids, 'invoice_sale_id');
        $returns       = $this->returns($ids);
        $names         = $this->names(array_values(array_filter(array_merge(array_map(static fn (object $row): ?int => $row->customer_id === null ? null : (int) $row->customer_id, $rows), array_map(static fn (object $row): ?int => $row->employee_id === null ? null : (int) $row->employee_id, $rows)))));
        $numbering     = new DocumentNumbering($this->db);
        $creditNumbers = $numbering->numbers(DocumentType::CreditNote, 'sales', $ids);
        $refundNumbers = $numbering->numbers(DocumentType::RefundInvoice, 'sales', $ids);
        $decimals      = totals_decimals();
        $documents     = [];

        foreach ($rows as $row) {
            $saleId = (int) $row->sale_id;
            $sale   = $sales[$saleId] ?? null;

            if ($sale === null) {
                continue;
            }

            $isReturn = (int) $row->sale_type === SALE_TYPE_RETURN;
            $kind     = match (true) {
                ! $isReturn                                                    => DocumentKind::Invoice,
                $row->return_document === ReturnDocument::RefundInvoice->value => DocumentKind::RefundInvoice,
                default                                                        => DocumentKind::CreditNote,
            };
            $sign    = $kind->sign();
            $figures = SaleAmounts::figures($sale, $decimals);
            $totals  = SaleAmounts::totals($sale, $decimals);
            $total   = Amounts::signed($figures['total'], $sign);
            $date    = (string) $row->doc_date;
            $number  = match ($kind) {
                DocumentKind::Invoice       => trim((string) $row->invoice_number) !== '' ? (string) $row->invoice_number : 'POS ' . $saleId,
                DocumentKind::CreditNote    => $creditNumbers[$saleId] ?? 'POS ' . $saleId,
                DocumentKind::RefundInvoice => $refundNumbers[$saleId] ?? 'POS ' . $saleId,
                default                     => (string) $saleId,
            };

            $documents[] = new ReportDocument(
                id: $saleId,
                kind: $kind,
                date: $date,
                branchId: (int) $row->branch_id,
                branchCode: (string) $row->branch_code,
                customerId: $row->customer_id === null ? null : (int) $row->customer_id,
                customerName: $row->customer_id === null ? '' : ($names[(int) $row->customer_id] ?? ''),
                employeeId: $row->employee_id === null ? null : (int) $row->employee_id,
                employeeName: $row->employee_id === null ? '' : ($names[(int) $row->employee_id] ?? ''),
                number: $number,
                value: Amounts::signed(Amounts::add($figures['revenue'], $totals['invoiceDiscount']), $sign),
                discount: Amounts::signed($totals['invoiceDiscount'], $sign),
                shipping: Amounts::signed($figures['shipping'], $sign),
                adjustment: Amounts::signed($figures['adjustment'], $sign),
                tax: Amounts::signed($figures['tax'], $sign),
                total: $total,
                ledgerTotal: bcadd((string) $row->ledger_total, '0', SaleAmounts::SCALE),
                ledgerTax: Amounts::signed((string) $row->ledger_tax, 1),
                ledgerRevenue: Amounts::signed((string) $row->ledger_revenue, 1),
                returnedByRefund: $returns[$saleId]['refund'] ?? Amounts::ZERO,
                figures: $isReturn ? null : new InvoiceFigures($saleId, $date, (int) $row->sale_type, $figures['total'], $paid[$saleId] ?? Amounts::ZERO, $applied[$saleId] ?? Amounts::ZERO, $returns[$saleId]['all'] ?? Amounts::ZERO, $row->due_date === null ? $date : (string) $row->due_date, $row->payment_terms_days === null ? null : (int) $row->payment_terms_days),
                originId: $sale->returnOfSaleId,
                headerCharges: Amounts::signed($sale->charges?->addedToTotal() ?? Amounts::ZERO, $sign),
                lines: $withLines ? $sale->lines : [],
                currencyCode: $sale->currency?->code,
            );
        }

        return $documents;
    }

    /**
     * @return list<ReportDocument>
     */
    private function debitNotes(ReportScope $scope, ?string $from, string $to, ?int $customerId, ?int $employeeId): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', null);

        $join         = ['j.source_type = ?', 'j.entry_type = ?', $standing];
        $joinBindings = [SalesDebitNotes::SOURCE, JournalEntryType::Revenue->value, ...$standingBindings];
        $where        = ['j.journal_date <= ?', 'j.branch_id IN (' . implode(',', array_map('intval', $scope->ids)) . ')'];
        $bindings     = [$to];

        if ($from !== null) {
            $where[]    = 'j.journal_date >= ?';
            $bindings[] = $from;
        }

        if ($customerId !== null) {
            $where[]    = 'n.customer_id = ?';
            $bindings[] = $customerId;
        }

        if ($employeeId !== null) {
            $where[]    = 'n.created_by = ?';
            $bindings[] = $employeeId;
        }

        $rows = $this->db->query(
            'SELECT n.debit_note_id, n.customer_id, n.created_by, n.amount, n.tax_amount, n.total, j.journal_date AS doc_date, j.branch_id, b.code AS branch_code,
                    ' . $this->ledgerFigures() . '
             FROM ' . $this->db->prefixTable('sales_debit_notes') . ' n
             JOIN ' . $this->db->prefixTable('accounting_journals') . ' j ON j.source_id = n.debit_note_id AND ' . implode(' AND ', $join) . '
             JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = j.branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY j.journal_date ASC, n.debit_note_id ASC',
            [$this->counterAccountId(), ...$joinBindings, ...$bindings],
        )->getResult();

        if ($rows === []) {
            return [];
        }

        $ids       = array_map(static fn (object $row): int => (int) $row->debit_note_id, $rows);
        $applied   = $this->applied($ids, 'debit_note_id');
        $names     = $this->names(array_values(array_filter(array_merge(array_map(static fn (object $row): ?int => $row->customer_id === null ? null : (int) $row->customer_id, $rows), array_map(static fn (object $row): ?int => $row->created_by === null ? null : (int) $row->created_by, $rows)))));
        $numbers   = (new DocumentNumbering($this->db))->numbers(DocumentType::SalesDebitNote, 'sales_debit_notes', $ids);
        $documents = [];

        foreach ($rows as $row) {
            $id    = (int) $row->debit_note_id;
            $date  = (string) $row->doc_date;
            $total = bcadd((string) $row->total, '0', SaleAmounts::SCALE);
            $key   = SalesDebitNotes::keyOf($id);

            $documents[] = new ReportDocument(
                id: $id,
                kind: DocumentKind::DebitNote,
                date: $date,
                branchId: (int) $row->branch_id,
                branchCode: (string) $row->branch_code,
                customerId: $row->customer_id === null ? null : (int) $row->customer_id,
                customerName: $row->customer_id === null ? '' : ($names[(int) $row->customer_id] ?? ''),
                employeeId: $row->created_by === null ? null : (int) $row->created_by,
                employeeName: $row->created_by === null ? '' : ($names[(int) $row->created_by] ?? ''),
                number: $numbers[$id] ?? DocumentType::SalesDebitNote->fallback($id),
                value: bcadd((string) $row->amount, '0', SaleAmounts::SCALE),
                discount: Amounts::ZERO,
                shipping: Amounts::ZERO,
                adjustment: Amounts::ZERO,
                tax: bcadd((string) $row->tax_amount, '0', SaleAmounts::SCALE),
                total: $total,
                ledgerTotal: bcadd((string) $row->ledger_total, '0', SaleAmounts::SCALE),
                ledgerTax: Amounts::signed((string) $row->ledger_tax, 1),
                ledgerRevenue: Amounts::signed((string) $row->ledger_revenue, 1),
                figures: new InvoiceFigures($key, $date, SALE_TYPE_INVOICE, $total, Amounts::ZERO, $applied[$id] ?? Amounts::ZERO, Amounts::ZERO, $date, null, false, true),
            );
        }

        return $documents;
    }

    /**
     * The payments that stand on each sale, one per payment, as what its journal credited the account of the
     * customer or the counter account, oldest first. A payment with no journal that stands, or none on those
     * accounts, as a sale on credit is, is not one.
     *
     * @param list<int> $saleIds
     *
     * @return array<int, list<array{id: int, date: string, amount: numeric-string}>> By sale.
     */
    public function paymentsOf(array $saleIds): array
    {
        $payments                      = [];
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', null);

        foreach (array_chunk(array_values(array_unique($saleIds)), 400) as $chunk) {
            $rows = $this->db->query(
                'SELECT p.sale_id, p.payment_id, p.payment_time, SUM(l.credit - l.debit) AS paid
                 FROM ' . $this->db->prefixTable('sales_payments') . ' p
                 JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = p.payment_id AND {$standing}
                 JOIN " . $this->db->prefixTable('accounting_journal_lines') . ' l ON l.journal_id = j.journal_id
                 JOIN ' . $this->db->prefixTable('accounting_accounts') . ' a ON a.account_id = l.account_id AND (a.entity_type = ? OR a.account_id = ?)
                 WHERE p.sale_id IN (' . implode(',', array_map('intval', $chunk)) . ')
                 GROUP BY p.sale_id, p.payment_id, p.payment_time
                 ORDER BY p.payment_id ASC',
                [SalePostingBuilder::SOURCE_PAYMENT, ...$standingBindings, EntityAccountService::CUSTOMER, $this->counterAccountId()],
            )->getResult();

            foreach ($rows as $row) {
                $payments[(int) $row->sale_id][] = ['id' => (int) $row->payment_id, 'date' => substr((string) $row->payment_time, 0, 10), 'amount' => bcadd((string) $row->paid, '0', SaleAmounts::SCALE)];
            }
        }

        return $payments;
    }

    /**
     * What the payments that stand credited the account of the customer or the counter account, by sale.
     *
     * @param list<int> $saleIds
     *
     * @return array<int, numeric-string>
     */
    private function paid(array $saleIds): array
    {
        $paid = [];

        foreach ($this->paymentsOf($saleIds) as $saleId => $payments) {
            $paid[$saleId] = array_reduce($payments, static fn (string $sum, array $payment): string => Amounts::add($sum, $payment['amount']), Amounts::ZERO);
        }

        return $paid;
    }

    /**
     * The credit spent on invoices or on debit notes that has not been released, by document.
     *
     * @param list<int> $ids
     *
     * @return array<int, numeric-string>
     */
    private function applied(array $ids, string $column): array
    {
        $applied = [];

        foreach (array_chunk($ids, 400) as $chunk) {
            $rows = $this->db->table('customer_credit_applications')
                ->select("{$column} AS document_id, SUM(amount) AS amount", false)
                ->whereIn($column, $chunk)
                ->where('released_at', null)
                ->groupBy($column)
                ->get()
                ->getResult();

            foreach ($rows as $row) {
                $applied[(int) $row->document_id] = bcadd((string) $row->amount, '0', SaleAmounts::SCALE);
            }
        }

        return $applied;
    }

    /**
     * What the standing returns of each invoice took back, all of them and the refund invoices apart, as
     * positive amounts.
     *
     * @param list<int> $saleIds
     *
     * @return array<int, array{all: numeric-string, refund: numeric-string}>
     */
    private function returns(array $saleIds): array
    {
        $returns                       = [];
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', null);
        $journalLines                  = $this->db->prefixTable('accounting_journal_lines');
        $accounts                      = $this->db->prefixTable('accounting_accounts');

        foreach (array_chunk($saleIds, 400) as $chunk) {
            $rows = $this->db->query(
                "SELECT r.return_of_sale_id AS origin_id, r.return_document,
                        SUM((SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM {$journalLines} l JOIN {$accounts} a ON a.account_id = l.account_id WHERE l.journal_id = j.journal_id AND (a.entity_type = ? OR a.account_id = ?))) AS taken
                 FROM " . $this->db->prefixTable('sales') . ' r
                 JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = r.sale_id AND j.entry_type = ? AND {$standing}
                 WHERE r.sale_type = ? AND r.sale_status = ? AND r.return_of_sale_id IN (" . implode(',', array_map('intval', $chunk)) . ')
                 GROUP BY r.return_of_sale_id, r.return_document',
                [EntityAccountService::CUSTOMER, $this->counterAccountId(), SalePostingBuilder::SOURCE_SALE, JournalEntryType::Revenue->value, ...$standingBindings, SALE_TYPE_RETURN, COMPLETED],
            )->getResult();

            foreach ($rows as $row) {
                $origin = (int) $row->origin_id;
                $taken  = bcadd((string) $row->taken, '0', SaleAmounts::SCALE);

                $returns[$origin]['all']    = Amounts::add($returns[$origin]['all'] ?? Amounts::ZERO, $taken);
                $returns[$origin]['refund'] = Amounts::add($returns[$origin]['refund'] ?? Amounts::ZERO, $row->return_document === ReturnDocument::RefundInvoice->value ? $taken : Amounts::ZERO);
            }
        }

        return $returns;
    }

    /**
     * @param list<int> $personIds
     *
     * @return array<int, string>
     */
    private function names(array $personIds): array
    {
        $names = [];

        foreach (array_chunk(array_values(array_unique($personIds)), 400) as $chunk) {
            foreach ($this->db->table('people')->select("person_id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name", false)->whereIn('person_id', $chunk)->get()->getResult() as $person) {
                $names[(int) $person->person_id] = (string) $person->name;
            }
        }

        return $names;
    }
}
