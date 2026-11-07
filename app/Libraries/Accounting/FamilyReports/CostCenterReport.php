<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\TrialBalance;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the journal lines of the books come to for each cost center, by center, by main center, by
 * account or by day, week, month or year. A line counts to the rows of its distribution; a line
 * with none counts in full to its single cost center; a line with neither counts under no cost
 * center. The amounts of the shares add up to the line, so the centers and the lines with none add
 * up to the trial balance of the same branches and days; a distribution over 100 percent counts
 * more than its line, and the excess is a row of its own so that the total still ties. It reads the
 * books and writes nothing. The columns are an assumption: no cost center existed in the account
 * the reference system was observed in.
 */
final class CostCenterReport implements FamilyReport
{
    use BuildsReports;

    public const GROUPS = ['center', 'main', 'account', 'day', 'week', 'month', 'year'];

    private BaseConnection $db;
    private TrialBalance $trialBalance;
    private ControlAccounts $accounts;
    private CostCenterService $costCenters;

    public function __construct(?BaseConnection $db = null, ?TrialBalance $trialBalance = null, ?ControlAccounts $accounts = null, ?CostCenterService $costCenters = null)
    {
        $this->db           = $db ?? Database::connect();
        $this->costCenters  = $costCenters ?? new CostCenterService($this->db);
        $this->trialBalance = $trialBalance ?? new TrialBalance($this->db);
        $this->accounts     = $accounts ?? new ControlAccounts($this->db);
    }

    public function build(ReportInput $input): ReportTable
    {
        $group    = $input->choice('group', self::GROUPS, 'center');
        $period   = $input->period();
        $account  = $input->id('account_id');
        $center   = $input->id('cost_center_id');
        $without  = $input->flag('without_cost_center');
        $centers  = $this->centers();
        $narrowed = $center > 0 && ! $without;
        $accounts = $this->accountLabels();
        $under    = $account > 0 ? $this->accounts->under($account) : [];
        $within   = $narrowed ? $this->costCenters->descendantsOf($center) : [];
        $lines    = $this->read($input->scope, $period, $group, $under, $within, $without, $input->journalOwner);
        $excess   = $narrowed || $without ? [] : $this->excess($input->scope, $period, $group, $under, $input->journalOwner);

        $sets = [];

        foreach ($lines as $line) {
            $centerId = $line->center_id === null ? 0 : (int) $line->center_id;

            [$key, $order, $title] = $this->placement($group, $centerId, (int) $line->account_id, (string) $line->bucket, $centers, $accounts);

            $sets[$key] ??= ['order' => $order, 'title' => $title, 'rows' => []];
            $sets[$key]['rows'][] = [
                'sort' => [$centerId === 0 ? 1 : 0, $centerId === 0 ? '' : $centers[$centerId]['code'], $accounts[(int) $line->account_id]['code']],
                'row'  => $this->row($centerId === 0 ? null : $centers[$centerId], $accounts[(int) $line->account_id], (string) $line->debit, (string) $line->credit),
            ];
        }

        foreach ($excess as $line) {
            [$key, $order, $title] = in_array($group, ['center', 'main'], true)
                ? ['excess', [2, ''], $this->text('cost_center_excess')]
                : $this->placement($group, 0, (int) $line->account_id, (string) $line->bucket, $centers, $accounts);

            $sets[$key] ??= ['order' => $order, 'title' => $title, 'rows' => []];
            $sets[$key]['rows'][] = [
                'sort' => [2, '', $accounts[(int) $line->account_id]['code']],
                'row'  => new ReportRow([
                    'center'  => $this->text('cost_center_excess'),
                    'main'    => '',
                    'account' => $accounts[(int) $line->account_id]['label'],
                    'debit'   => $this->amount(bcsub('0', (string) $line->over_debit, SaleAmounts::SCALE)),
                    'credit'  => $this->amount(bcsub('0', (string) $line->over_credit, SaleAmounts::SCALE)),
                    'balance' => $this->amount(bcsub((string) $line->over_credit, (string) $line->over_debit, SaleAmounts::SCALE)),
                ]),
            ];
        }

        uasort($sets, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        $blocks = [];
        $all    = [];

        foreach ($sets as $set) {
            usort($set['rows'], static fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);

            $rows     = array_column($set['rows'], 'row');
            $all      = [...$all, ...$rows];
            $blocks[] = new ReportBlock($set['title'], $rows, $this->totalRow($rows, ['debit', 'credit', 'balance'], 'center', $this->text('total')));
        }

        $grand = $this->totalRow($all, ['debit', 'credit', 'balance'], 'center', $this->text('grand_total'));
        $notes = [$this->text('note_cc_assumption'), $this->text('note_cc_rule')];
        $ties  = [];

        $this->noteIfAny($notes, $excess !== [], 'note_cc_excess');

        if ($narrowed || $without || $input->journalOwner !== null) {
            $notes[] = $this->text('note_cc_narrowed');
        } elseif ($account > 0) {
            $held   = $this->accounts->movement($under, $input->scope, $period->from, $period->to);
            $ties[] = new TieCheck($this->text('tie_cc_account'), bcsub($grand->cell('debit'), $grand->cell('credit'), SaleAmounts::SCALE), $held);
        } else {
            $totals = $input->scope->isEmpty() ? ['debit' => '0.0000', 'credit' => '0.0000'] : $this->trialBalance->build($input->scope, $period)->totals;
            $ties[] = new TieCheck($this->text('tie_cc_debit'), $grand->cell('debit'), $totals['debit']);
            $ties[] = new TieCheck($this->text('tie_cc_credit'), $grand->cell('credit'), $totals['credit']);
        }

        $columns = [
            $this->column('center', ColumnKind::Text, 'col_cost_center'), $this->column('main', ColumnKind::Text, 'col_main_center'), $this->column('account'),
            $this->column('debit', ColumnKind::Amount), $this->column('credit', ColumnKind::Amount), $this->column('balance', ColumnKind::Amount),
        ];

        return new ReportTable($this->text('cost_centers_title'), $columns, $blocks, $grand, $ties, $notes);
    }

    /**
     * @param list<int> $accountIds The accounts to read, none for all.
     * @param list<int> $centerIds  The cost centers to read, none for all.
     *
     * @return list<object>
     */
    private function read(ReportScope $scope, ReportPeriod $period, string $group, array $accountIds, array $centerIds, bool $without, ?int $owner): array
    {
        $builder = $this->base($scope, $period, $accountIds, $owner);

        if ($builder === null) {
            return [];
        }

        $builder->select(
            $this->bucket($group) . ' AS bucket, COALESCE(d.cost_center_id, l.cost_center_id) AS center_id, l.account_id, '
            . 'SUM(IF(d.line_id IS NULL, l.debit, IF(l.debit > 0, d.amount, 0))) AS debit, '
            . 'SUM(IF(d.line_id IS NULL, l.credit, IF(l.credit > 0, d.amount, 0))) AS credit',
            false,
        )->join('accounting_journal_line_distributions d', 'd.line_id = l.line_id', 'left');

        if ($without) {
            $builder->where('COALESCE(d.cost_center_id, l.cost_center_id) IS NULL', null, false);
        } elseif ($centerIds !== []) {
            $builder->where('COALESCE(d.cost_center_id, l.cost_center_id) IN (' . implode(', ', $centerIds) . ')', null, false);
        }

        return $builder->groupBy('bucket, center_id, l.account_id')->get()->getResult();
    }

    /**
     * @param list<int> $accountIds
     *
     * @return list<object>
     */
    private function excess(ReportScope $scope, ReportPeriod $period, string $group, array $accountIds, ?int $owner): array
    {
        $builder = $this->base($scope, $period, $accountIds, $owner);

        if ($builder === null) {
            return [];
        }

        $distributions = $this->db->prefixTable('accounting_journal_line_distributions');

        return $builder
            ->select($this->bucket($group) . ' AS bucket, l.account_id, SUM(IF(l.debit > 0, x.total - l.debit, 0)) AS over_debit, SUM(IF(l.credit > 0, x.total - l.credit, 0)) AS over_credit', false)
            ->join("(SELECT line_id, SUM(amount) AS total FROM {$distributions} GROUP BY line_id) x", 'x.line_id = l.line_id', 'inner', false)
            ->where('x.total > l.debit + l.credit', null, false)
            ->groupBy('bucket, l.account_id')
            ->get()
            ->getResult();
    }

    /**
     * @param list<int> $accountIds
     */
    private function base(ReportScope $scope, ReportPeriod $period, array $accountIds, ?int $owner): ?BaseBuilder
    {
        if ($scope->isEmpty()) {
            return null;
        }

        $builder = $this->db->table('accounting_journal_lines l')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->where('j.journal_date <=', $period->to);

        if ($period->from !== null) {
            $builder->where('j.journal_date >=', $period->from);
        }

        if ($accountIds !== []) {
            $builder->whereIn('l.account_id', $accountIds);
        }

        if ($owner !== null) {
            $builder->where('j.created_by', $owner);
        }

        return $builder;
    }

    private function bucket(string $group): string
    {
        return match ($group) {
            'day'   => 'j.journal_date',
            'week'  => 'DATE_SUB(j.journal_date, INTERVAL WEEKDAY(j.journal_date) DAY)',
            'month' => "DATE_FORMAT(j.journal_date, '%Y-%m-01')",
            'year'  => "CONCAT(YEAR(j.journal_date), '-01-01')",
            default => "''",
        };
    }

    /**
     * @return array<int, array{code: string, label: string, main: int, main_label: string}>
     */
    private function centers(): array
    {
        $found = [];

        foreach ($this->db->table('accounting_cost_centers')->select('cost_center_id, parent_id, code, name')->get()->getResult() as $center) {
            $found[(int) $center->cost_center_id] = ['parent' => $center->parent_id === null ? null : (int) $center->parent_id, 'code' => (string) $center->code, 'label' => $center->code . ' - ' . $center->name];
        }

        $centers = [];

        foreach ($found as $id => $center) {
            $main = $id;

            for ($guard = 0; $found[$main]['parent'] !== null && isset($found[$found[$main]['parent']]) && $guard < 100; $guard++) {
                $main = $found[$main]['parent'];
            }

            $centers[$id] = ['code' => $center['code'], 'label' => $center['label'], 'main' => $main, 'main_label' => $main === $id ? '' : $found[$main]['label']];
        }

        return $centers;
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    private function accountLabels(): array
    {
        $found = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, code, name')->where('company_id', CompanyContext::id())->get()->getResult() as $row) {
            $found[(int) $row->account_id] = ['code' => (string) $row->code, 'label' => $row->code . ' ' . $row->name];
        }

        return $found;
    }

    /**
     * @param array{label: string, main_label: string}|null $center
     * @param array{label: string}                          $account
     */
    private function row(?array $center, array $account, string $debit, string $credit): ReportRow
    {
        return new ReportRow([
            'center'  => $center === null ? $this->text('cost_center_none') : $center['label'],
            'main'    => $center === null ? '' : $center['main_label'],
            'account' => $account['label'],
            'debit'   => $this->amount($debit),
            'credit'  => $this->amount($credit),
            'balance' => $this->amount(bcsub($debit, $credit, SaleAmounts::SCALE)),
        ]);
    }

    /**
     * The block a row belongs to: its key, what it sorts by and its title.
     *
     * @param array<int, array{code: string, label: string, main: int}> $centers
     * @param array<int, array{code: string, label: string}>            $accounts
     *
     * @return array{0: string, 1: array{0: int, 1: string}, 2: string}
     */
    private function placement(string $group, int $centerId, int $accountId, string $bucket, array $centers, array $accounts): array
    {
        $none = ['none', [1, ''], $this->text('cost_center_none')];

        return match ($group) {
            'center'  => $centerId === 0 ? $none : ["c{$centerId}", [0, $centers[$centerId]['code']], $centers[$centerId]['label']],
            'main'    => $centerId === 0 ? $none : ['c' . $centers[$centerId]['main'], [0, $centers[$centers[$centerId]['main']]['code']], $centers[$centers[$centerId]['main']]['label']],
            'account' => ["a{$accountId}", [0, $accounts[$accountId]['code']], $accounts[$accountId]['label']],
            default   => [$bucket, [0, $bucket], PeriodUnit::from($group)->label($bucket)],
        };
    }
}
