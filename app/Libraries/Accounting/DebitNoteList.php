<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Every return of goods to a supplier as the debit note it is: its number (the one its sequence
 * gave it, or for a return saved before there were numbers the receiving number it has always
 * shown), the purchase it takes goods back from, the supplier, what it comes to, what the supplier
 * paid back at once, and what of the rest was applied against what the supplier is owed. What the
 * return is worth is read from its own lines, the way the books were given it; what was applied is
 * read from the credit applications that still stand. Nothing here changes how a return is valued
 * or posted. What is read is cut down to the branches given.
 */
final class DebitNoteList
{
    public const SETTLED_IN_CASH = 'cash';
    public const APPLIED         = 'applied';
    public const PARTLY_APPLIED  = 'partly';
    public const HELD            = 'held';
    public const NOTHING         = 'nothing';

    private const SORTS = [
        'date'          => 'r.receiving_time',
        'number'        => 'COALESCE(dn.sequence_number, r.receiving_id)',
        'supplier_name' => 'supplier_name',
    ];

    private BaseConnection $db;
    private ReceivingLoader $loader;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->loader = new ReceivingLoader($this->db);
    }

    /**
     * @param list<int>|null       $branchIds Null reads every branch, none reads nothing.
     * @param array<string, mixed> $filters   search, date_from, date_to, supplier_id and employee_id; anything that is not what a filter takes is left out.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(?array $branchIds, array $filters = [], string $sort = 'date', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        [$where, $bindings] = $this->conditions($branchIds, $filters);

        $from  = $this->from();
        $total = (int) $this->db->query("SELECT COUNT(*) AS found {$from['sql']} {$where}", [...$from['bindings'], ...$bindings])->getRow()->found;

        $column = self::SORTS[$sort] ?? self::SORTS['date'];
        $way    = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $paging = ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);
        $notes  = $this->db->query(
            "SELECT r.receiving_id, r.receiving_time, r.supplier_id, r.employee_id, r.branch_id, r.return_of_receiving_id, r.payment_type, r.comment,
                    dn.number AS number, pn.number AS origin_number, {$this->supplierName()} AS supplier_name,
                    TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name, b.code AS branch_code,
                    {$this->applied()} AS applied, {$this->refunded()} AS refunded
             {$from['sql']} {$where}
             ORDER BY {$column} {$way}, r.receiving_id DESC{$paging}",
            [...$from['bindings'], ...$bindings],
        )->getResult();

        $rows = [];

        foreach ($notes as $note) {
            $document     = $this->loader->load((int) $note->receiving_id, false);
            $amount       = $document === null ? '0.00' : ReceivingAmounts::totals($document->lines)['total'];
            $refunded     = bcadd((string) $note->refunded, '0', SaleAmounts::SCALE);
            $applied      = bcadd((string) $note->applied, '0', SaleAmounts::SCALE);
            $credit       = bcsub($amount, $refunded, SaleAmounts::SCALE);
            $credit       = bccomp($credit, '0', SaleAmounts::SCALE) > 0 ? $credit : '0.0000';
            $note->amount = bcadd($amount, '0', SaleAmounts::SCALE);

            $note->refunded = $refunded;
            $note->applied  = $applied;
            $note->held     = bcsub($credit, $applied, SaleAmounts::SCALE);
            $note->state    = self::stateOf($note->amount, $credit, $applied);
            $rows[]         = $note;
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Where a debit note stands against what the supplier is owed: paid back in cash entirely,
     * applied entirely, applied in part, held with the supplier as credit, or worth nothing.
     *
     * @param numeric-string $amount  What the return comes to.
     * @param numeric-string $credit  What is left after what the supplier paid back at once.
     * @param numeric-string $applied What was applied against what the supplier is owed.
     */
    public static function stateOf(string $amount, string $credit, string $applied): string
    {
        return match (true) {
            bccomp($amount, '0', SaleAmounts::SCALE) <= 0 => self::NOTHING,
            bccomp($credit, '0', SaleAmounts::SCALE) <= 0 => self::SETTLED_IN_CASH,
            bccomp($applied, '0', SaleAmounts::SCALE) <= 0 => self::HELD,
            bccomp($applied, $credit, SaleAmounts::SCALE) < 0 => self::PARTLY_APPLIED,
            default => self::APPLIED,
        };
    }

    /**
     * The branch a debit note belongs to, or null when there is no such debit note.
     */
    public function branchOf(int $receivingId): ?int
    {
        $row = $this->db->table('receivings')->select('branch_id')->where(['receiving_id' => $receivingId, 'receiving_type' => ReceivingType::Return->value])->get()->getRow();

        return $row === null ? null : (int) $row->branch_id;
    }

    /**
     * @return array{sql: string, bindings: list<int|string>}
     */
    private function from(): array
    {
        $numbers  = $this->db->prefixTable('document_numbers');
        $accounts = $this->db->prefixTable('accounting_accounts');
        $company  = CompanyContext::id();

        $sql = 'FROM ' . $this->db->prefixTable('receivings') . " r
                LEFT JOIN {$numbers} dn ON dn.company_id = ? AND dn.doc_type = ? AND dn.source_table = 'receivings' AND dn.source_id = r.receiving_id
                LEFT JOIN {$numbers} pn ON pn.company_id = ? AND pn.doc_type = ? AND pn.source_table = 'receivings' AND pn.source_id = r.return_of_receiving_id
                LEFT JOIN {$accounts} sa ON sa.company_id = ? AND sa.entity_type = ? AND sa.entity_id = r.supplier_id
                LEFT JOIN " . $this->db->prefixTable('suppliers') . ' s ON s.person_id = r.supplier_id
                LEFT JOIN ' . $this->db->prefixTable('people') . ' sp ON sp.person_id = r.supplier_id
                LEFT JOIN ' . $this->db->prefixTable('people') . ' ep ON ep.person_id = r.employee_id
                LEFT JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = r.branch_id';

        return ['sql' => $sql, 'bindings' => [$company, DocumentType::DebitNote->value, $company, DocumentType::Purchase->value, $company, EntityAccountService::SUPPLIER]];
    }

    private function supplierName(): string
    {
        return "COALESCE(sa.name, NULLIF(s.company_name, ''), TRIM(CONCAT(COALESCE(sp.first_name, ''), ' ', COALESCE(sp.last_name, ''))))";
    }

    private function applied(): string
    {
        return '(SELECT COALESCE(SUM(c.amount), 0) FROM ' . $this->db->prefixTable('supplier_credit_applications') . " c WHERE c.source_type = '" . CreditSource::DEBIT_NOTE . "' AND c.source_id = r.receiving_id AND c.released_at IS NULL)";
    }

    private function refunded(): string
    {
        return '(SELECT COALESCE(SUM(l.credit - l.debit), 0) FROM ' . $this->db->prefixTable('accounting_journals') . ' j JOIN ' . $this->db->prefixTable('accounting_journal_lines')
            . " l ON l.journal_id = j.journal_id AND l.account_id = sa.account_id WHERE j.source_type = '" . ReceivingPostingBuilder::SOURCE_PAYMENT . "' AND j.source_id = r.receiving_id AND j.entry_type = '"
            . JournalEntryType::General->value . "' AND j.status = '" . JournalStatus::Posted->value . "')";
    }

    /**
     * @param list<int>|null       $branchIds
     * @param array<string, mixed> $filters
     *
     * @return array{string, list<int|string>}
     */
    private function conditions(?array $branchIds, array $filters): array
    {
        $where    = ['r.receiving_type = ?'];
        $bindings = [ReceivingType::Return->value];

        if ($branchIds !== null) {
            $ids     = array_values(array_unique(array_map('intval', $branchIds)));
            $where[] = $ids === [] ? '1 = 0' : 'r.branch_id IN (' . implode(', ', $ids) . ')';
        }

        $search = $this->text($filters['search'] ?? null);

        if ($search !== '') {
            $like     = '%' . $this->db->escapeLikeString($search) . '%';
            $escape   = "ESCAPE '!'";
            $where[]  = "(dn.number LIKE ? {$escape} OR (dn.number IS NULL AND CONCAT('RECV ', r.receiving_id) LIKE ? {$escape}) OR pn.number LIKE ? {$escape} OR (pn.number IS NULL AND CONCAT('RECV ', r.return_of_receiving_id) LIKE ? {$escape}) OR {$this->supplierName()} LIKE ? {$escape})";
            $bindings = [...$bindings, $like, $like, $like, $like, $like];
        }

        $from = $this->day($filters['date_from'] ?? null);
        $to   = $this->day($filters['date_to'] ?? null);

        if ($from !== null) {
            $where[]    = 'DATE(r.receiving_time) >= ?';
            $bindings[] = $from;
        }

        if ($to !== null) {
            $where[]    = 'DATE(r.receiving_time) <= ?';
            $bindings[] = $to;
        }

        foreach (['supplier_id' => 'r.supplier_id', 'employee_id' => 'r.employee_id'] as $name => $column) {
            $id = $this->id($filters[$name] ?? null);

            if ($id > 0) {
                $where[]    = "{$column} = ?";
                $bindings[] = $id;
            }
        }

        return ['WHERE ' . implode(' AND ', $where), $bindings];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function id(mixed $value): int
    {
        return is_string($value) || is_int($value) ? (int) $value : 0;
    }

    private function day(mixed $value): ?string
    {
        $text = $this->text($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $text;
    }
}
