<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What purchases put on the cost of sales for lines that were not kept in stock, before such lines
 * went to the account of purchases. The journals of those purchases are never changed. This lists
 * them, and for those dated in the fiscal year that is open today it can post one journal for each
 * branch, dated today, that moves what they put on the cost of sales to the account of purchases.
 * Posting again moves only what was not moved yet. Returns are left out: what a return took off the
 * cost of sales cannot be told apart from its price variance.
 */
final class PurchaseCostReclass
{
    use RunsInTransaction;

    public const SOURCE = 'purchase_reclass';
    private const SCALE = 4;

    private BaseConnection $db;
    private RoutingResolver $resolver;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->resolver = new RoutingResolver($this->db);
    }

    /**
     * Every purchase journal that stands and debits the cost of sales, oldest first.
     *
     * @return list<array{journal_id: int, number: string, date: string, branch_id: int, receiving_id: int, amount: string, in_open_year: bool}>
     */
    public function affected(string $today): array
    {
        $accounts = $this->costAccounts();

        if ($accounts === []) {
            return [];
        }

        $year = $this->openYear($today);
        $rows = $this->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.journal_date, j.branch_id, j.source_id, SUM(l.debit) AS amount', false)
            ->join('accounting_journal_lines l', 'l.journal_id = j.journal_id')
            ->join('receivings r', 'r.receiving_id = j.source_id')
            ->where('j.company_id', CompanyContext::id())
            ->where('j.source_type', ReceivingPostingBuilder::SOURCE_PURCHASE)
            ->where('j.status', JournalStatus::Posted->value)
            ->whereIn('l.account_id', $accounts)
            ->where('l.debit >', 0)
            ->groupStart()->where('r.receiving_type', null)->orWhere('r.receiving_type', ReceivingType::Receive->value)->groupEnd()
            ->groupBy('j.journal_id, j.number, j.journal_date, j.branch_id, j.source_id')
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC')
            ->get()
            ->getResult();

        return array_map(static fn (object $row): array => [
            'journal_id'   => (int) $row->journal_id,
            'number'       => (string) ($row->number ?? ''),
            'date'         => (string) $row->journal_date,
            'branch_id'    => (int) $row->branch_id,
            'receiving_id' => (int) $row->source_id,
            'amount'       => bcadd((string) $row->amount, '0', self::SCALE),
            'in_open_year' => $year !== null && $row->journal_date >= $year['start'] && $row->journal_date <= $year['end'],
        ], $rows);
    }

    /**
     * What is still to move for each branch: what the journals of the open year put on the cost of
     * sales, less what journals dated in that year moved already. What was moved in an earlier year
     * belongs to the purchases of that year and takes nothing off this one.
     *
     * @return array<int, numeric-string>
     */
    public function pending(string $today): array
    {
        $pending = [];

        foreach ($this->affected($today) as $journal) {
            if ($journal['in_open_year']) {
                $pending[$journal['branch_id']] = bcadd($pending[$journal['branch_id']] ?? '0', $journal['amount'], self::SCALE);
            }
        }

        $year = $this->openYear($today);

        if ($year === null) {
            return [];
        }

        $moved = $this->db->table('accounting_journals j')
            ->select('j.branch_id, SUM(j.total_debit) AS amount', false)
            ->where('j.company_id', CompanyContext::id())
            ->where('j.source_type', self::SOURCE)
            ->where('j.status', JournalStatus::Posted->value)
            ->where('j.journal_date >=', $year['start'])
            ->where('j.journal_date <=', $year['end'])
            ->groupBy('j.branch_id')
            ->get()
            ->getResult();

        foreach ($moved as $row) {
            $branchId = (int) $row->branch_id;

            if (isset($pending[$branchId])) {
                $pending[$branchId] = bcsub($pending[$branchId], (string) $row->amount, self::SCALE);
            }
        }

        return array_filter($pending, static fn (string $amount): bool => bccomp($amount, '0', self::SCALE) > 0);
    }

    /**
     * Posts, dated today, one journal for each branch that still has something to move, in the name of
     * the employee who asked for it, and writes each in the audit log.
     *
     * @return array<int, int> The journal posted for each branch.
     *
     * @throws AccountingException|PostingException
     */
    public function post(string $today, int $employeeId): array
    {
        $posting  = new PostingService($this->db);
        $journals = new DocumentJournals($this->db);

        return $this->transactional(function () use ($today, $employeeId, $posting, $journals): array {
            $purchases = $this->resolver->accountFor(RoutingKey::Purchases);
            $cost      = $this->resolver->accountFor(RoutingKey::CostOfGoodsSold) ?? $this->resolver->systemAccount(RoutingKey::CostOfGoodsSold->defaultSystemKey());

            if ($purchases === null) {
                throw new PostingException(PostingError::RoutingRowCancelled, 'The routing of ' . RoutingKey::Purchases->value . ' is cancelled, so there is no account to move the purchases to.', ['routing_key' => RoutingKey::Purchases->value]);
            }

            $posted = [];

            foreach ($this->pending($today) as $branchId => $amount) {
                $posted[$branchId] = $posting->post(new JournalDraft(
                    date: $today,
                    lines: [new JournalLineDraft($purchases, debit: $amount), new JournalLineDraft($cost, credit: $amount)],
                    sourceType: self::SOURCE,
                    sourceId: $branchId,
                    revision: $journals->nextRevision(self::SOURCE, $branchId),
                    origin: JournalOrigin::User,
                    employeeId: $employeeId,
                    description: 'Purchases not kept in stock moved from the cost of sales',
                    branchId: $branchId,
                ))->journalId;

                (new AuditLog($this->db))->write('journal', $posted[$branchId], 'purchase_costs_reclassified', $employeeId, JournalOrigin::User, ['amount' => $amount, 'from_account_id' => $cost, 'to_account_id' => $purchases], $branchId);
            }

            return $posted;
        });
    }

    /**
     * The accounts the cost of sales was posted to: the one its row is routed to and the one the
     * chart opened for it.
     *
     * @return list<int>
     */
    private function costAccounts(): array
    {
        $rows = $this->db->table('accounting_accounts a')
            ->select('a.account_id')
            ->join('accounting_routing r', "r.account_id = a.account_id AND r.routing_key = '" . RoutingKey::CostOfGoodsSold->value . "'", 'left')
            ->where('a.company_id', CompanyContext::id())
            ->groupStart()->where('a.system_key', RoutingKey::CostOfGoodsSold->defaultSystemKey())->orWhere('r.routing_key IS NOT NULL')->groupEnd()
            ->get()
            ->getResultArray();

        return array_values(array_unique(array_map('intval', array_column($rows, 'account_id'))));
    }

    /**
     * @return array{start: string, end: string}|null
     */
    private function openYear(string $today): ?array
    {
        $year = $this->db->table('accounting_fiscal_years')
            ->select('start_date, end_date')
            ->where('company_id', CompanyContext::id())
            ->where('status', 'open')
            ->where('start_date <=', $today)
            ->where('end_date >=', $today)
            ->get()
            ->getRow();

        return $year === null ? null : ['start' => (string) $year->start_date, 'end' => (string) $year->end_date];
    }
}
