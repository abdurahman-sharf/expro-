<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\StandingJournals;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The payments of customers whose journals stand, in the branches and days of a report. A payment counts
 * only when it was completed and posted, so one under review or failed is not there, and neither is the
 * credit applied to an invoice, which moves no money, nor the rounding of a sale to the cash difference
 * account. What a payment came to is what its journal put on the account of the customer; the money side
 * of the same journal is read beside it, and the account it went into is the treasury of the row.
 */
final class PaymentRows
{
    private BaseConnection $db;
    private ReportDocuments $documents;

    public function __construct(?BaseConnection $db = null, ?ReportDocuments $documents = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->documents = $documents ?? new ReportDocuments($this->db);
    }

    /**
     * @return list<PaymentRow> Oldest first.
     */
    public function read(ReportScope $scope, ?string $from, string $to, ?int $customerId = null, ?int $userId = null, ?int $collectorId = null, string $methodKey = '', ?int $moneyAccountId = null): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', null);
        $lines                         = $this->db->prefixTable('accounting_journal_lines');
        $accounts                      = $this->db->prefixTable('accounting_accounts');
        $receivable                    = "(a.entity_type = '" . EntityAccountService::CUSTOMER . "' OR a.account_id = ?)";

        $where    = ['p.cash_adjustment = 0', 'j.journal_date <= ?', 'j.branch_id IN (' . implode(',', array_map('intval', $scope->ids)) . ')'];
        $bindings = [$to];

        if ($from !== null) {
            $where[]    = 'j.journal_date >= ?';
            $bindings[] = $from;
        }

        foreach ([['s.customer_id', $customerId], ['s.employee_id', $userId], ['p.employee_id', $collectorId]] as [$column, $value]) {
            if ($value !== null) {
                $where[]    = "{$column} = ?";
                $bindings[] = $value;
            }
        }

        $counter = $this->documents->counterAccountId();
        $rows    = $this->db->query(
            "SELECT p.payment_id, p.sale_id, p.payment_type, p.reference_code, p.employee_id AS collector_id, j.journal_date, j.branch_id, b.code AS branch_code,
                    s.customer_id, s.employee_id AS user_id, s.invoice_number, s.sale_type, s.return_document,
                    (SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM {$lines} l JOIN {$accounts} a ON a.account_id = l.account_id WHERE l.journal_id = j.journal_id AND {$receivable}) AS amount,
                    (SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM {$lines} l JOIN {$accounts} a ON a.account_id = l.account_id WHERE l.journal_id = j.journal_id AND NOT {$receivable}) AS money,
                    (SELECT l.account_id FROM {$lines} l JOIN {$accounts} a ON a.account_id = l.account_id WHERE l.journal_id = j.journal_id AND NOT {$receivable} ORDER BY ABS(l.debit - l.credit) DESC, l.line_no ASC LIMIT 1) AS money_account_id
             FROM " . $this->db->prefixTable('sales_payments') . ' p
             JOIN ' . $this->db->prefixTable('sales') . ' s ON s.sale_id = p.sale_id
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = p.payment_id AND {$standing}
             JOIN " . $this->db->prefixTable('branches') . ' b ON b.branch_id = j.branch_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY j.journal_date ASC, p.payment_id ASC',
            [$counter, $counter, $counter, SalePostingBuilder::SOURCE_PAYMENT, ...$standingBindings, ...$bindings],
        )->getResult();

        $names     = $this->names($rows);
        $money     = $this->moneyNames($rows);
        $saleIds   = array_values(array_unique(array_map(static fn (object $row): int => (int) $row->sale_id, $rows)));
        $numbering = new DocumentNumbering($this->db);
        $credits   = $numbering->numbers(DocumentType::CreditNote, 'sales', $saleIds);
        $refunds   = $numbering->numbers(DocumentType::RefundInvoice, 'sales', $saleIds);
        $payments  = [];

        foreach ($rows as $row) {
            $amount = bcadd((string) $row->amount, '0', SaleAmounts::SCALE);

            if (Amounts::isZero($amount) || $row->money_account_id === null) {
                continue;
            }

            $type    = PaymentType::fromLabel((string) $row->payment_type);
            $method  = $type?->value ?? (string) $row->payment_type;
            $account = (int) $row->money_account_id;
            $saleId  = (int) $row->sale_id;

            if ($methodKey !== '' && $method !== $methodKey) {
                continue;
            }

            if ($moneyAccountId !== null && $account !== $moneyAccountId) {
                continue;
            }

            $payments[] = new PaymentRow(
                paymentId: (int) $row->payment_id,
                saleId: $saleId,
                date: (string) $row->journal_date,
                branchId: (int) $row->branch_id,
                branchCode: (string) $row->branch_code,
                customerId: $row->customer_id === null ? null : (int) $row->customer_id,
                customerName: $row->customer_id === null ? '' : ($names[(int) $row->customer_id] ?? ''),
                invoiceNumber: match (true) {
                    (int) $row->sale_type !== SALE_TYPE_RETURN                     => trim((string) $row->invoice_number) !== '' ? (string) $row->invoice_number : 'POS ' . $saleId,
                    $row->return_document === ReturnDocument::RefundInvoice->value => $refunds[$saleId] ?? 'POS ' . $saleId,
                    default                                                        => $credits[$saleId] ?? 'POS ' . $saleId,
                },
                userId: $row->user_id === null ? null : (int) $row->user_id,
                userName: $row->user_id === null ? '' : ($names[(int) $row->user_id] ?? ''),
                collectorId: $row->collector_id === null ? null : (int) $row->collector_id,
                collectorName: $row->collector_id === null ? '' : ($names[(int) $row->collector_id] ?? ''),
                methodKey: $method,
                method: $type === null ? (string) $row->payment_type : lang($type->languageKey()),
                reference: (string) ($row->reference_code ?? ''),
                moneyAccountId: $account,
                moneyAccount: $money[$account] ?? '',
                amount: $amount,
                ledgerMoney: bcadd((string) $row->money, '0', SaleAmounts::SCALE),
            );
        }

        return $payments;
    }

    /**
     * @param list<object> $rows
     *
     * @return array<int, string>
     */
    private function names(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            foreach ([$row->customer_id, $row->user_id, $row->collector_id] as $id) {
                if ($id !== null) {
                    $ids[(int) $id] = (int) $id;
                }
            }
        }

        $names = [];

        foreach (array_chunk(array_values($ids), 400) as $chunk) {
            foreach ($this->db->table('people')->select("person_id, TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, ''))) AS name", false)->whereIn('person_id', $chunk)->get()->getResult() as $person) {
                $names[(int) $person->person_id] = (string) $person->name;
            }
        }

        return $names;
    }

    /**
     * @param list<object> $rows
     *
     * @return array<int, string>
     */
    private function moneyNames(array $rows): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (object $row): int => (int) $row->money_account_id, $rows))));

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, name')->whereIn('account_id', $ids)->get()->getResult() as $account) {
            $names[(int) $account->account_id] = (string) $account->name;
        }

        return $names;
    }
}
