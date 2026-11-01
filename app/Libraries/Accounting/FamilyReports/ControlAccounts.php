<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The accounts of the books a report is set beside: the main account a routing key names with every
 * account under it, and what those accounts hold in the branches of a scope over a stretch of days.
 */
final class ControlAccounts
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<int>
     */
    public function tree(RoutingKey ...$keys): array
    {
        $roots = [];

        foreach ($keys as $key) {
            $row = $this->db->table('accounting_routing')->select('account_id')->where('company_id', CompanyContext::id())->where('routing_key', $key->value)->get()->getRow();

            if ($row !== null) {
                $roots[] = (int) $row->account_id;
            }
        }

        if ($roots === []) {
            return [];
        }

        $children = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, parent_id')->where('company_id', CompanyContext::id())->where('parent_id IS NOT NULL', null, false)->get()->getResult() as $account) {
            $children[(int) $account->parent_id][] = (int) $account->account_id;
        }

        $found = array_fill_keys($roots, true);
        $queue = $roots;

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

    /**
     * Debit less credit of the accounts over the journals that are in the books, a reversed one
     * with its reversal, between two days, or only the journals of some sources that stand.
     *
     * @param list<int>         $accountIds
     * @param list<string>|null $standingSources The sources of the journals to count, which count only while they stand; null counts every journal.
     *
     * @return numeric-string
     */
    public function movement(array $accountIds, ReportScope $scope, ?string $from, ?string $to, ?array $standingSources = null): string
    {
        if ($accountIds === [] || $scope->isEmpty()) {
            return '0.0000';
        }

        $builder = $this->db->table('accounting_journal_lines l')
            ->select('COALESCE(SUM(l.debit - l.credit), 0) AS balance', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('l.account_id', $accountIds)
            ->whereIn('j.branch_id', $scope->ids);

        if ($standingSources === null) {
            $builder->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value]);
        } else {
            $builder->where('j.status', JournalStatus::Posted->value)->whereIn('j.source_type', $standingSources);
        }

        if ($from !== null) {
            $builder->where('j.journal_date >=', $from);
        }

        if ($to !== null) {
            $builder->where('j.journal_date <=', $to);
        }

        return bcadd((string) $builder->get()->getRow()->balance, '0', SaleAmounts::SCALE);
    }
}
