<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The lines of the journals that are in the books of the branches of a scope over a period, a reversed
 * journal and its reversal both, cut down to a source, an account and the accounts under it. A reversal
 * counts as the source of the journal it reverses. A reader who may not read everyone's journals reads
 * their own.
 */
final class JournalLines
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<object>
     */
    public function read(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $accountId = null, ?int $owner = null): array
    {
        $builder = $this->base($scope, $period, $source, $accountId, $owner);

        if ($builder === null) {
            return [];
        }

        return $builder
            ->select('j.journal_id, j.journal_date, j.series, j.number, j.description AS journal_description, j.source_type, j.entry_type, j.status, j.branch_id, l.line_id, l.line_no, l.description AS line_description, a.code AS account_code, a.name AS account_name, l.account_id, l.debit, l.credit')
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC')
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @return list<object>
     */
    public function journals(ReportScope $scope, ReportPeriod $period, ?string $source = null, ?int $owner = null, string $search = ''): array
    {
        $builder = $this->base($scope, $period, $source, null, $owner);

        if ($builder === null) {
            return [];
        }

        if ($search !== '') {
            $builder->like('j.description', $search);
        }

        return $builder
            ->select('j.journal_id, j.journal_date, j.series, j.number, j.description, j.source_type, j.entry_type, j.status, j.branch_id, br.code AS branch_code, j.total_debit')
            ->join('branches br', 'br.branch_id = j.branch_id', 'left')
            ->groupBy('j.journal_id, j.journal_date, j.series, j.number, j.description, j.source_type, j.entry_type, j.status, j.branch_id, br.code, j.total_debit')
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC')
            ->get()
            ->getResult();
    }

    private function base(ReportScope $scope, ReportPeriod $period, ?string $source, ?int $accountId, ?int $owner): ?BaseBuilder
    {
        if ($scope->isEmpty()) {
            return null;
        }

        $builder = $this->db->table('accounting_journal_lines l')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->join('accounting_journals o', 'o.journal_id = j.reverses_journal_id', 'left')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->where('j.journal_date <=', $period->to);

        if ($period->from !== null) {
            $builder->where('j.journal_date >=', $period->from);
        }

        if ($source !== null && $source !== '') {
            $builder->groupStart()->where('j.source_type', $source)->orWhere('o.source_type', $source)->groupEnd();
        }

        if ($accountId !== null && $accountId > 0) {
            $builder->whereIn('l.account_id', $this->subtree($accountId));
        }

        if ($owner !== null) {
            $builder->where('j.created_by', $owner);
        }

        return $builder;
    }

    /**
     * @return list<int>
     */
    private function subtree(int $accountId): array
    {
        $children = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, parent_id')->where('company_id', CompanyContext::id())->where('parent_id IS NOT NULL', null, false)->get()->getResult() as $account) {
            $children[(int) $account->parent_id][] = (int) $account->account_id;
        }

        $found = [$accountId => true];
        $queue = [$accountId];

        while ($queue !== []) {
            foreach ($children[array_pop($queue)] ?? [] as $child) {
                if (! isset($found[$child])) {
                    $found[$child] = true;
                    $queue[]       = $child;
                }
            }
        }

        return array_keys($found);
    }
}
