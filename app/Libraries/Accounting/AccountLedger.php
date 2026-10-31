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
 * branch of the chart, in one list or account by account. The journals read are those in the
 * books, a reversed journal with its reversal. A line of the journal that closes a year is among
 * them, and says what it is by its type. Limited to an employee, every figure of the
 * statement, the opening balance included, is that of the lines the employee added. Each statement is
 * read in one snapshot, so that its lines add up to its totals when the books move while it is read.
 */
final class AccountLedger
{
    use RunsInTransaction;

    public const MAX_BLOCK_ITEMS = 2000;
    private const SCALE          = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null, private readonly int $maxBlockItems = self::MAX_BLOCK_ITEMS)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @throws AccountingException
     */
    public function build(ReportScope $scope, int $accountId, ReportPeriod $period, int $limit = 100, int $offset = 0, ?int $employeeId = null): AccountLedgerReport
    {
        return $this->transactional(fn (): AccountLedgerReport => $this->readStatement($scope, $accountId, $period, $limit, $offset, $employeeId));
    }

    private function readStatement(ReportScope $scope, int $accountId, ReportPeriod $period, int $limit, int $offset, ?int $employeeId): AccountLedgerReport
    {
        $account = $this->account($accountId);
        $normal  = NormalBalance::from((string) $account->normal_balance);
        $ids     = $this->withDescendants($accountId);
        $empty   = $scope->isEmpty();

        $opening  = $empty ? '0' : $this->opening($scope, $ids, $period, $employeeId);
        $sums     = $empty ? ['debit' => '0', 'credit' => '0', 'rows' => 0] : $this->sums($scope, $ids, $period, $employeeId);
        $before   = $empty || $offset === 0 ? '0' : $this->net($scope, $ids, $period, $offset, $normal, $employeeId);
        $openSide = $this->inDirection($opening, $normal);
        $running  = bcadd($openSide, $before, self::SCALE);
        $rows     = [];

        foreach ($empty ? [] : $this->lines($scope, $ids, $period, $limit, $offset, $employeeId) as $line) {
            $running = bcadd($running, $this->delta((string) $line->debit, (string) $line->credit, $normal), self::SCALE);
            $rows[]  = $this->row($line) + ['balance' => $running];
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
            $employeeId,
        );
    }

    /**
     * The statement of an account drawn account by account: a block for each account under it that
     * the choice of display lists, in the order of their codes, and at the end what all of them
     * debited and credited. The account of a party is drawn only when it holds something in the
     * branches of the report, so that the parties of other branches are never named.
     *
     * @throws AccountingException When there are more blocks and lines than the screen can hold.
     */
    public function buildBlocks(ReportScope $scope, int $accountId, ReportPeriod $period, LedgerDisplay $display = LedgerDisplay::Activity, ?int $employeeId = null): AccountLedgerBlocksReport
    {
        return $this->transactional(fn (): AccountLedgerBlocksReport => $this->readBlocks($scope, $accountId, $period, $display, $employeeId));
    }

    /**
     * @throws AccountingException
     */
    private function readBlocks(ReportScope $scope, int $accountId, ReportPeriod $period, LedgerDisplay $display, ?int $employeeId): AccountLedgerBlocksReport
    {
        $account = $this->account($accountId);
        $ids     = $this->withDescendants($accountId);
        $figures = $scope->isEmpty() ? [] : $this->figuresByAccount($scope, $ids, $period, $employeeId);
        $listed  = $this->blockAccounts($ids, $figures, $display);

        $items = count($listed);

        foreach ($listed as $listedAccount) {
            $items += (int) ($figures[(int) $listedAccount->account_id]['found'] ?? 0);
        }

        if ($items > $this->maxBlockItems) {
            throw new AccountingException(AccountingError::LedgerTooLarge, 'The statement is too large to draw account by account.', ['max' => $this->maxBlockItems]);
        }

        $rowsByAccount = [];

        if ($listed !== [] && ! $scope->isEmpty()) {
            foreach ($this->blockLines($scope, array_map(static fn (object $listedAccount): int => (int) $listedAccount->account_id, $listed), $period, $employeeId) as $line) {
                $rowsByAccount[(int) $line->account_id][] = $line;
            }
        }

        $blocks      = [];
        $totalDebit  = '0';
        $totalCredit = '0';

        foreach ($listed as $listedAccount) {
            $listedId = (int) $listedAccount->account_id;
            $own      = $figures[$listedId] ?? ['opening' => '0', 'debit' => '0', 'credit' => '0', 'found' => 0];
            $running  = bcadd($own['opening'], '0', self::SCALE);
            $rows     = [];

            foreach ($rowsByAccount[$listedId] ?? [] as $line) {
                $running = bcadd($running, bcsub((string) $line->debit, (string) $line->credit, self::SCALE), self::SCALE);
                $rows[]  = $this->row($line) + ['balance' => $running];
            }

            $blocks[] = new LedgerBlock(
                $listedId,
                (string) $listedAccount->code,
                (string) $listedAccount->name,
                $listedAccount->entity_type !== null,
                $rows,
                bcadd($own['opening'], '0', self::SCALE),
                bcadd($own['debit'], '0', self::SCALE),
                bcadd($own['credit'], '0', self::SCALE),
                bcadd(bcadd($own['opening'], $own['debit'], self::SCALE), bcsub('0', $own['credit'], self::SCALE), self::SCALE),
            );

            $totalDebit  = bcadd($totalDebit, $own['debit'], self::SCALE);
            $totalCredit = bcadd($totalCredit, $own['credit'], self::SCALE);
        }

        return new AccountLedgerBlocksReport(
            $accountId,
            (string) $account->code,
            (string) $account->name,
            $blocks,
            bcadd($totalDebit, '0', self::SCALE),
            bcadd($totalCredit, '0', self::SCALE),
            $scope,
            $period,
            $display,
            $employeeId,
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
            ->select('account_id, code, name, is_group')
            ->where('company_id', CompanyContext::id())
            ->groupStart()
            ->where('entity_type IS NULL', null, false);

        if ($include !== null) {
            $builder->orWhere('account_id', $include);
        }

        return array_map(
            static fn (object $account): object => (object) ['account_id' => (int) $account->account_id, 'code' => (string) $account->code, 'name' => (string) $account->name, 'is_group' => (int) $account->is_group === 1],
            $builder->groupEnd()->orderBy('code', 'ASC')->get()->getResult(),
        );
    }

    /**
     * The employees who added a journal in the branches of the report, for the choice of whose
     * lines a statement reads.
     *
     * @return list<object{employee_id: int, name: string}>
     */
    public function employees(ReportScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $rows = $this->db->table('accounting_journals j')
            ->select("j.created_by AS employee_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)
            ->join('people p', 'p.person_id = j.created_by')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->groupBy('j.created_by')
            ->orderBy('name', 'ASC')
            ->get()
            ->getResult();

        return array_map(static fn (object $row): object => (object) ['employee_id' => (int) $row->employee_id, 'name' => (string) $row->name], $rows);
    }

    /**
     * @throws AccountingException
     */
    private function account(int $accountId): object
    {
        $account = $this->db->table('accounting_accounts')->where('company_id', CompanyContext::id())->where('account_id', $accountId)->get()->getRow();

        if ($account === null) {
            throw new AccountingException(AccountingError::ReportAccountMissing, "Account {$accountId} does not exist.", ['account_id' => $accountId]);
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $line): array
    {
        return [
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
            'branch_code'         => (string) $line->branch_code,
            'account_code'        => (string) $line->account_code,
            'account_name'        => (string) $line->account_name,
            'employee'            => (string) ($line->employee_name ?? ''),
        ];
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
    private function base(ReportScope $scope, array $ids, ReportPeriod $period, ?int $employeeId, bool $within = true): BaseBuilder
    {
        $builder = $this->db->table('accounting_journal_lines l')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('l.account_id', $ids)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids);

        if ($employeeId !== null) {
            $builder->where('j.created_by', $employeeId);
        }

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
    private function opening(ReportScope $scope, array $ids, ReportPeriod $period, ?int $employeeId): string
    {
        if ($period->from === null) {
            return '0';
        }

        $row = $this->base($scope, $ids, $period, $employeeId, false)
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
    private function sums(ReportScope $scope, array $ids, ReportPeriod $period, ?int $employeeId): array
    {
        $row = $this->base($scope, $ids, $period, $employeeId)
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
    private function net(ReportScope $scope, array $ids, ReportPeriod $period, int $count, NormalBalance $normal, ?int $employeeId): string
    {
        $inner = $this->ordered($this->base($scope, $ids, $period, $employeeId)->select('l.debit AS debit, l.credit AS credit', false))->limit($count);
        $row   = $this->db->query('SELECT COALESCE(SUM(t.debit), 0) AS debit, COALESCE(SUM(t.credit), 0) AS credit FROM (' . $inner->getCompiledSelect() . ') t')->getRow();

        return $this->delta((string) $row->debit, (string) $row->credit, $normal);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<object>
     */
    private function lines(ReportScope $scope, array $ids, ReportPeriod $period, int $limit, int $offset, ?int $employeeId): array
    {
        return $this->ordered($this->withLineColumns($this->base($scope, $ids, $period, $employeeId)))->limit($limit, $offset)->get()->getResult();
    }

    private function withLineColumns(BaseBuilder $builder): BaseBuilder
    {
        return $builder
            ->select("l.line_id, l.account_id, l.journal_id, l.debit, l.credit, l.description AS line_description, j.description AS journal_description, j.journal_date, j.number, j.series, j.source_type, j.entry_type, j.reverses_journal_id, b.code AS branch_code, a.code AS account_code, a.name AS account_name, TRIM(CONCAT(ep.first_name, ' ', ep.last_name)) AS employee_name", false)
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->join('branches b', 'b.branch_id = j.branch_id')
            ->join('people ep', 'ep.person_id = j.created_by', 'left');
    }

    private function ordered(BaseBuilder $builder): BaseBuilder
    {
        return $builder->orderBy('j.journal_date', 'ASC')->orderBy('j.journal_id', 'ASC')->orderBy('l.line_no', 'ASC');
    }

    /**
     * What each account holds in the period: where it stood when the period opened, what it was
     * debited and credited, and how many lines that was. Debit minus credit and not the direction of
     * the account, so that the blocks of accounts of both directions add up in one statement.
     *
     * @param list<int> $ids
     *
     * @return array<int, array{opening: string, debit: string, credit: string, found: int}>
     */
    private function figuresByAccount(ReportScope $scope, array $ids, ReportPeriod $period, ?int $employeeId): array
    {
        $from   = $period->from;
        $before = $from === null ? '0' : 'IF(j.journal_date < ' . $this->db->escape($from) . ', %s, 0)';
        $within = $from === null ? '%s' : 'IF(j.journal_date >= ' . $this->db->escape($from) . ', %s, 0)';

        $rows = $this->base($scope, $ids, $period, $employeeId, false)
            ->select(
                'l.account_id, '
                . 'SUM(' . ($from === null ? '0' : sprintf($before, 'l.debit - l.credit')) . ') AS opening, '
                . 'SUM(' . sprintf($within, 'l.debit') . ') AS debit, '
                . 'SUM(' . sprintf($within, 'l.credit') . ') AS credit, '
                . 'SUM(' . sprintf($within, '1') . ') AS found',
                false,
            )
            ->where('j.journal_date <=', $period->to)
            ->groupBy('l.account_id')
            ->get()
            ->getResult();

        $figures = [];

        foreach ($rows as $row) {
            $figures[(int) $row->account_id] = [
                'opening' => bcadd((string) $row->opening, '0', self::SCALE),
                'debit'   => bcadd((string) $row->debit, '0', self::SCALE),
                'credit'  => bcadd((string) $row->credit, '0', self::SCALE),
                'found'   => (int) $row->found,
            ];
        }

        return $figures;
    }

    /**
     * The accounts that have a block, in the order of their codes. An account with a line in the
     * period always has one, except under the choice of accounts that stand at something, which
     * keeps an account that stood at something when the period opened or stands at something at the
     * end of it. Listing every account adds the accounts that are not groups, but never an account
     * of a party that holds nothing here, which would name the parties of other branches.
     *
     * @param list<int>                                                                     $ids
     * @param array<int, array{opening: string, debit: string, credit: string, found: int}> $figures
     *
     * @return list<object>
     */
    private function blockAccounts(array $ids, array $figures, LedgerDisplay $display): array
    {
        $accounts = $this->db->table('accounting_accounts')
            ->select('account_id, code, name, is_group, entity_type')
            ->where('company_id', CompanyContext::id())
            ->whereIn('account_id', $ids)
            ->orderBy('code', 'ASC')
            ->get()
            ->getResult();

        $listed = [];

        foreach ($accounts as $account) {
            $own      = $figures[(int) $account->account_id] ?? null;
            $moved    = $own !== null && $own['found'] > 0;
            $standing = $own !== null && (bccomp($own['opening'], '0', self::SCALE) !== 0 || bccomp(bcadd($own['opening'], bcsub($own['debit'], $own['credit'], self::SCALE), self::SCALE), '0', self::SCALE) !== 0);

            $keep = match ($display) {
                LedgerDisplay::Activity       => $moved,
                LedgerDisplay::NonZeroBalance => $standing,
                LedgerDisplay::All            => $moved || $standing || ((int) $account->is_group === 0 && $account->entity_type === null),
            };

            if ($keep) {
                $listed[] = $account;
            }
        }

        return $listed;
    }

    /**
     * @param list<int> $accountIds
     *
     * @return list<object>
     */
    private function blockLines(ReportScope $scope, array $accountIds, ReportPeriod $period, ?int $employeeId): array
    {
        return $this->withLineColumns($this->base($scope, $accountIds, $period, $employeeId))
            ->orderBy('a.code', 'ASC')
            ->orderBy('j.journal_date', 'ASC')
            ->orderBy('j.journal_id', 'ASC')
            ->orderBy('l.line_no', 'ASC')
            ->get()
            ->getResult();
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
