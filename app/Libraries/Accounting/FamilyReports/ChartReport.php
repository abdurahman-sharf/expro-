<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The chart of accounts as a list: the code, the name, the nature (debit or credit) and the level
 * (main account or sub-account) of each account, which can be cut down to a level, a nature, and leave
 * out the accounts of customers or of suppliers, in the order of the codes. The chart is the same for
 * every branch, so what is listed is not limited by branch. With the balances asked for, each account
 * holds what the journals of the branches read put on it and the leaf accounts add up to nothing.
 */
final class ChartReport implements FamilyReport
{
    use BuildsReports;

    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function build(ReportInput $input): ReportTable
    {
        $level    = $input->choice('level', ['all', 'main', 'sub'], 'all');
        $nature   = $input->choice('nature', ['all', 'debit', 'credit'], 'all');
        $exclude  = $input->choice('exclude', ['none', 'customers', 'suppliers', 'both'], 'none');
        $order    = $input->choice('order', ['asc', 'desc'], 'asc');
        $balances = $input->flag('balances');
        $held     = $balances ? $this->balances($input) : [];

        $builder = $this->db->table('accounting_accounts a')
            ->select('a.account_id, a.code, a.name, a.normal_balance, a.is_group, a.entity_type, a.active')
            ->where('a.company_id', CompanyContext::id())
            ->orderBy('a.code', $order === 'desc' ? 'DESC' : 'ASC');

        if ($level !== 'all') {
            $builder->where('a.is_group', $level === 'main' ? 1 : 0);
        }

        if ($nature !== 'all') {
            $builder->where('a.normal_balance', $nature);
        }

        $excluded = match ($exclude) {
            'customers' => [EntityAccountService::CUSTOMER],
            'suppliers' => [EntityAccountService::SUPPLIER],
            'both'      => [EntityAccountService::CUSTOMER, EntityAccountService::SUPPLIER],
            default     => [],
        };

        if ($excluded !== []) {
            $builder->groupStart()->where('a.entity_type IS NULL', null, false)->orWhereNotIn('a.entity_type', $excluded)->groupEnd();
        }

        $rows = [];

        foreach ($builder->get()->getResult() as $account) {
            $cells = [
                'code'   => (string) $account->code,
                'name'   => (string) $account->name,
                'nature' => $this->text('nature_' . $account->normal_balance),
                'level'  => $this->text((int) $account->is_group === 1 ? 'level_main' : 'level_sub'),
                'state'  => $this->text((int) $account->active === 1 ? 'state_active' : 'state_inactive'),
            ];

            if ($balances) {
                $cells['balance'] = $held[(int) $account->account_id] ?? '0.0000';
            }

            $rows[] = new ReportRow($cells);
        }

        $columns = [$this->column('code'), $this->column('name'), $this->column('nature'), $this->column('level'), $this->column('state')];
        $ties    = [];
        $notes   = [$this->text('note_chart_company')];

        if ($balances) {
            $columns[] = $this->column('balance', ColumnKind::Amount, 'col_balance_debit');
            $leaves    = '0';

            foreach ($held as $balance) {
                $leaves = bcadd($leaves, $balance, SaleAmounts::SCALE);
            }

            $ties[]  = new TieCheck($this->text('tie_chart_zero'), bcadd($leaves, '0', SaleAmounts::SCALE), '0.0000');
            $notes[] = $this->text('note_chart_balances');
        }

        return new ReportTable($this->text('chart_title'), $columns, [new ReportBlock(null, $rows)], null, $ties, $notes);
    }

    /**
     * What the journals of the branches put on each account itself, debit less credit, to the day.
     *
     * @return array<int, string>
     */
    private function balances(ReportInput $input): array
    {
        if ($input->scope->isEmpty()) {
            return [];
        }

        $period = ReportPeriod::of(null, $input->text('to') === '' ? $input->today : $input->text('to'));
        $found  = [];

        foreach ($this->db->table('accounting_journal_lines l')
            ->select('l.account_id, SUM(l.debit - l.credit) AS balance', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $input->scope->ids)
            ->where('j.journal_date <=', $period->to)
            ->groupBy('l.account_id')
            ->get()
            ->getResult() as $row) {
            $found[(int) $row->account_id] = bcadd((string) $row->balance, '0', SaleAmounts::SCALE);
        }

        return $found;
    }
}
