<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The statement of an account: every line posted to it in the branches the report adds up, in the
 * order they happened, with the balance after each. An account that has accounts under it is
 * read with all of them, which is the statement of a customer's control account or of a whole
 * branch of the chart. The journals read are those in the books, a reversed journal with its
 * reversal. A line of the journal that closes a year is among them, and says what it is by its
 * type.
 */
final class AccountLedger
{
    private const SCALE = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @throws AccountingException
     */
    public function build(ReportScope $scope, int $accountId, ReportPeriod $period, int $limit = 100, int $offset = 0): AccountLedgerReport
    {
        $account = $this->db->table('accounting_accounts')->where('company_id', CompanyContext::id())->where('account_id', $accountId)->get()->getRow();

        if ($account === null) {
            throw new AccountingException(AccountingError::ReportAccountMissing, "Account {$accountId} does not exist.", ['account_id' => $accountId]);
        }

        $normal   = NormalBalance::from((string) $account->normal_balance);
        $ids      = $this->withDescendants($accountId);
        $empty    = $scope->isEmpty();
        $opening  = $empty ? '0' : $this->opening($scope, $ids, $period);
        $sums     = $empty ? ['debit' => '0', 'credit' => '0', 'rows' => 0] : $this->sums($scope, $ids, $period);
        $before   = $empty || $offset === 0 ? '0' : $this->net($scope, $ids, $period, $offset, $normal);
        $openSide = $this->inDirection($opening, $normal);
        $running  = bcadd($openSide, $before, self::SCALE);
        $rows     = [];

        foreach ($empty ? [] : $this->lines($scope, $ids, $period, $limit, $offset) as $line) {
            $running = bcadd($running, $this->delta((string) $line->debit, (string) $line->credit, $normal), self::SCALE);
            $rows[]  = [
                'line_id'             => (int) $line->line_id,
                'journal_id'          => (int) $line->journal_id,
                'date'                => (string) $line->journal_date,
                'series'              => $line->series === null ? null : (string) $line->series,
                'number'              => $line->number === null ? null : (string) $line->number,
                'source_type'         => (string) $line->source_type,
                'entry_type'          => (string) $line->entry_type,
                'reverses_journal_id' => $line->reverses_journal_id === null ? null : (int) $line->reverses_journal_id,
                'description'         => (string) ($line->line_description ?? '') !== '' ? (string) $line->line_description : (string) ($line->journal_description ?? ''),
                'debit'               => bcadd((string) $line->debit, '0', self::SCALE),
                'credit'              => bcadd((string) $line->credit, '0', self::SCALE),
                'balance'             => $running,
                'branch_code'         => (string) $line->branch_code,
                'account_code'        => (string) $line->account_code,
                'account_name'        => (string) $line->account_name,
            ];
        }

        $movement = $this->delta($sums['debit'], $sums['credit'], $normal);

        return new AccountLedgerReport(
            $accountId,
            (string) $account->code,
            (string) $account->name,
            $normal,
            (int) $account->is_group === 1,
            count($ids),
            $rows,
            (int) $sums['rows'],
            $openSide,
            bcadd($sums['debit'], '0', self::SCALE),
            bcadd($sums['credit'], '0', self::SCALE),
            bcadd($openSide, $movement, self::SCALE),
            $scope,
            $period,
        );
    }

    /**
     * The accounts a statement can be asked for from a list: every account of the chart except the
     * account of a customer, a supplier, a location or a treasury, of which there can be a
     * thousand, and the one asked for even when it is one of those.
     *
     * @return list<object{account_id: int, code: string, name: string}>
     */
    public function pickable(?int $include = null): array
    {
        $builder = $this->db->table('accounting_accounts')
            ->select('account_id, code, name')
            ->where('company_id', CompanyContext::id())
            ->groupStart()
            ->where('entity_type IS NULL', null, false);

        if ($include !== null) {
            $builder->orWhere('account_id', $include);
        }

        return array_map(
            static fn (object $account): object => (object) ['account_id' => (int) $account->account_id, 'code' => (string) $account->code, 'name' => (string) $account->name],
            $builder->groupEnd()->orderBy('code', 'ASC')->get()->getResult(),
        );
    }

    /**
     * @return list<int> The account and every account under it.
     */
    private function withDescendants(int $accountId): array
    {
        $children = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, parent_id')->where('company_id', CompanyContext::id())->where('parent_id IS NOT NULL', null, false)->get()->getResult() as $row) {
            $children[(int) $row->parent_id][] = (int) $row->account_id;
        }

        $found = [$accountId => true];
        $queue = [$accountId];

        while ($queue !== []) {
            $current = array_pop($queue);

            foreach ($children[$current] ?? [] as $child) {
                if (! isset($found[$child])) {
                    $found[$child] = true;
                    $queue[]       = $child;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param list<int> $ids
     */
    private function base(ReportScope $scope, array $ids, ReportPeriod $period, bool $within = true): BaseBuilder
    {
        $builder = $this->db->table('accounting_journal_lines l')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('l.account_id', $ids)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids);

        if ($within) {
            $builder->where('j.journal_date <=', $period->to);

            if ($period->from !== null) {
                $builder->where('j.journal_date >=', $period->from);
            }
        }

        return $builder;
    }

    /**
     * Debit minus credit of everything before the period.
     *
     * @param list<int> $ids
     *
     * @return numeric-string
     */
    private function opening(ReportScope $scope, array $ids, ReportPeriod $period): string
    {
        if ($period->from === null) {
            return '0';
        }

        $row = $this->base($scope, $ids, $period, false)
            ->select('COALESCE(SUM(l.debit - l.credit), 0) AS net', false)
            ->where('j.journal_date <', $period->from)
            ->get()
            ->getRow();

        return bcadd((string) $row->net, '0', self::SCALE);
    }

    /**
     * @param list<int> $ids
     *
     * @return array{debit: numeric-string, credit: numeric-string, rows: int}
     */
    private function sums(ReportScope $scope, array $ids, ReportPeriod $period): array
    {
        $row = $this->base($scope, $ids, $period)
            ->select('COALESCE(SUM(l.debit), 0) AS debit, COALESCE(SUM(l.credit), 0) AS credit, COUNT(*) AS found', false)
            ->get()
            ->getRow();

        return ['debit' => bcadd((string) $row->debit, '0', self::SCALE), 'credit' => bcadd((string) $row->credit, '0', self::SCALE), 'rows' => (int) $row->found];
    }

    /**
     * What the lines before a page add to, in the direction of the account.
     *
     * @param list<int> $ids
     *
     * @return numeric-string
     */
    private function net(ReportScope $scope, array $ids, ReportPeriod $period, int $count, NormalBalance $normal): string
    {
        $inner = $this->ordered($this->base($scope, $ids, $period)->select('l.debit AS debit, l.credit AS credit', false))->limit($count);
        $row   = $this->db->query('SELECT COALESCE(SUM(t.debit), 0) AS debit, COALESCE(SUM(t.credit), 0) AS credit FROM (' . $inner->getCompiledSelect() . ') t')->getRow();

        return $this->delta((string) $row->debit, (string) $row->credit, $normal);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<object>
     */
    private function lines(ReportScope $scope, array $ids, ReportPeriod $period, int $limit, int $offset): array
    {
        return $this->ordered(
            $this->base($scope, $ids, $period)
                ->select('l.line_id, l.journal_id, l.debit, l.credit, l.description AS line_description, j.description AS journal_description, j.journal_date, j.number, j.series, j.source_type, j.entry_type, j.reverses_journal_id, b.code AS branch_code, a.code AS account_code, a.name AS account_name', false)
                ->join('accounting_accounts a', 'a.account_id = l.account_id')
                ->join('branches b', 'b.branch_id = j.branch_id'),
        )->limit($limit, $offset)->get()->getResult();
    }

    private function ordered(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('j.journal_date', 'ASC')->orderBy('j.journal_id', 'ASC')->orderBy('l.line_no', 'ASC');
    }

    /**
     * @return numeric-string
     */
    private function delta(string $debit, string $credit, NormalBalance $normal): string
    {
        return $normal === NormalBalance::Debit ? bcsub($debit, $credit, self::SCALE) : bcsub($credit, $debit, self::SCALE);
    }

    /**
     * @return numeric-string
     */
    private function inDirection(string $net, NormalBalance $normal): string
    {
        return $normal === NormalBalance::Debit ? bcadd($net, '0', self::SCALE) : bcsub('0', $net, self::SCALE);
    }
}
