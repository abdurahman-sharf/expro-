<?php

namespace App\Libraries\Accounting;

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

    public function position(int $customerId): CustomerPosition
    {
        $accountId = (new EntityAccountService($this->db))->accountFor(EntityAccountService::CUSTOMER, $customerId);

        if ($accountId === null) {
            return new CustomerPosition($customerId, null, '0.0000', [], []);
        }

        $sales    = $this->sales($customerId, $accountId);
        $paid     = $this->paid($customerId, $accountId);
        $applied  = $this->applied($customerId);
        $returned = [];
        $credits  = [];
        $invoices = [];

        foreach ($sales as $sale) {
            if ((int) $sale->sale_type !== SALE_TYPE_RETURN) {
                continue;
            }

            $amount = bcadd(bcsub('0', $sale->total, SaleAmounts::SCALE), $paid[(int) $sale->sale_id] ?? '0', SaleAmounts::SCALE);
            $origin = $sale->return_of_sale_id === null ? null : (int) $sale->return_of_sale_id;

            if ($origin !== null) {
                $returned[$origin] = bcadd($returned[$origin] ?? '0', bcsub('0', $sale->total, SaleAmounts::SCALE), SaleAmounts::SCALE);
            }

            $credits[(int) $sale->sale_id] = [CreditSource::CREDIT_NOTE, (int) $sale->sale_id, $origin, substr((string) $sale->sale_time, 0, 10), $amount];
        }

        $figures = [];

        foreach ($sales as $sale) {
            if ((int) $sale->sale_type === SALE_TYPE_RETURN) {
                continue;
            }

            $saleId           = (int) $sale->sale_id;
            $figures[$saleId] = new InvoiceFigures(
                $saleId,
                substr((string) $sale->sale_time, 0, 10),
                (int) $sale->sale_type,
                bcadd($sale->total, '0', SaleAmounts::SCALE),
                $paid[$saleId] ?? '0.0000',
                $applied['into'][$saleId] ?? '0.0000',
                $returned[$saleId] ?? '0.0000',
            );
        }

        foreach ($figures as $saleId => $invoice) {
            $invoices[] = $invoice;
            $excess     = $invoice->excess();

            if (bccomp($excess, '0', SaleAmounts::SCALE) > 0) {
                $credits[$saleId] = [CreditSource::INVOICE_EXCESS, $saleId, null, $invoice->date, $excess];
            }
        }

        $sources = [];

        foreach ($credits as $key => [$type, $sourceId, $origin, $date, $amount]) {
            $sources[] = new CreditSource($type, $sourceId, $origin, $date, $amount, $applied['from'][$type . ':' . $sourceId] ?? '0.0000');
        }

        usort($sources, static fn (CreditSource $a, CreditSource $b): int => [$a->date, $a->sourceId] <=> [$b->date, $b->sourceId]);
        usort($invoices, static fn (InvoiceFigures $a, InvoiceFigures $b): int => [$a->date, $a->saleId] <=> [$b->date, $b->saleId]);

        return new CustomerPosition($customerId, $accountId, $this->accountBalance($accountId), $invoices, $sources);
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

        $total = (int) $this->db->query("SELECT COUNT(*) AS found FROM ({$grouped}) t", $bindings)->getRow()->found;

        $column = $sort === 'customer_name' ? 'customer_name' : 'balance';
        $way    = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $rows   = $this->db->query("{$grouped} ORDER BY {$column} {$way}, customer_id ASC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $bindings)->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * The sales of a customer that are in the books, with what their invoice journal debited
     * the account of the customer.
     *
     * @return list<object>
     */
    private function sales(int $customerId, int $accountId): array
    {
        return $this->db->query(
            'SELECT s.sale_id, s.sale_type, s.sale_time, s.return_of_sale_id,
                    (SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l WHERE l.journal_id = j.journal_id AND l.account_id = ?) AS total
             FROM ' . $this->db->prefixTable('sales') . ' s
             JOIN ' . $this->db->prefixTable('accounting_journals') . ' j ON j.source_type = ? AND j.source_id = s.sale_id AND j.entry_type = ? AND j.status = ?
             WHERE s.customer_id = ? AND s.sale_status = ? AND s.sale_type IN (' . implode(',', self::SALE_TYPES) . ')
             ORDER BY s.sale_time ASC, s.sale_id ASC',
            [$accountId, SalePostingBuilder::SOURCE_SALE, JournalEntryType::Revenue->value, JournalStatus::Posted->value, $customerId, COMPLETED],
        )->getResult();
    }

    /**
     * What the payments that stand credited the customer, by sale. A refund is negative.
     *
     * @return array<int, numeric-string>
     */
    private function paid(int $customerId, int $accountId): array
    {
        $rows = $this->db->query(
            'SELECT p.sale_id, SUM(l.credit - l.debit) AS paid
             FROM ' . $this->db->prefixTable('sales_payments') . ' p
             JOIN ' . $this->db->prefixTable('sales') . ' s ON s.sale_id = p.sale_id AND s.customer_id = ?
             JOIN ' . $this->db->prefixTable('accounting_journals') . ' j ON j.source_type = ? AND j.source_id = p.payment_id AND j.status = ?
             JOIN ' . $this->db->prefixTable('accounting_journal_lines') . ' l ON l.journal_id = j.journal_id AND l.account_id = ?
             GROUP BY p.sale_id',
            [$customerId, SalePostingBuilder::SOURCE_PAYMENT, JournalStatus::Posted->value, $accountId],
        )->getResult();

        $paid = [];

        foreach ($rows as $row) {
            $paid[(int) $row->sale_id] = bcadd($row->paid, '0', SaleAmounts::SCALE);
        }

        return $paid;
    }

    /**
     * @return array{into: array<int, numeric-string>, from: array<string, numeric-string>}
     */
    private function applied(int $customerId): array
    {
        $into = [];
        $from = [];

        foreach ($this->applications($customerId) as $application) {
            $invoice = (int) $application->invoice_sale_id;
            $source  = $application->source_type . ':' . $application->source_id;

            $into[$invoice] = bcadd($into[$invoice] ?? '0', $application->amount, SaleAmounts::SCALE);
            $from[$source]  = bcadd($from[$source] ?? '0', $application->amount, SaleAmounts::SCALE);
        }

        return ['into' => $into, 'from' => $from];
    }

    /**
     * Debit less credit of the account of the customer over everything posted, reversals
     * included, which is how every balance in the books is read.
     *
     * @return numeric-string
     */
    private function accountBalance(int $accountId): string
    {
        $row = $this->db->query(
            'SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance
             FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l
             JOIN ' . $this->db->prefixTable('accounting_journals') . ' j ON j.journal_id = l.journal_id
             WHERE j.status IN (?, ?) AND l.account_id = ?',
            [JournalStatus::Posted->value, JournalStatus::Reversed->value, $accountId],
        )->getRow();

        return bcadd((string) $row->balance, '0', SaleAmounts::SCALE);
    }
}
