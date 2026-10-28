<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reads what is owed to a supplier from the journals, not from the purchase rows: the total of a
 * purchase is what its journal credited the supplier, a payment is what its journal debited, and
 * a credit application is the only thing the books do not carry as a journal. So the figures here
 * are the figures of the account of the supplier, split by purchase.
 */
final class SupplierLedger
{
    private const TYPES = [ReceivingType::Receive->value, ReceivingType::Return->value, SupplierAdvances::OPENING_TYPE];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Where a supplier stands now, or on a day of the past: what the purchases dated by then and the
     * payments and credit applied by then say, which adds up to the balance of the account on that day.
     */
    public function position(int $supplierId, ?string $asOf = null): SupplierPosition
    {
        $accountId = (new EntityAccountService($this->db))->accountFor(EntityAccountService::SUPPLIER, $supplierId);

        if ($accountId === null) {
            return new SupplierPosition($supplierId, null, '0.0000', [], [], $asOf);
        }

        $purchases = $this->purchases($supplierId, $accountId, $asOf);
        $paid      = $this->paid($supplierId, $accountId, $asOf);
        $applied   = $this->applied($supplierId, $asOf);
        $returned  = [];
        $credits   = [];
        $figures   = [];

        foreach ($purchases as $purchase) {
            if ($purchase->receiving_type !== ReceivingType::Return->value) {
                continue;
            }

            $worth  = bcsub('0', $purchase->total, SaleAmounts::SCALE);
            $amount = bcadd($worth, $paid[(int) $purchase->receiving_id] ?? '0', SaleAmounts::SCALE);
            $origin = $purchase->return_of_receiving_id === null ? null : (int) $purchase->return_of_receiving_id;

            if ($origin !== null) {
                $returned[$origin] = bcadd($returned[$origin] ?? '0', $worth, SaleAmounts::SCALE);
            }

            $credits[(int) $purchase->receiving_id] = [CreditSource::DEBIT_NOTE, (int) $purchase->receiving_id, $origin, (string) $purchase->doc_date, $amount];
        }

        foreach ($purchases as $purchase) {
            if ($purchase->receiving_type === ReceivingType::Return->value) {
                continue;
            }

            $receivingId           = (int) $purchase->receiving_id;
            $figures[$receivingId] = new PurchaseFigures(
                $receivingId,
                (string) $purchase->doc_date,
                bcadd($purchase->total, '0', SaleAmounts::SCALE),
                $paid[$receivingId] ?? '0.0000',
                $applied['into'][$receivingId] ?? '0.0000',
                $returned[$receivingId] ?? '0.0000',
                $purchase->due_date === null ? (string) $purchase->doc_date : (string) $purchase->due_date,
                $purchase->payment_terms_days === null ? null : (int) $purchase->payment_terms_days,
                $purchase->receiving_type === SupplierAdvances::OPENING_TYPE,
            );
        }

        $list = [];

        foreach ($figures as $receivingId => $purchase) {
            $list[] = $purchase;
            $excess = $purchase->excess();

            if (bccomp($excess, '0', SaleAmounts::SCALE) > 0) {
                $credits[$receivingId] = [CreditSource::INVOICE_EXCESS, $receivingId, null, $purchase->date, $excess];
            }
        }

        foreach ($this->advances($supplierId, $accountId, $asOf) as $advance) {
            $credits['advance:' . $advance->advance_id] = [CreditSource::ADVANCE, (int) $advance->advance_id, null, (string) $advance->advance_date, bcadd((string) $advance->amount, '0', SaleAmounts::SCALE)];
        }

        $sources = [];

        foreach ($credits as [$type, $sourceId, $origin, $date, $amount]) {
            $sources[] = new CreditSource($type, $sourceId, $origin, $date, $amount, $applied['from'][$type . ':' . $sourceId] ?? '0.0000');
        }

        usort($sources, static fn (CreditSource $a, CreditSource $b): int => [$a->date, $a->sourceId] <=> [$b->date, $b->sourceId]);
        usort($list, static fn (PurchaseFigures $a, PurchaseFigures $b): int => [$a->date, $a->receivingId] <=> [$b->date, $b->receivingId]);

        return new SupplierPosition($supplierId, $accountId, $this->accountBalance($accountId, $asOf), $list, $sources, $asOf);
    }

    /**
     * The applications that stand for a supplier, oldest first, or all of them with the ones
     * that were released.
     *
     * @return list<object>
     */
    public function applications(int $supplierId, bool $withReleased = false): array
    {
        $builder = $this->db->table('supplier_credit_applications')->where('supplier_id', $supplierId);

        if (! $withReleased) {
            $builder->where('released_at', null);
        }

        return $builder->orderBy('application_id', 'ASC')->get()->getResult();
    }

    /**
     * The name the account of a supplier goes by, which is the name of the supplier.
     */
    public function accountName(int $supplierId): string
    {
        $row = $this->db->table('accounting_accounts')->select('name')->where(['entity_type' => EntityAccountService::SUPPLIER, 'entity_id' => $supplierId])->get()->getRow();

        return $row === null ? '' : (string) $row->name;
    }

    /**
     * The suppliers whose account holds something, by what it holds. A balance is what is owed to
     * the supplier, or minus what they owe back.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function suppliersWithBalance(string $search = '', string $sort = 'balance', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $accounts = $this->db->prefixTable('accounting_accounts');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $journals = $this->db->prefixTable('accounting_journals');
        $bindings = [JournalStatus::Posted->value, JournalStatus::Reversed->value, EntityAccountService::SUPPLIER];
        $filter   = '';

        if ($search !== '') {
            $filter     = ' AND a.name LIKE ?';
            $bindings[] = '%' . $this->db->escapeLikeString($search) . '%';
        }

        $grouped = "SELECT a.entity_id AS supplier_id, a.name AS supplier_name, a.account_id,
                           SUM(l.credit) - SUM(l.debit) AS balance
                    FROM {$accounts} a
                    JOIN {$lines} l ON l.account_id = a.account_id
                    JOIN {$journals} j ON j.journal_id = l.journal_id AND j.status IN (?, ?)
                    WHERE a.entity_type = ?{$filter}
                    GROUP BY a.account_id, a.entity_id, a.name
                    HAVING balance <> 0";

        $total = (int) $this->db->query("SELECT COUNT(*) AS found FROM ({$grouped}) t", $bindings)->getRow()->found;

        $column = $sort === 'supplier_name' ? 'supplier_name' : 'balance';
        $way    = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $rows   = $this->db->query("{$grouped} ORDER BY {$column} {$way}, supplier_id ASC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $bindings)->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * The purchases and returns of a supplier that are in the books, with what their journal
     * credited the account of the supplier, the day they are dated and their due date. A purchase
     * with no date typed is dated the day it was saved and falls due that same day.
     *
     * @return list<object>
     */
    private function purchases(int $supplierId, int $accountId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $bindings = [$accountId, ReceivingPostingBuilder::SOURCE_PURCHASE, JournalEntryType::General->value, ...$standingBindings, $supplierId, ...self::TYPES];
        $where    = '';

        if ($asOf !== null) {
            $where      = ' AND COALESCE(r.invoice_date, DATE(r.receiving_time)) <= ?';
            $bindings[] = $asOf;
        }

        return $this->db->query(
            'SELECT r.receiving_id, r.receiving_type, r.receiving_time, r.return_of_receiving_id, r.due_date, r.payment_terms_days,
                    COALESCE(r.invoice_date, DATE(r.receiving_time)) AS doc_date,
                    (SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l WHERE l.journal_id = j.journal_id AND l.account_id = ?) AS total
             FROM ' . $this->db->prefixTable('receivings') . ' r
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = r.receiving_id AND j.entry_type = ? AND {$standing}
             WHERE r.supplier_id = ? AND r.receiving_type IN (" . implode(', ', array_fill(0, count(self::TYPES), '?')) . "){$where}
             ORDER BY doc_date ASC, r.receiving_id ASC",
            $bindings,
        )->getResult();
    }

    /**
     * The payments to the supplier that belong to no purchase, and what the supplier owed us from
     * before the books started, with what their journal debited the account of the supplier.
     *
     * @return list<object>
     */
    private function advances(int $supplierId, int $accountId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $bindings = [$accountId, SupplierAdvances::SOURCE, JournalEntryType::General->value, ...$standingBindings, $supplierId];
        $status   = '';

        if ($asOf === null) {
            $status     = ' AND a.status = ?';
            $bindings[] = 'completed';
        }

        return $this->db->query(
            'SELECT a.advance_id, a.advance_date,
                    (SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l WHERE l.journal_id = j.journal_id AND l.account_id = ?) AS amount
             FROM ' . $this->db->prefixTable('supplier_advances') . ' a
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = a.advance_id AND j.entry_type = ? AND {$standing}
             WHERE a.supplier_id = ?{$status}",
            $bindings,
        )->getResult();
    }

    /**
     * What the payments that stand debited the supplier, by purchase: the payment made when the
     * purchase was saved and the payments made against it since. What a supplier paid back for a
     * return is negative.
     *
     * @return array<int, numeric-string>
     */
    private function paid(int $supplierId, int $accountId, ?string $asOf): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($this->db, 'j', $asOf);

        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $rows     = $this->db->query(
            'SELECT t.receiving_id, SUM(l.debit - l.credit) AS paid
             FROM (
                 SELECT r.receiving_id, r.receiving_id AS source_id, ? AS source_type
                 FROM ' . $this->db->prefixTable('receivings') . ' r
                 WHERE r.supplier_id = ?
                 UNION ALL
                 SELECT p.receiving_id, p.payment_id AS source_id, ? AS source_type
                 FROM ' . $this->db->prefixTable('receivings_payments') . ' p
                 JOIN ' . $this->db->prefixTable('receivings') . " r ON r.receiving_id = p.receiving_id AND r.supplier_id = ?
             ) t
             JOIN {$journals} j ON j.source_type = t.source_type AND j.source_id = t.source_id AND {$standing}
             JOIN {$lines} l ON l.journal_id = j.journal_id AND l.account_id = ?
             GROUP BY t.receiving_id",
            [ReceivingPostingBuilder::SOURCE_PAYMENT, $supplierId, ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, $supplierId, ...$standingBindings, $accountId],
        )->getResult();

        $paid = [];

        foreach ($rows as $row) {
            $paid[(int) $row->receiving_id] = bcadd($row->paid, '0', SaleAmounts::SCALE);
        }

        return $paid;
    }

    /**
     * What the applications of credit stand for, by purchase and by source. On a day of the past it is
     * the ones made by then and not yet released by then.
     *
     * @return array{into: array<int, numeric-string>, from: array<string, numeric-string>}
     */
    private function applied(int $supplierId, ?string $asOf): array
    {
        $into = [];
        $from = [];

        foreach ($this->applications($supplierId, $asOf !== null) as $application) {
            if ($asOf !== null && (substr((string) $application->created_at, 0, 10) > $asOf || ($application->released_at !== null && substr((string) $application->released_at, 0, 10) <= $asOf))) {
                continue;
            }

            $purchase = (int) $application->invoice_receiving_id;
            $source   = $application->source_type . ':' . $application->source_id;

            $into[$purchase] = bcadd($into[$purchase] ?? '0', $application->amount, SaleAmounts::SCALE);
            $from[$source]   = bcadd($from[$source] ?? '0', $application->amount, SaleAmounts::SCALE);
        }

        return ['into' => $into, 'from' => $from];
    }

    /**
     * Credit less debit of the account of the supplier over everything posted, reversals
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
            'SELECT COALESCE(SUM(l.credit), 0) - COALESCE(SUM(l.debit), 0) AS balance
             FROM ' . $this->db->prefixTable('accounting_journal_lines') . ' l
             JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.journal_id = l.journal_id
             WHERE j.status IN (?, ?) AND l.account_id = ?{$until}",
            $bindings,
        )->getRow();

        return bcadd((string) $row->balance, '0', SaleAmounts::SCALE);
    }
}
