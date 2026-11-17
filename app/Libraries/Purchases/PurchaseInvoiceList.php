<?php

namespace App\Libraries\Purchases;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\PurchaseFigures;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\ReturnState;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Documents\PurchasePaidAmounts;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The purchase invoices and the returns to suppliers as a list that is read a page at a time, whether the books
 * are on or off. The documents, their numbers, days and names are read from the purchases and cut down to the
 * branches given and, for someone who sees their own records only, to the ones they made. What each came to
 * comes from its lines with tax, the way the printed invoice shows it; what was paid and is still due comes from
 * the account of the supplier when the books hold the purchase and from the purchase itself otherwise, so a list
 * cannot disagree with the invoice or with the balance of the supplier. A filter on what was paid or returned,
 * or an order by an amount, needs those figures for every document it keeps, so it is read up to a limit and
 * refused beyond it rather than cut short.
 */
final class PurchaseInvoiceList
{
    public const STATE_ROWS    = 10000;
    public const EXPORT_LIMIT  = 20000;
    public const MAX_PAGE_SIZE = 500;
    public const TOO_MANY      = 'Purchases.too_many_rows';
    public const SUMS_TOO_MANY = 'Purchases.sums_narrow_dates';
    private const DAY          = 'COALESCE(r.invoice_date, DATE(r.receiving_time))';
    private const SORTS        = [
        'date'          => 'doc_date',
        'number'        => 'COALESCE(n.sequence_number, r.receiving_id)',
        'supplier_name' => 'supplier_name',
        'branch_code'   => 'branch_code',
        'employee_name' => 'employee_name',
        'type'          => 'r.receiving_type',
        'receiving_id'  => 'r.receiving_id',
    ];
    private const COMPUTED = ['total', 'paid', 'due', 'payment_state'];

    private BaseConnection $db;
    private string $today;

    public function __construct(?BaseConnection $db = null, ?string $today = null, private readonly int $stateRows = self::STATE_ROWS, private readonly int $exportLimit = self::EXPORT_LIMIT)
    {
        $this->db    = $db ?? Database::connect();
        $this->today = $today ?? date('Y-m-d');
    }

    /**
     * @param list<int>|null $branchIds Null reads every branch, none reads nothing.
     */
    public function search(?array $branchIds, PurchaseInvoiceFilters $filters, string $sort = 'date', string $order = 'desc', int $limit = 25, int $offset = 0, ?int $viewerId = null): PurchaseInvoicePage
    {
        $limit  = max(1, min(self::MAX_PAGE_SIZE, $limit));
        $offset = max(0, $offset);

        [$where, $bindings] = $this->conditions($branchIds, $filters, $viewerId);

        if (! $filters->needsFigures() && ! in_array($sort, self::COMPUTED, true)) {
            $total = $this->count($where, $bindings);
            $rows  = $this->fetch($where, $bindings, $this->orderBy($sort, $order), $limit, $offset);

            return new PurchaseInvoicePage($total, array_values($this->build($rows)));
        }

        $kept = $this->kept($where, $bindings, $filters, $sort, $order);

        if ($kept === null) {
            return new PurchaseInvoicePage(0, [], self::TOO_MANY);
        }

        if (in_array($sort, self::COMPUTED, true)) {
            $kept = $this->sorted($kept, $sort, $order);
        }

        return new PurchaseInvoicePage(count($kept), array_slice($kept, $offset, $limit));
    }

    /**
     * What every document the filters keep adds up to, per currency.
     *
     * @param list<int>|null $branchIds
     */
    public function sums(?array $branchIds, PurchaseInvoiceFilters $filters, ?int $viewerId = null): PurchaseSums
    {
        [$where, $bindings] = $this->conditions($branchIds, $filters, $viewerId);

        $kept = $this->kept($where, $bindings, $filters, 'date', 'desc');

        return $kept === null ? new PurchaseSums([], self::SUMS_TOO_MANY) : PurchaseSums::of($kept);
    }

    /**
     * One document, or nothing for one that does not exist and for one the reader may not see alike.
     *
     * @param list<int>|null $branchIds
     */
    public function row(?array $branchIds, int $receivingId, ?int $viewerId = null): ?PurchaseInvoiceRow
    {
        [$where, $bindings] = $this->conditions($branchIds, new PurchaseInvoiceFilters(), $viewerId);

        $rows = $this->fetch($where . ' AND r.receiving_id = ?', [...$bindings, $receivingId], 'r.receiving_id DESC', 1, 0);

        return array_values($this->build($rows))[0] ?? null;
    }

    /**
     * Every document the filters keep, for a file, or nothing when they keep more than a file may hold.
     *
     * @param list<int>|null $branchIds
     *
     * @return list<PurchaseInvoiceRow>|null
     */
    public function all(?array $branchIds, PurchaseInvoiceFilters $filters, string $sort = 'date', string $order = 'desc', ?int $viewerId = null): ?array
    {
        [$where, $bindings] = $this->conditions($branchIds, $filters, $viewerId);

        if ($filters->needsFigures() || in_array($sort, self::COMPUTED, true)) {
            $kept = $this->kept($where, $bindings, $filters, $sort, $order);

            return $kept === null ? null : (in_array($sort, self::COMPUTED, true) ? $this->sorted($kept, $sort, $order) : $kept);
        }

        $total = $this->count($where, $bindings);

        if ($total > $this->exportLimit) {
            return null;
        }

        $rows = [];

        for ($offset = 0; $offset < $total; $offset += self::MAX_PAGE_SIZE) {
            array_push($rows, ...array_values($this->build($this->fetch($where, $bindings, $this->orderBy($sort, $order), self::MAX_PAGE_SIZE, $offset))));
        }

        return $rows;
    }

    /**
     * The suppliers of the documents the reader may see, for the filter to offer by what is typed.
     *
     * @param list<int>|null $branchIds
     *
     * @return list<array{id: int, name: string}>
     */
    public function suppliers(?array $branchIds, string $term, ?int $viewerId = null, int $supplierId = 0): array
    {
        [$where, $bindings] = $this->conditions($branchIds, new PurchaseInvoiceFilters(supplierId: $supplierId), $viewerId);

        $name = $this->supplierName();
        $where .= ($where === '' ? 'WHERE' : ' AND') . ' r.supplier_id IS NOT NULL';

        if ($term !== '') {
            $where .= " AND {$name} LIKE ? ESCAPE '!'";
            $bindings[] = $this->like($term);
        }

        $rows = $this->db->query(
            "SELECT DISTINCT r.supplier_id AS id, {$name} AS name {$this->from()['sql']} {$where} ORDER BY name ASC LIMIT 10",
            [...$this->from()['bindings'], ...$bindings],
        )->getResult();

        return array_map(static fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name], $rows);
    }

    private function like(string $term): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
    }

    /**
     * @param list<int|string> $bindings
     */
    private function count(string $where, array $bindings): int
    {
        $from = $this->from();

        return (int) $this->db->query("SELECT COUNT(*) AS found {$from['sql']} {$where}", [...$from['bindings'], ...$bindings])->getRow()->found;
    }

    /**
     * Every document the filters keep with its figures, in the order asked, or nothing when they are too many.
     *
     * @param list<int|string> $bindings
     *
     * @return list<PurchaseInvoiceRow>|null
     */
    private function kept(string $where, array $bindings, PurchaseInvoiceFilters $filters, string $sort, string $order): ?array
    {
        $rows = $this->fetch($where, $bindings, $this->orderBy($sort, $order), $this->stateRows + 1, 0);

        if (count($rows) > $this->stateRows) {
            return null;
        }

        return array_values(array_filter($this->build($rows), fn (PurchaseInvoiceRow $row): bool => $this->passes($row, $filters)));
    }

    private function passes(PurchaseInvoiceRow $row, PurchaseInvoiceFilters $filters): bool
    {
        if ($filters->status === 'partly_returned' && ($row->isReturn() || $row->returnState !== ReturnState::Partial)) {
            return false;
        }

        if ($filters->status === 'fully_returned' && ($row->isReturn() || $row->returnState !== ReturnState::Full)) {
            return false;
        }

        return match ($filters->payment) {
            'unpaid'  => $row->state === PaymentState::Unpaid,
            'partial' => $row->state === PaymentState::Partial,
            'paid'    => $row->state === PaymentState::Paid || $row->state === PaymentState::Excess,
            'overdue' => $row->overdueDays > 0,
            default   => true,
        };
    }

    /**
     * @param list<PurchaseInvoiceRow> $rows
     *
     * @return list<PurchaseInvoiceRow>
     */
    private function sorted(array $rows, string $sort, string $order): array
    {
        $states = [PaymentState::Unpaid->value => 0, PaymentState::Partial->value => 1, PaymentState::Paid->value => 2, PaymentState::Excess->value => 3];
        $key    = static fn (PurchaseInvoiceRow $row): string => match ($sort) {
            'total' => $row->signedTotal(),
            'paid'  => $row->paid,
            'due'   => $row->due,
            default => (string) ($row->state === null ? -1 : $states[$row->state->value]),
        };

        $way = strtolower($order) === 'asc' ? 1 : -1;

        usort($rows, static function (PurchaseInvoiceRow $a, PurchaseInvoiceRow $b) use ($key, $way): int {
            $compare = bccomp($key($a), $key($b), SaleAmounts::SCALE);

            return $compare !== 0 ? $way * $compare : $b->receivingId <=> $a->receivingId;
        });

        return $rows;
    }

    private function orderBy(string $sort, string $order): string
    {
        $column = self::SORTS[$sort] ?? self::SORTS['date'];
        $way    = strtolower($order) === 'asc' ? 'ASC' : 'DESC';

        if ($sort === 'date' || ! isset(self::SORTS[$sort])) {
            return "doc_date {$way}, r.receiving_time {$way}, r.receiving_id {$way}";
        }

        return "{$column} {$way}, doc_date DESC, r.receiving_id DESC";
    }

    /**
     * @param list<int|string> $bindings
     *
     * @return list<object>
     */
    private function fetch(string $where, array $bindings, string $orderBy, int $limit, int $offset): array
    {
        $from = $this->from();

        return $this->db->query(
            'SELECT r.receiving_id, r.receiving_type, r.receiving_time, r.supplier_id, r.employee_id, r.branch_id, r.return_of_receiving_id, r.reference,
                    r.payment_type, r.currency_code, r.due_date, ' . self::DAY . " AS doc_date, n.number AS number, o.number AS origin_number,
                    {$this->supplierName()} AS supplier_name, TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name, b.code AS branch_code
             {$from['sql']} {$where}
             ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}",
            [...$from['bindings'], ...$bindings],
        )->getResult();
    }

    /**
     * @return array{sql: string, bindings: list<int>}
     */
    private function from(): array
    {
        $numbers = $this->db->prefixTable('document_numbers');
        $company = CompanyContext::id();

        $sql = 'FROM ' . $this->db->prefixTable('receivings') . " r
                LEFT JOIN {$numbers} n ON n.company_id = ? AND n.doc_type = CASE r.receiving_type WHEN '" . ReceivingType::Return->value . "' THEN '" . DocumentType::DebitNote->value . "' ELSE '" . DocumentType::Purchase->value . "' END AND n.source_table = 'receivings' AND n.source_id = r.receiving_id
                LEFT JOIN {$numbers} o ON o.company_id = ? AND o.doc_type = '" . DocumentType::Purchase->value . "' AND o.source_table = 'receivings' AND o.source_id = r.return_of_receiving_id
                LEFT JOIN " . $this->db->prefixTable('suppliers') . ' s ON s.person_id = r.supplier_id
                LEFT JOIN ' . $this->db->prefixTable('people') . ' sp ON sp.person_id = r.supplier_id
                LEFT JOIN ' . $this->db->prefixTable('people') . ' ep ON ep.person_id = r.employee_id
                LEFT JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = r.branch_id';

        return ['sql' => $sql, 'bindings' => [$company, $company]];
    }

    private function supplierName(): string
    {
        return "COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(COALESCE(sp.first_name, ''), ' ', COALESCE(sp.last_name, ''))))";
    }

    /**
     * @param list<int>|null $branchIds
     *
     * @return array{string, list<int|string>}
     */
    private function conditions(?array $branchIds, PurchaseInvoiceFilters $filters, ?int $viewerId): array
    {
        $where    = ['r.receiving_type IN (?, ?)'];
        $bindings = [ReceivingType::Receive->value, ReceivingType::Return->value];
        $mine     = (new RecordScope($this->db))->limit($viewerId ?? RecordScope::sessionEmployee());

        if ($mine !== null) {
            $where[]    = 'r.employee_id = ?';
            $bindings[] = $mine;
        }

        if ($branchIds !== null) {
            $ids     = array_values(array_unique(array_map('intval', $branchIds)));
            $where[] = $ids === [] ? '1 = 0' : 'r.branch_id IN (' . implode(', ', $ids) . ')';
        }

        if ($filters->dateFrom !== null) {
            $where[]    = self::DAY . ' >= ?';
            $bindings[] = $filters->dateFrom;
        }

        if ($filters->dateTo !== null) {
            $where[]    = self::DAY . ' <= ?';
            $bindings[] = $filters->dateTo;
        }

        if ($filters->supplierId > 0) {
            $where[]    = 'r.supplier_id = ?';
            $bindings[] = $filters->supplierId;
        }

        if ($filters->search !== '') {
            $like     = $this->like($filters->search);
            $escape   = "ESCAPE '!'";
            $where[]  = "(n.number LIKE ? {$escape} OR CONCAT('RECV ', r.receiving_id) LIKE ? {$escape} OR r.reference LIKE ? {$escape} OR {$this->supplierName()} LIKE ? {$escape} OR TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) LIKE ? {$escape} OR r.comment LIKE ? {$escape})";
            $bindings = [...$bindings, $like, $like, $like, $like, $like, $like];
        }

        if ($filters->status === 'received' || $filters->status === 'return') {
            $where[]    = 'r.receiving_type = ?';
            $bindings[] = $filters->status === 'return' ? ReceivingType::Return->value : ReceivingType::Receive->value;
        } elseif ($filters->status !== '') {
            $where[]  = 'r.receiving_type = ? AND EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('receivings') . ' c WHERE c.return_of_receiving_id = r.receiving_id AND c.receiving_type = ?)';
            $bindings = [...$bindings, ReceivingType::Receive->value, ReceivingType::Return->value];
        }

        if ($filters->payment !== '') {
            $where[]    = 'r.receiving_type = ?';
            $bindings[] = ReceivingType::Receive->value;
        }

        return ['WHERE ' . implode(' AND ', $where), $bindings];
    }

    /**
     * The rows of the list for some documents, in the order given, with what each came to and how it stands.
     *
     * @param list<object> $rows
     *
     * @return array<int, PurchaseInvoiceRow>
     */
    private function build(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids       = array_map(static fn (object $row): int => (int) $row->receiving_id, $rows);
        $decimals  = $this->decimalsOf($rows);
        $totals    = (new PurchaseLines($this->db))->totalsOf($ids, $decimals);
        $purchases = [];

        foreach ($rows as $row) {
            if ($row->receiving_type === ReceivingType::Return->value) {
                continue;
            }

            $purchases[(int) $row->receiving_id] = [
                'supplier_id'  => $row->supplier_id === null ? null : (int) $row->supplier_id,
                'payment_type' => $row->payment_type === null ? null : (string) $row->payment_type,
                'foreign'      => $row->currency_code !== null && $row->currency_code !== '',
                'total'        => $totals[(int) $row->receiving_id]['total'],
            ];
        }

        $settled  = $purchases === [] ? [] : (new PurchasePaidAmounts($this->db))->readMany($purchases);
        $returned = $this->returnedOf(array_keys(array_filter($settled, static fn (array $read): bool => $read['figures'] === null)), $decimals);
        $books    = (new AccountingSettings($this->db))->isEnabled();
        $list     = [];

        foreach ($rows as $row) {
            $id       = (int) $row->receiving_id;
            $isReturn = $row->receiving_type === ReceivingType::Return->value;
            $total    = $totals[$id]['total'];
            $read     = $settled[$id] ?? null;
            $figures  = $read['figures'] ?? null;
            $code     = $row->currency_code === null || $row->currency_code === '' ? null : (string) $row->currency_code;
            $state    = null;
            $overdue  = 0;
            $returns  = ReturnState::None;

            if (! $isReturn && $read !== null) {
                $figures ??= new PurchaseFigures($id, (string) $row->doc_date, $total, $read['paid'], '0.0000', $returned[$id] ?? '0.0000', $row->due_date === null ? (string) $row->doc_date : (string) $row->due_date);
                $state   = $figures->paymentState();
                $returns = $figures->returnState();
                $overdue = $books && ($read['figures'] !== null || $code !== null) ? $figures->daysOverdue($this->today) : 0;
            }

            $list[$id] = new PurchaseInvoiceRow(
                $id,
                $row->number !== null ? (string) $row->number : ($isReturn ? DocumentType::DebitNote->fallback($id) : DocumentType::Purchase->fallback($id)),
                (string) $row->doc_date,
                (string) $row->receiving_time,
                ReceivingType::from((string) $row->receiving_type),
                $row->supplier_id === null ? null : (int) $row->supplier_id,
                (string) $row->supplier_name,
                trim((string) $row->employee_name),
                $row->branch_id === null ? null : (int) $row->branch_id,
                (string) ($row->branch_code ?? ''),
                (string) ($row->reference ?? ''),
                $isReturn && $row->origin_number !== null ? (string) $row->origin_number : ($isReturn && $row->return_of_receiving_id !== null ? DocumentType::Purchase->fallback((int) $row->return_of_receiving_id) : null),
                $total,
                $isReturn || $read === null ? '0.0000' : $read['paid'],
                $isReturn || $read === null ? '0.0000' : $read['due'],
                $state,
                $overdue,
                $returns,
                $code,
                $decimals[$id] ?? 2,
            );
        }

        return $list;
    }

    /**
     * What the debit notes of some purchases took back from each, by purchase, for the purchases the books do not hold.
     *
     * @param list<int>       $ids
     * @param array<int, int> $decimals
     *
     * @return array<int, numeric-string>
     */
    private function returnedOf(array $ids, array $decimals): array
    {
        if ($ids === []) {
            return [];
        }

        $notes = $this->db->table('receivings')->select('receiving_id, return_of_receiving_id')->where('receiving_type', ReceivingType::Return->value)->whereIn('return_of_receiving_id', $ids)->get()->getResult();

        if ($notes === []) {
            return [];
        }

        $noteIds      = array_map(static fn (object $note): int => (int) $note->receiving_id, $notes);
        $noteDecimals = [];

        foreach ($notes as $note) {
            $noteDecimals[(int) $note->receiving_id] = $decimals[(int) $note->return_of_receiving_id] ?? 2;
        }

        $totals   = (new PurchaseLines($this->db))->totalsOf($noteIds, $noteDecimals);
        $returned = [];

        foreach ($notes as $note) {
            $origin            = (int) $note->return_of_receiving_id;
            $returned[$origin] = bcadd($returned[$origin] ?? '0', $totals[(int) $note->receiving_id]['total'], SaleAmounts::SCALE);
        }

        return $returned;
    }

    /**
     * The decimals each foreign document is kept in, which are those of its currency.
     *
     * @param list<object> $rows
     *
     * @return array<int, int>
     */
    private function decimalsOf(array $rows): array
    {
        $decimals   = [];
        $known      = [];
        $currencies = null;

        foreach ($rows as $row) {
            if ($row->currency_code === null || $row->currency_code === '') {
                continue;
            }

            $currencies ??= new Currencies($this->db);
            $code                               = (string) $row->currency_code;
            $decimals[(int) $row->receiving_id] = $known[$code] ??= $currencies->decimalsOf($code);
        }

        return $decimals;
    }
}
