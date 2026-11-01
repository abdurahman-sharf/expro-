<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The documents of the customers as lists: the payments they made and the returns that were
 * issued to them as credit notes or refund invoices. Nothing is written. A payment shows the
 * amount its row carries next to the amount its journal put in the books, so a list that does not
 * tie to the books shows it; a return shows what it came to, what has been settled of it and what
 * is still open, read from the same ledger as the balances of the customers.
 */
final class CustomerDocuments
{
    private const PAYMENT_SORTS = [
        'number'        => 'p.payment_id',
        'payment_time'  => 'p.payment_time',
        'customer_name' => 'customer_name',
        'amount'        => 'amount',
        'branch'        => 'b.code',
    ];
    private const RETURN_SORTS = [
        'number'        => 's.sale_id',
        'sale_time'     => 's.sale_time',
        'customer_name' => 'customer_name',
        'branch'        => 'b.code',
    ];

    private BaseConnection $db;
    private CustomerLedger $ledger;

    /**
     * @var array<int, CustomerPosition>
     */
    private array $positions = [];

    public function __construct(?BaseConnection $db = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->ledger = new CustomerLedger($this->db);
    }

    /**
     * The payments of completed sales, newest first unless sorted otherwise. A payment that only
     * records what stays unpaid (a credit sale) or the rounding of cash is not a document and is
     * left out. A branch list of null means every branch.
     *
     * @param array{date_from?: string, date_to?: string, customer_id?: int, customer?: string, way?: string, employee_id?: int, branch_id?: int, status?: string, search?: string} $filters
     * @param list<int>|null                                                                                                                                                        $branchIds
     *
     * @return array{total: int, amount: numeric-string, posted: numeric-string, rows: list<object>}
     */
    public function payments(array $filters, string $sort, string $order, int $limit, int $offset, ?array $branchIds): array
    {
        [$where, $bindings] = $this->paymentConditions($filters, $branchIds);

        $payments   = $this->db->prefixTable('sales_payments');
        $sales      = $this->db->prefixTable('sales');
        $branches   = $this->db->prefixTable('branches');
        $people     = $this->db->prefixTable('people');
        $treasuries = $this->db->prefixTable('treasuries');
        $numbers    = $this->db->prefixTable('document_numbers');
        $journals   = $this->db->prefixTable('accounting_journals');
        $joins      = "FROM {$payments} p
                       JOIN {$sales} s ON s.sale_id = p.sale_id
                       JOIN {$branches} b ON b.branch_id = s.branch_id
                       LEFT JOIN {$people} cp ON cp.person_id = s.customer_id
                       LEFT JOIN {$people} ep ON ep.person_id = p.employee_id
                       LEFT JOIN {$treasuries} t ON t.treasury_id = p.treasury_id
                       LEFT JOIN {$numbers} n ON n.doc_type = ? AND n.source_table = 'sales_payments' AND n.source_id = p.payment_id";
        $posted   = "COALESCE((SELECT SUM(j.total_debit) FROM {$journals} j WHERE j.source_type = ? AND j.source_id = p.payment_id AND j.status = ?), 0)";
        $joinBind = [DocumentType::CustomerPayment->value];

        $totals = $this->db->query(
            "SELECT COUNT(*) AS found, COALESCE(SUM(p.payment_amount - p.cash_refund), 0) AS amount, COALESCE(SUM(SIGN(p.payment_amount - p.cash_refund) * {$posted}), 0) AS posted
             {$joins} WHERE {$where}",
            [SalePostingBuilder::SOURCE_PAYMENT, JournalStatus::Posted->value, ...$joinBind, ...$bindings],
        )->getRow();

        $column = self::PAYMENT_SORTS[$sort] ?? 'p.payment_time';
        $way    = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $rows   = $this->db->query(
            "SELECT p.payment_id, p.payment_time, p.payment_type, p.payment_amount, p.cash_refund, p.payment_status, p.reference_code, p.employee_id, p.treasury_id,
                    s.sale_id, s.sale_type, s.customer_id, s.branch_id, s.invoice_number,
                    TRIM(CONCAT(COALESCE(cp.first_name, ''), ' ', COALESCE(cp.last_name, ''))) AS customer_name,
                    TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name,
                    t.name AS treasury_name, b.code AS branch_code, b.name AS branch_name, n.number AS document_number,
                    (p.payment_amount - p.cash_refund) AS amount, SIGN(p.payment_amount - p.cash_refund) * {$posted} AS posted
             {$joins} WHERE {$where}
             ORDER BY {$column} {$way}, p.payment_id {$way} LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [SalePostingBuilder::SOURCE_PAYMENT, JournalStatus::Posted->value, ...$joinBind, ...$bindings],
        )->getResult();

        return [
            'total'  => (int) $totals->found,
            'amount' => bcadd((string) $totals->amount, '0', SaleAmounts::SCALE),
            'posted' => bcadd((string) $totals->posted, '0', SaleAmounts::SCALE),
            'rows'   => $rows,
        ];
    }

    /**
     * The people who collected payments, for the filter of the list.
     *
     * @param list<int>|null $branchIds
     *
     * @return list<object>
     */
    public function collectors(?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        $scope    = $branchIds === null ? '' : ' AND s.branch_id IN (' . implode(',', array_map('intval', $branchIds)) . ')';
        $payments = $this->db->prefixTable('sales_payments');
        $sales    = $this->db->prefixTable('sales');
        $people   = $this->db->prefixTable('people');

        return $this->db->query(
            "SELECT DISTINCT p.employee_id, TRIM(CONCAT(ep.first_name, ' ', ep.last_name)) AS employee_name
             FROM {$payments} p JOIN {$sales} s ON s.sale_id = p.sale_id JOIN {$people} ep ON ep.person_id = p.employee_id
             WHERE s.sale_status = ?{$scope} ORDER BY employee_name ASC, p.employee_id ASC",
            [COMPLETED],
        )->getResult();
    }

    /**
     * The returns that were issued, as credit notes and as refund invoices, with what they came
     * to and how much of it has been settled. The settlement is the one the ledger of the customer
     * keeps, so a return the books do not hold has an amount worked out from its lines and no
     * settlement to show.
     *
     * @param array{date_from?: string, date_to?: string, customer_id?: int, customer?: string, document?: string, branch_id?: int, search?: string} $filters
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function returns(array $filters, string $sort, string $order, int $limit, int $offset): array
    {
        [$where, $bindings] = $this->returnConditions($filters);

        $sales    = $this->db->prefixTable('sales');
        $branches = $this->db->prefixTable('branches');
        $people   = $this->db->prefixTable('people');
        $numbers  = $this->db->prefixTable('document_numbers');
        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $accounts = $this->db->prefixTable('accounting_accounts');
        $joins    = "FROM {$sales} s
                     JOIN {$branches} b ON b.branch_id = s.branch_id
                     LEFT JOIN {$people} cp ON cp.person_id = s.customer_id
                     LEFT JOIN {$sales} o ON o.sale_id = s.return_of_sale_id
                     LEFT JOIN {$numbers} n ON n.source_table = 'sales' AND n.source_id = s.sale_id AND n.doc_type = IF(s.return_document = ?, ?, ?)";
        $joinBind = [ReturnDocument::RefundInvoice->value, ReturnDocument::RefundInvoice->value, ReturnDocument::CreditNote->value];

        $total = (int) $this->db->query("SELECT COUNT(*) AS found {$joins} WHERE {$where}", [...$joinBind, ...$bindings])->getRow()->found;

        $column = self::RETURN_SORTS[$sort] ?? 's.sale_time';
        $way    = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $found  = $this->db->query(
            "SELECT s.sale_id, s.sale_time, s.customer_id, s.branch_id, s.return_of_sale_id, s.return_document, o.invoice_number AS origin_invoice_number, n.number AS document_number,
                    TRIM(CONCAT(COALESCE(cp.first_name, ''), ' ', COALESCE(cp.last_name, ''))) AS customer_name, b.code AS branch_code, b.name AS branch_name,
                    (SELECT COUNT(*) FROM {$journals} j WHERE j.source_type = ? AND j.source_id = s.sale_id AND j.entry_type = ? AND j.status = ?) AS in_books,
                    (SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM {$journals} j JOIN {$lines} l ON l.journal_id = j.journal_id JOIN {$accounts} a ON a.account_id = l.account_id AND a.entity_type = ? AND a.entity_id = s.customer_id
                      WHERE j.source_type = ? AND j.source_id = s.sale_id AND j.entry_type = ? AND j.status = ?) AS booked
             {$joins} WHERE {$where}
             ORDER BY {$column} {$way}, s.sale_id {$way} LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset),
            [
                SalePostingBuilder::SOURCE_SALE, JournalEntryType::Revenue->value, JournalStatus::Posted->value,
                EntityAccountService::CUSTOMER, SalePostingBuilder::SOURCE_SALE, JournalEntryType::Revenue->value, JournalStatus::Posted->value,
                ...$joinBind,
                ...$bindings,
            ],
        )->getResult();

        return ['total' => $total, 'rows' => array_map($this->returnRow(...), $found)];
    }

    /**
     * The returns of a customer that were issued as refund invoices, for the screens that name a
     * return by its document.
     *
     * @return list<int>
     */
    public function refundInvoiceIds(int $customerId): array
    {
        $rows = $this->db->table('sales')
            ->select('sale_id')
            ->where(['customer_id' => $customerId, 'sale_type' => SALE_TYPE_RETURN, 'return_document' => ReturnDocument::RefundInvoice->value])
            ->orderBy('sale_id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map('intval', array_column($rows, 'sale_id'));
    }

    /**
     * What each customer was returned, split the way the reference system splits it: refund
     * invoices are returns, credit notes are adjustments. Only returns the books hold count.
     *
     * @param list<int> $customerIds
     *
     * @return array<int, array{returns: numeric-string, adjustments: numeric-string}>
     */
    public function returnTotals(array $customerIds): array
    {
        $customerIds = array_values(array_unique(array_filter(array_map('intval', $customerIds), static fn (int $id): bool => $id > 0)));
        $totals      = array_fill_keys($customerIds, ['returns' => '0.0000', 'adjustments' => '0.0000']);

        if ($customerIds === []) {
            return $totals;
        }

        $sales    = $this->db->prefixTable('sales');
        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $accounts = $this->db->prefixTable('accounting_accounts');
        $holes    = implode(',', array_fill(0, count($customerIds), '?'));
        $rows     = $this->db->query(
            "SELECT s.customer_id, IF(s.return_document = ?, 'returns', 'adjustments') AS bucket, SUM(l.credit - l.debit) AS total
             FROM {$sales} s
             JOIN {$journals} j ON j.source_type = ? AND j.source_id = s.sale_id AND j.entry_type = ? AND j.status = ?
             JOIN {$lines} l ON l.journal_id = j.journal_id
             JOIN {$accounts} a ON a.account_id = l.account_id AND a.entity_type = ? AND a.entity_id = s.customer_id
             WHERE s.sale_type = ? AND s.sale_status = ? AND s.customer_id IN ({$holes})
             GROUP BY s.customer_id, bucket",
            [ReturnDocument::RefundInvoice->value, SalePostingBuilder::SOURCE_SALE, JournalEntryType::Revenue->value, JournalStatus::Posted->value, EntityAccountService::CUSTOMER, SALE_TYPE_RETURN, COMPLETED, ...$customerIds],
        )->getResult();

        foreach ($rows as $row) {
            $totals[(int) $row->customer_id][(string) $row->bucket] = bcadd((string) $row->total, '0', SaleAmounts::SCALE);
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    private function returnRow(object $row): array
    {
        $saleId     = (int) $row->sale_id;
        $customerId = $row->customer_id === null ? null : (int) $row->customer_id;
        $inBooks    = (int) $row->in_books > 0 && $customerId !== null;
        $amount     = $inBooks ? bcadd((string) $row->booked, '0', SaleAmounts::SCALE) : $this->amountOfLines($saleId);
        $settled    = null;
        $open       = null;

        if ($inBooks) {
            $source = $this->creditOf($customerId, $saleId);

            if ($source !== null) {
                $open    = $source->available();
                $settled = bcsub($amount, $open, SaleAmounts::SCALE);
            }
        }

        return [
            'sale_id'         => $saleId,
            'sale_time'       => (string) $row->sale_time,
            'customer_id'     => $customerId,
            'customer_name'   => (string) $row->customer_name,
            'branch_id'       => (int) $row->branch_id,
            'branch_code'     => (string) $row->branch_code,
            'branch_name'     => (string) $row->branch_name,
            'origin_id'       => $row->return_of_sale_id === null ? null : (int) $row->return_of_sale_id,
            'origin_number'   => (string) ($row->origin_invoice_number ?? ''),
            'document'        => ReturnDocument::fromStored($row->return_document),
            'document_number' => $row->document_number === null ? null : (string) $row->document_number,
            'in_books'        => $inBooks,
            'amount'          => $amount,
            'settled'         => $settled,
            'open'            => $open,
        ];
    }

    private function creditOf(int $customerId, int $saleId): ?CreditSource
    {
        $this->positions[$customerId] ??= $this->ledger->position($customerId);

        foreach ($this->positions[$customerId]->credits as $source) {
            if ($source->type === CreditSource::CREDIT_NOTE && $source->sourceId === $saleId) {
                return $source;
            }
        }

        return null;
    }

    /**
     * What a return came to from its saved lines, for one the books do not hold.
     *
     * @return numeric-string
     */
    private function amountOfLines(int $saleId): string
    {
        $sale = (new SaleLoader($this->db))->load($saleId, false);

        if ($sale === null) {
            return '0.0000';
        }

        return SaleAmounts::figures($sale, totals_decimals())['total'];
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int>|null       $branchIds
     *
     * @return array{string, list<int|string>}
     */
    private function paymentConditions(array $filters, ?array $branchIds): array
    {
        $where    = ['s.sale_status = ?', 'p.cash_adjustment = 0'];
        $bindings = [COMPLETED];
        $dues     = PaymentType::Due->labels();

        if ($dues !== []) {
            $where[]  = 'p.payment_type NOT IN (' . implode(',', array_fill(0, count($dues), '?')) . ')';
            $bindings = [...$bindings, ...$dues];
        }

        if ($branchIds !== null) {
            if ($branchIds === []) {
                $where[] = '1 = 0';
            } else {
                $where[]  = 's.branch_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
                $bindings = [...$bindings, ...array_map('intval', $branchIds)];
            }
        }

        if ($this->isDate($filters['date_from'] ?? null)) {
            $where[]    = 'p.payment_time >= ?';
            $bindings[] = $filters['date_from'] . ' 00:00:00';
        }

        if ($this->isDate($filters['date_to'] ?? null)) {
            $where[]    = 'p.payment_time <= ?';
            $bindings[] = $filters['date_to'] . ' 23:59:59';
        }

        $this->customerCondition($filters, $where, $bindings);

        if ((int) ($filters['employee_id'] ?? 0) > 0) {
            $where[]    = 'p.employee_id = ?';
            $bindings[] = (int) $filters['employee_id'];
        }

        if ((int) ($filters['branch_id'] ?? 0) > 0) {
            $where[]    = 's.branch_id = ?';
            $bindings[] = (int) $filters['branch_id'];
        }

        $status = (string) ($filters['status'] ?? '');

        if (PaymentStatus::tryFrom($status) !== null) {
            $where[]    = 'p.payment_status = ?';
            $bindings[] = $status;
        }

        $type = PaymentType::tryFrom((string) ($filters['way'] ?? ''));

        if ($type !== null) {
            $alternatives = [];

            foreach ($type->labels() as $label) {
                $alternatives[] = 'p.payment_type = ?';
                $alternatives[] = 'p.payment_type LIKE ?';
                $bindings[]     = $label;
                $bindings[]     = $this->likeText($label) . ':%';
            }

            $where[] = $alternatives === [] ? '1 = 0' : '(' . implode(' OR ', $alternatives) . ')';
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like     = '%' . $this->likeText($search) . '%';
            $where[]  = "(n.number LIKE ? OR CAST(p.payment_id AS CHAR) = ? OR cp.first_name LIKE ? OR cp.last_name LIKE ? OR CONCAT(cp.first_name, ' ', cp.last_name) LIKE ? OR p.reference_code LIKE ? OR s.invoice_number LIKE ? OR CAST(s.sale_id AS CHAR) = ?)";
            $bindings = [...$bindings, $like, $search, $like, $like, $like, $like, $like, ltrim($search, '#')];
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{string, list<int|string>}
     */
    private function returnConditions(array $filters): array
    {
        $where    = ['s.sale_type = ?', 's.sale_status = ?'];
        $bindings = [SALE_TYPE_RETURN, COMPLETED];

        if ($this->isDate($filters['date_from'] ?? null)) {
            $where[]    = 's.sale_time >= ?';
            $bindings[] = $filters['date_from'] . ' 00:00:00';
        }

        if ($this->isDate($filters['date_to'] ?? null)) {
            $where[]    = 's.sale_time <= ?';
            $bindings[] = $filters['date_to'] . ' 23:59:59';
        }

        $this->customerCondition($filters, $where, $bindings);

        if ((int) ($filters['branch_id'] ?? 0) > 0) {
            $where[]    = 's.branch_id = ?';
            $bindings[] = (int) $filters['branch_id'];
        }

        $document = ReturnDocument::tryFrom((string) ($filters['document'] ?? ''));

        if ($document === ReturnDocument::RefundInvoice) {
            $where[]    = 's.return_document = ?';
            $bindings[] = $document->value;
        } elseif ($document === ReturnDocument::CreditNote) {
            $where[] = 's.return_document IS NULL';
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like     = '%' . $this->likeText($search) . '%';
            $where[]  = "(n.number LIKE ? OR CAST(s.sale_id AS CHAR) = ? OR cp.first_name LIKE ? OR cp.last_name LIKE ? OR CONCAT(cp.first_name, ' ', cp.last_name) LIKE ? OR o.invoice_number LIKE ? OR CAST(o.sale_id AS CHAR) = ?)";
            $bindings = [...$bindings, $like, ltrim($search, '#'), $like, $like, $like, $like, ltrim($search, '#')];
        }

        return [implode(' AND ', $where), $bindings];
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $where
     * @param list<int|string>     $bindings
     */
    private function customerCondition(array $filters, array &$where, array &$bindings): void
    {
        if ((int) ($filters['customer_id'] ?? 0) > 0) {
            $where[]    = 's.customer_id = ?';
            $bindings[] = (int) $filters['customer_id'];
        }

        $customer = trim((string) ($filters['customer'] ?? ''));

        if ($customer !== '') {
            $where[]    = "CONCAT(cp.first_name, ' ', cp.last_name) LIKE ?";
            $bindings[] = '%' . $this->likeText($customer) . '%';
        }
    }

    private function likeText(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }
}
