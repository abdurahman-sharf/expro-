<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the cheque screens read: the list of the cheques of one direction, and the report of them
 * that is tied to the accounts the cheque cycle posts to. Both are cut down to the branches the
 * reader works in.
 */
final class ChequeList
{
    public const REPORT_LIMIT = 5000;
    public const PARTY_NAME   = "COALESCE(NULLIF(s.company_name, ''), NULLIF(cu.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name)))";
    private const SORTABLE    = ['cheque_id' => 'c.cheque_id', 'cheque_number' => 'c.cheque_number', 'issue_date' => 'c.issue_date', 'due_date' => 'c.due_date', 'amount' => 'c.amount', 'status' => 'c.status', 'party_name' => 'party_name'];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<int>|null                                                                                                                              $branchIds The branches the reader may see, null for every branch.
     * @param array{search?: string, status?: string, party_id?: int, date_from?: string, date_to?: string, due_from?: string, due_to?: string, deleted?: bool} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(ChequeDirection $direction, ?array $branchIds, array $filters, string $sort, string $order, int $limit, int $offset): array
    {
        if ($branchIds === []) {
            return ['total' => 0, 'rows' => []];
        }

        $builder = $this->base($direction, $branchIds, $filters);
        $total   = (clone $builder)->countAllResults();
        $rows    = $builder
            ->select('c.*, ' . self::PARTY_NAME . ' AS party_name, t.name AS treasury_name, b.code AS branch_code, k.name AS book_name, n.number AS document_number', false)
            ->orderBy(self::SORTABLE[$sort] ?? 'c.cheque_id', strtolower($order) === 'asc' ? 'ASC' : 'DESC')
            ->orderBy('c.cheque_id', 'DESC')
            ->limit(max(1, $limit), max(0, $offset))
            ->get()
            ->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * The cheques of the filter with the totals by status, and what the accounts of the cheque cycle
     * hold against what the cheques in each status add up to: the two figures are the same while the
     * books and the cheques agree.
     *
     * @param list<int>|null $branchIds
     * @param array<string, mixed> $filters
     *
     * @return array{rows: list<object>, truncated: bool, totals: array<string, string>, total: string, ties: list<array{key: string, account: ?object, ledger: string, cheques: string, agrees: bool}>}
     */
    public function report(ChequeDirection $direction, ?array $branchIds, array $filters): array
    {
        if ($branchIds === []) {
            return ['rows' => [], 'truncated' => false, 'totals' => [], 'total' => '0.0000', 'ties' => []];
        }

        $rows = $this->base($direction, $branchIds, $filters)
            ->select('c.*, ' . self::PARTY_NAME . ' AS party_name, t.name AS treasury_name, b.code AS branch_code, k.name AS book_name, n.number AS document_number', false)
            ->orderBy('c.issue_date', 'ASC')
            ->orderBy('c.cheque_id', 'ASC')
            ->limit(self::REPORT_LIMIT + 1)
            ->get()
            ->getResult();

        $truncated = count($rows) > self::REPORT_LIMIT;
        $rows      = array_slice($rows, 0, self::REPORT_LIMIT);
        $totals    = array_fill_keys(array_map(static fn (ChequeStatus $status): string => $status->value, ChequeStatus::of($direction)), '0.0000');
        $total     = '0.0000';

        foreach ($rows as $row) {
            $totals[$row->status] = bcadd($totals[$row->status], (string) $row->amount, SaleAmounts::SCALE);
            $total                = bcadd($total, (string) $row->amount, SaleAmounts::SCALE);
        }

        return ['rows' => $rows, 'truncated' => $truncated, 'totals' => $totals, 'total' => $total, 'ties' => $this->ties($direction, $branchIds)];
    }

    /**
     * The customers, or the suppliers, a cheque can be entered for, by name.
     *
     * @return list<object>
     */
    public function parties(ChequeDirection $direction, int $limit): array
    {
        return $this->db->table(($direction === ChequeDirection::Received ? 'customers' : 'suppliers') . ' x')
            ->select("x.person_id, COALESCE(NULLIF(x.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS name", false)
            ->join('people p', 'p.person_id = x.person_id')
            ->where('x.deleted', 0)
            ->orderBy('name', 'ASC')
            ->limit($limit)
            ->get()
            ->getResult();
    }

    /**
     * @param list<int>|null $branchIds
     *
     * @return list<array{key: string, account: ?object, ledger: string, cheques: string, agrees: bool}>
     */
    private function ties(ChequeDirection $direction, ?array $branchIds): array
    {
        $pairs = $direction === ChequeDirection::Received
            ? [[RoutingKey::NotesReceivable, ChequeStatus::Received, 1], [RoutingKey::ChequesUnderCollection, ChequeStatus::Deposited, 1]]
            : [[RoutingKey::NotesPayable, ChequeStatus::Delivered, -1]];

        $ties = [];

        foreach ($pairs as [$key, $status, $sign]) {
            $account = $this->db->table('accounting_accounts')->select('account_id, code, name')->where('company_id', CompanyContext::id())->where('system_key', $key->defaultSystemKey())->get()->getRow();
            $ledger  = $account === null ? '0.0000' : $this->ledger((int) $account->account_id, $branchIds, $sign);
            $held    = $this->held($direction, $status, $branchIds);

            $ties[] = ['key' => $key->value, 'account' => $account, 'ledger' => $ledger, 'cheques' => $held, 'agrees' => bccomp($ledger, $held, SaleAmounts::SCALE) === 0];
        }

        return $ties;
    }

    /**
     * What an account holds on the side it is normally on: debit less credit for an asset and credit
     * less debit for a liability, over everything posted, reversals included.
     *
     * @param list<int>|null $branchIds
     *
     * @return numeric-string
     */
    private function ledger(int $accountId, ?array $branchIds, int $sign): string
    {
        $builder = $this->db->table('accounting_journal_lines l')
            ->select('COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->where('l.account_id', $accountId);

        if ($branchIds !== null) {
            $builder->whereIn('j.branch_id', $branchIds);
        }

        $balance = (string) $builder->get()->getRow()->balance;

        return bcmul(bcadd($balance, '0', SaleAmounts::SCALE), (string) $sign, SaleAmounts::SCALE);
    }

    /**
     * @param list<int>|null $branchIds
     *
     * @return numeric-string
     */
    private function held(ChequeDirection $direction, ChequeStatus $status, ?array $branchIds): string
    {
        $builder = $this->db->table('cheques')
            ->select('COALESCE(SUM(amount), 0) AS held', false)
            ->where(['company_id' => CompanyContext::id(), 'direction' => $direction->value, 'status' => $status->value, 'deleted' => 0]);

        if ($branchIds !== null) {
            $builder->whereIn('branch_id', $branchIds);
        }

        return bcadd((string) $builder->get()->getRow()->held, '0', SaleAmounts::SCALE);
    }

    /**
     * @param list<int>|null       $branchIds
     * @param array<string, mixed> $filters
     */
    private function base(ChequeDirection $direction, ?array $branchIds, array $filters): BaseBuilder
    {
        $type    = $direction === ChequeDirection::Received ? DocumentType::ReceivedCheque : DocumentType::PaidCheque;
        $builder = $this->db->table('cheques c')
            ->join('people p', 'p.person_id = c.party_id')
            ->join('customers cu', 'cu.person_id = c.party_id', 'left')
            ->join('suppliers s', 's.person_id = c.party_id', 'left')
            ->join('treasuries t', 't.treasury_id = c.treasury_id')
            ->join('branches b', 'b.branch_id = c.branch_id')
            ->join('cheque_books k', 'k.book_id = c.book_id', 'left')
            ->join('document_numbers n', 'n.doc_type = ' . $this->db->escape($type->value) . " AND n.source_table = 'cheques' AND n.source_id = c.cheque_id", 'left')
            ->where('c.company_id', CompanyContext::id())
            ->where('c.direction', $direction->value)
            ->where('c.deleted', empty($filters['deleted']) ? 0 : 1);

        if ($branchIds !== null) {
            $builder->whereIn('c.branch_id', $branchIds);
        }

        if (($filters['status'] ?? '') !== '' && ChequeStatus::tryFrom((string) $filters['status']) !== null) {
            $builder->where('c.status', (string) $filters['status']);
        }

        if ((int) ($filters['party_id'] ?? 0) > 0) {
            $builder->where('c.party_id', (int) $filters['party_id']);
        }

        foreach (['date_from' => ['c.issue_date', '>='], 'date_to' => ['c.issue_date', '<='], 'due_from' => ['c.due_date', '>='], 'due_to' => ['c.due_date', '<=']] as $key => [$column, $operator]) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters[$key] ?? '')) === 1) {
                $builder->where("{$column} {$operator}", $filters[$key]);
            }
        }

        $term = trim((string) ($filters['search'] ?? ''));

        if ($term !== '') {
            $builder->groupStart()
                ->like('c.cheque_number', $term)
                ->orLike('c.drawer_name', $term)
                ->orLike('c.description', $term)
                ->orLike('n.number', $term)
                ->orLike('p.first_name', $term)
                ->orLike('p.last_name', $term)
                ->orLike('s.company_name', $term)
                ->orLike('cu.company_name', $term)
                ->groupEnd();
        }

        return $builder;
    }
}
