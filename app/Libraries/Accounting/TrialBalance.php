<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What every account of the chart holds at the end of a period, and how it got there: where it
 * stood when the period opened, what was debited and credited in it, and where it stands now. It
 * reads the journals that are in the books, a journal that was reversed with its reversal, so a
 * reversal leaves the two of them adding up to nothing. A group holds its own lines and those of
 * every account under it. The accounts of customers, suppliers, stock locations and treasuries
 * are folded into the account they sit under unless they are asked for, because there can be a
 * thousand of them.
 *
 * The journals of one branch balance on their own, so the report balances for any set of branches.
 */
final class TrialBalance
{
    private const SCALE = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param bool $expandEntities Whether the account of each customer, supplier, location and treasury has a row.
     * @param bool $showEmpty      Whether accounts that never held anything have a row.
     */
    public function build(ReportScope $scope, ReportPeriod $period, bool $expandEntities = false, bool $showEmpty = false, ?TrialBalanceFilter $filter = null): TrialBalanceReport
    {
        $filter ??= new TrialBalanceFilter();
        $amounts  = $scope->isEmpty() ? [] : $this->amounts($scope, $period);
        $accounts = $this->accounts();
        $totals   = $this->rollUp($accounts, $amounts);
        $shown    = $this->shown($accounts, $totals, $amounts, $expandEntities, $showEmpty, $filter);

        return new TrialBalanceReport(
            $this->rows($accounts, $totals, $shown),
            $this->grandTotals($accounts, $totals, $shown, $filter->isLimitedToAccounts()),
            $scope,
            $period->from,
            $period->to,
            $scope->isCompany() ? (new InterBranchPosition($this->db))->difference($period->to) : null,
            $filter->isLimitedToAccounts(),
        );
    }

    /**
     * The accounts a run can be limited to by name: every account of the chart except the account of
     * a customer, a supplier, a location or a treasury, of which there can be a thousand.
     *
     * @return list<object{account_id: int, code: string, name: string, is_group: bool}>
     */
    public function choices(): array
    {
        $choices = [];

        foreach ($this->accounts() as $account) {
            if ($account->entity_type === null) {
                $choices[] = (object) ['account_id' => (int) $account->account_id, 'code' => (string) $account->code, 'name' => (string) $account->name, 'is_group' => (int) $account->is_group === 1];
            }
        }

        return $choices;
    }

    /**
     * What every account holds in the period with the accounts under it, for the statements that
     * are drawn from the same figures as the trial balance.
     *
     * @param bool $leaveOutYearEnd Whether the journals that close a year are left out.
     *
     * @return array{accounts: array<int, object>, totals: array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}>}
     */
    public function totals(ReportScope $scope, ReportPeriod $period, bool $leaveOutYearEnd = false): array
    {
        $accounts = $this->accounts();

        return [
            'accounts' => $accounts,
            'totals'   => $this->rollUp($accounts, $scope->isEmpty() ? [] : $this->amounts($scope, $period, $leaveOutYearEnd)),
        ];
    }

    /**
     * @return array<int, object> The accounts of the company by id, in the order of their codes.
     */
    private function accounts(): array
    {
        $accounts = [];

        foreach ($this->db->table('accounting_accounts')->select('account_id, parent_id, code, name, account_type, is_group, entity_type')->where('company_id', CompanyContext::id())->orderBy('code', 'ASC')->get()->getResult() as $account) {
            $accounts[(int) $account->account_id] = $account;
        }

        return $accounts;
    }

    /**
     * What each account holds from its own lines.
     *
     * @return array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}>
     */
    private function amounts(ReportScope $scope, ReportPeriod $period, bool $leaveOutYearEnd = false): array
    {
        $from = $period->from;
        $to   = $period->to;

        $before = $from === null ? '0' : 'IF(j.journal_date < ' . $this->db->escape($from) . ', %s, 0)';
        $within = $from === null ? '%s' : 'IF(j.journal_date >= ' . $this->db->escape($from) . ', %s, 0)';

        $builder = $this->db->table('accounting_journal_lines l')
            ->select(
                'l.account_id, '
                . 'SUM(' . ($from === null ? '0' : sprintf($before, 'l.debit - l.credit')) . ') AS opening, '
                . 'SUM(' . ($from === null ? '0' : sprintf($before, 'l.debit')) . ') AS opening_debit, '
                . 'SUM(' . ($from === null ? '0' : sprintf($before, 'l.credit')) . ') AS opening_credit, '
                . 'SUM(' . sprintf($within, 'l.debit') . ') AS debit, '
                . 'SUM(' . sprintf($within, 'l.credit') . ') AS credit',
                false,
            )
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->where('j.journal_date <=', $to)
            ->groupBy('l.account_id');

        if ($leaveOutYearEnd) {
            YearEndEntries::leaveOut($builder, $this->db);
        }

        $rows = $builder->get()->getResult();

        $amounts = [];

        foreach ($rows as $row) {
            $amounts[(int) $row->account_id] = [
                'opening'        => bcadd((string) $row->opening, '0', self::SCALE),
                'opening_debit'  => bcadd((string) $row->opening_debit, '0', self::SCALE),
                'opening_credit' => bcadd((string) $row->opening_credit, '0', self::SCALE),
                'debit'          => bcadd((string) $row->debit, '0', self::SCALE),
                'credit'         => bcadd((string) $row->credit, '0', self::SCALE),
            ];
        }

        return $amounts;
    }

    /**
     * What each account holds with the accounts under it.
     *
     * @param array<int, object>                                                $accounts
     * @param array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}> $amounts
     *
     * @return array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}>
     */
    private function rollUp(array $accounts, array $amounts): array
    {
        $totals = [];

        foreach (array_keys($accounts) as $accountId) {
            $totals[$accountId] = $amounts[$accountId] ?? ['opening' => '0.0000', 'opening_debit' => '0.0000', 'opening_credit' => '0.0000', 'debit' => '0.0000', 'credit' => '0.0000'];
        }

        // An account comes after the accounts under it in the order of the codes only when the codes
        // follow the chart, which nothing demands, so the way up is walked from every account.
        $rolled = $totals;

        foreach ($amounts as $accountId => $own) {
            $parentId = $this->parentOf($accounts, $accountId);
            $seen     = [$accountId => true];

            while ($parentId !== null && ! isset($seen[$parentId])) {
                $seen[$parentId] = true;

                foreach (['opening', 'opening_debit', 'opening_credit', 'debit', 'credit'] as $measure) {
                    $rolled[$parentId][$measure] = bcadd($rolled[$parentId][$measure], $own[$measure], self::SCALE);
                }

                $parentId = $this->parentOf($accounts, $parentId);
            }
        }

        return $rolled;
    }

    /**
     * @param array<int, object> $accounts
     */
    private function parentOf(array $accounts, int $accountId): ?int
    {
        $parent = $accounts[$accountId]->parent_id ?? null;

        return $parent === null || ! isset($accounts[(int) $parent]) ? null : (int) $parent;
    }

    /**
     * The accounts that have a row. The account of a customer, a supplier, a location or a treasury
     * has one only when it holds something in the branches of the report, so asking for the empty
     * accounts never lists the parties of other branches. A main account asked for lists the parties
     * under it that hold something, and accounts asked for by name are listed alone, parties
     * included only when they hold something. Then the choice of what they hold and the depth cut
     * the list, each account kept with the accounts above it that are listed.
     *
     * @param array<int, object>                                                                                                $accounts
     * @param array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}> $totals
     * @param array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}> $amounts
     *
     * @return array<int, true>
     */
    private function shown(array $accounts, array $totals, array $amounts, bool $expandEntities, bool $showEmpty, TrialBalanceFilter $filter): array
    {
        $touched = [];

        foreach (array_keys($amounts) as $accountId) {
            $parentId = $accountId;

            while ($parentId !== null && ! isset($touched[$parentId])) {
                $touched[$parentId] = true;
                $parentId           = $this->parentOf($accounts, $parentId);
            }
        }

        $named   = $filter->accountIds === [] ? null : array_flip($filter->accountIds);
        $subtree = $filter->accountId === null || $named !== null ? null : $this->subtreeOf($accounts, $filter->accountId);
        $shown   = [];

        foreach ($accounts as $accountId => $account) {
            if ($named !== null && ! isset($named[$accountId])) {
                continue;
            }

            if ($subtree !== null && ! isset($subtree[$accountId])) {
                continue;
            }

            if ($account->entity_type !== null) {
                if (($expandEntities || $subtree !== null || $named !== null) && isset($touched[$accountId])) {
                    $shown[$accountId] = true;
                }

                continue;
            }

            if ($named !== null || $showEmpty || isset($touched[$accountId])) {
                $shown[$accountId] = true;
            }
        }

        if ($filter->display !== StatementDisplay::All) {
            $shown = $this->keepWhatHolds($accounts, $totals, $shown, $filter->display);
        }

        if ($filter->level !== null) {
            $levels = StatementFigures::levelsOf($accounts);
            $shown  = array_filter($shown, static fn (int $accountId): bool => $levels[$accountId] <= $filter->level, ARRAY_FILTER_USE_KEY);
        }

        return $shown;
    }

    /**
     * The account asked for and every account under it.
     *
     * @param array<int, object> $accounts
     *
     * @return array<int, true>
     */
    private function subtreeOf(array $accounts, int $accountId): array
    {
        if (! isset($accounts[$accountId])) {
            return [];
        }

        $children = [];

        foreach (array_keys($accounts) as $id) {
            $parentId = $this->parentOf($accounts, $id);

            if ($parentId !== null) {
                $children[$parentId][] = $id;
            }
        }

        $found = [$accountId => true];
        $queue = [$accountId];

        while ($queue !== []) {
            foreach ($children[array_pop($queue)] ?? [] as $childId) {
                if (! isset($found[$childId])) {
                    $found[$childId] = true;
                    $queue[]         = $childId;
                }
            }
        }

        return $found;
    }

    /**
     * The listed accounts that had a line in the period, or that stand at something, each with the
     * listed accounts above it.
     *
     * @param array<int, object>                                                                                                $accounts
     * @param array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}> $totals
     * @param array<int, true>                                                                                                  $listed
     *
     * @return array<int, true>
     */
    private function keepWhatHolds(array $accounts, array $totals, array $listed, StatementDisplay $display): array
    {
        $kept = [];

        foreach (array_keys($listed) as $accountId) {
            $row = $totals[$accountId];

            $holds = $display === StatementDisplay::WithActivity
                ? bccomp($row['debit'], '0', self::SCALE) !== 0 || bccomp($row['credit'], '0', self::SCALE) !== 0
                : bccomp(bcsub(bcadd($row['opening'], $row['debit'], self::SCALE), $row['credit'], self::SCALE), '0', self::SCALE) !== 0;

            if (! $holds) {
                continue;
            }

            $current = $accountId;
            $seen    = [];

            while ($current !== null && ! isset($seen[$current])) {
                $seen[$current] = true;

                if (isset($listed[$current])) {
                    $kept[$current] = true;
                }

                $current = $this->parentOf($accounts, $current);
            }
        }

        return $kept;
    }

    /**
     * @param array<int, object>                                                                                                $accounts
     * @param array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}> $totals
     * @param array<int, true>                                                                                                  $shown
     *
     * @return list<TrialBalanceRow>
     */
    private function rows(array $accounts, array $totals, array $shown): array
    {
        $levels = StatementFigures::levelsOf($accounts);
        $rows   = [];

        foreach ($this->inTreeOrder($accounts, $shown) as $accountId) {
            $account = $accounts[$accountId];

            $rows[] = new TrialBalanceRow(
                $accountId,
                (string) $account->code,
                (string) $account->name,
                AccountType::from((string) $account->account_type),
                (int) $account->is_group === 1,
                $account->entity_type !== null,
                $this->depthOf($accounts, $shown, $accountId),
                $totals[$accountId]['opening'],
                $totals[$accountId]['debit'],
                $totals[$accountId]['credit'],
                $totals[$accountId]['opening_debit'],
                $totals[$accountId]['opening_credit'],
                max(1, $levels[$accountId]),
            );
        }

        return $rows;
    }

    /**
     * The accounts that have a row, each followed by the accounts under it, those of one parent in
     * the order of their codes. A code does not always sort beside its parent's, so the order of
     * the codes alone would draw an account under another account's rows.
     *
     * @param array<int, object> $accounts In the order of their codes.
     * @param array<int, true>   $shown
     *
     * @return list<int>
     */
    private function inTreeOrder(array $accounts, array $shown): array
    {
        $children = [];

        foreach (array_keys($accounts) as $accountId) {
            $children[$this->parentOf($accounts, $accountId) ?? 0][] = $accountId;
        }

        $ordered = [];
        $seen    = [];
        $stack   = array_reverse($children[0] ?? []);

        while ($stack !== []) {
            $accountId = array_pop($stack);

            if (isset($seen[$accountId])) {
                continue;
            }

            $seen[$accountId] = true;

            if (isset($shown[$accountId])) {
                $ordered[] = $accountId;
            }

            foreach (array_reverse($children[$accountId] ?? []) as $childId) {
                $stack[] = $childId;
            }
        }

        foreach (array_keys($shown) as $accountId) {
            if (! isset($seen[$accountId])) {
                $ordered[] = $accountId;
            }
        }

        return $ordered;
    }

    /**
     * How many of the accounts above this one have a row.
     *
     * @param array<int, object> $accounts
     * @param array<int, true>   $shown
     */
    private function depthOf(array $accounts, array $shown, int $accountId): int
    {
        $depth    = 0;
        $parentId = $this->parentOf($accounts, $accountId);
        $seen     = [$accountId => true];

        while ($parentId !== null && ! isset($seen[$parentId])) {
            $seen[$parentId] = true;
            $depth += isset($shown[$parentId]) ? 1 : 0;
            $parentId = $this->parentOf($accounts, $parentId);
        }

        return $depth;
    }

    /**
     * The total of the accounts at the top of the chart, each holding everything under it, so that
     * what is listed never changes what is added up. A run limited to some accounts adds up the
     * accounts listed that have no listed account above them.
     *
     * @param array<int, object>                                                                                                $accounts
     * @param array<int, array{opening: string, opening_debit: string, opening_credit: string, debit: string, credit: string}> $totals
     * @param array<int, true>                                                                                                  $shown
     *
     * @return array{opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, gross_opening_debit: string, gross_opening_credit: string, gross_closing_debit: string, gross_closing_credit: string}
     */
    private function grandTotals(array $accounts, array $totals, array $shown, bool $limitedToAccounts): array
    {
        $grand = [
            'opening_debit'        => '0.0000',
            'opening_credit'       => '0.0000',
            'debit'                => '0.0000',
            'credit'               => '0.0000',
            'closing_debit'        => '0.0000',
            'closing_credit'       => '0.0000',
            'gross_opening_debit'  => '0.0000',
            'gross_opening_credit' => '0.0000',
            'gross_closing_debit'  => '0.0000',
            'gross_closing_credit' => '0.0000',
        ];

        foreach (array_keys($accounts) as $accountId) {
            $isTop = $limitedToAccounts ? isset($shown[$accountId]) && $this->depthOf($accounts, $shown, $accountId) === 0 : $this->parentOf($accounts, $accountId) === null;

            if (! $isTop) {
                continue;
            }

            $row     = $totals[$accountId];
            $closing = bcadd(bcadd($row['opening'], $row['debit'], self::SCALE), bcsub('0', $row['credit'], self::SCALE), self::SCALE);

            [$openingDebit, $openingCredit] = TrialBalanceRow::sides($row['opening']);
            [$closingDebit, $closingCredit] = TrialBalanceRow::sides($closing);

            $grand['opening_debit']        = bcadd($grand['opening_debit'], $openingDebit, self::SCALE);
            $grand['opening_credit']       = bcadd($grand['opening_credit'], $openingCredit, self::SCALE);
            $grand['debit']                = bcadd($grand['debit'], $row['debit'], self::SCALE);
            $grand['credit']               = bcadd($grand['credit'], $row['credit'], self::SCALE);
            $grand['closing_debit']        = bcadd($grand['closing_debit'], $closingDebit, self::SCALE);
            $grand['closing_credit']       = bcadd($grand['closing_credit'], $closingCredit, self::SCALE);
            $grand['gross_opening_debit']  = bcadd($grand['gross_opening_debit'], $row['opening_debit'], self::SCALE);
            $grand['gross_opening_credit'] = bcadd($grand['gross_opening_credit'], $row['opening_credit'], self::SCALE);
            $grand['gross_closing_debit']  = bcadd($grand['gross_closing_debit'], bcadd($row['opening_debit'], $row['debit'], self::SCALE), self::SCALE);
            $grand['gross_closing_credit'] = bcadd($grand['gross_closing_credit'], bcadd($row['opening_credit'], $row['credit'], self::SCALE), self::SCALE);
        }

        return $grand;
    }
}
