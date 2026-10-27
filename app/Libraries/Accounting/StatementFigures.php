<?php

namespace App\Libraries\Accounting;

/**
 * What the accounts of the chart hold, read in the direction of the section they are listed in:
 * assets and expenses by what was debited less what was credited, liabilities, equity and revenue
 * the other way round. The accounts are listed as a tree down to a level, the top of the chart
 * being the first, and the chart is cut off at the fifth. The account of a customer, a supplier, a
 * location or a treasury is listed only when asked for and only when it holds something.
 */
final readonly class StatementFigures
{
    public const MAX_LEVEL = 5;
    private const SCALE    = 4;

    /**
     * @var array<int, int>
     */
    private array $levels;

    /**
     * @param array<int, object>                                                $accounts
     * @param array<int, array{opening: string, debit: string, credit: string}> $totals
     * @param list<AccountType>                                                 $shown
     */
    private function __construct(private array $accounts, private array $totals, private array $shown)
    {
        $this->levels = self::levelsOf($accounts);
    }

    /**
     * @param list<AccountType> $shown The types the statement lists, so that it offers only the levels they reach; every type when empty.
     */
    public static function read(TrialBalance $trialBalance, ReportScope $scope, ReportPeriod $period, bool $leaveOutYearEnd, array $shown = []): self
    {
        $read = $trialBalance->totals($scope, $period, $leaveOutYearEnd);

        return new self($read['accounts'], $read['totals'], $shown);
    }

    /**
     * The deepest level a statement can be asked for: that of the deepest account of the types it
     * lists, and never past the fifth.
     */
    public function deepestLevel(): int
    {
        $levels = [1];

        foreach ($this->levels as $accountId => $level) {
            if ($this->shown === [] || in_array($this->typeOf($accountId), $this->shown, true)) {
                $levels[] = $level;
            }
        }

        return min(self::MAX_LEVEL, max($levels));
    }

    public function levelFor(?int $requested, int $default): int
    {
        return max(1, min($requested === null || $requested < 1 ? $default : $requested, $this->deepestLevel()));
    }

    /**
     * What the accounts of the type at the top of the chart stand at together.
     *
     * @return numeric-string
     */
    public function total(AccountType $type): string
    {
        $total     = '0.0000';
        $direction = $type->normalBalance();

        foreach (array_keys($this->accounts) as $accountId) {
            if ($this->typeOf($accountId) === $type && ! $this->hasParentOfType($accountId, $type)) {
                $total = bcadd($total, $this->net($accountId, $direction), self::SCALE);
            }
        }

        return $total;
    }

    public function section(AccountType $type, int $level, StatementDisplay $display, bool $expandEntities): StatementSection
    {
        $direction = $type->normalBalance();
        $listed    = $this->listed($type, $level, $display, $expandEntities);
        $lines     = [];

        foreach ($this->inTreeOrder($type, $listed) as $accountId) {
            $account = $this->accounts[$accountId];

            $lines[] = new StatementLine(
                $accountId,
                (string) $account->code,
                (string) $account->name,
                (int) $account->is_group === 1,
                $account->entity_type !== null,
                $this->levels[$accountId],
                $this->net($accountId, $direction),
            );
        }

        return new StatementSection($type, $lines, $this->total($type));
    }

    /**
     * @param array<int, object> $accounts
     *
     * @return array<int, int>
     */
    private static function levelsOf(array $accounts): array
    {
        $levels = [];

        foreach (array_keys($accounts) as $accountId) {
            $level    = 1;
            $seen     = [$accountId => true];
            $parentId = self::parentOf($accounts, $accountId);

            while ($parentId !== null && ! isset($seen[$parentId])) {
                $seen[$parentId] = true;
                $level++;
                $parentId = self::parentOf($accounts, $parentId);
            }

            $levels[$accountId] = $level;
        }

        return $levels;
    }

    /**
     * @param array<int, object> $accounts
     */
    private static function parentOf(array $accounts, int $accountId): ?int
    {
        $parent = $accounts[$accountId]->parent_id ?? null;

        return $parent === null || ! isset($accounts[(int) $parent]) ? null : (int) $parent;
    }

    private function typeOf(int $accountId): AccountType
    {
        return AccountType::from((string) $this->accounts[$accountId]->account_type);
    }

    private function hasParentOfType(int $accountId, AccountType $type): bool
    {
        $parentId = self::parentOf($this->accounts, $accountId);

        return $parentId !== null && $this->typeOf($parentId) === $type;
    }

    /**
     * @return numeric-string
     */
    private function net(int $accountId, NormalBalance $direction): string
    {
        $row = $this->totals[$accountId];

        return $direction === NormalBalance::Debit
            ? bcsub($row['debit'], $row['credit'], self::SCALE)
            : bcsub($row['credit'], $row['debit'], self::SCALE);
    }

    private function moved(int $accountId): bool
    {
        return bccomp($this->totals[$accountId]['debit'], '0', self::SCALE) !== 0 || bccomp($this->totals[$accountId]['credit'], '0', self::SCALE) !== 0;
    }

    private function stands(int $accountId): bool
    {
        return bccomp($this->net($accountId, NormalBalance::Debit), '0', self::SCALE) !== 0;
    }

    private function qualifies(int $accountId, StatementDisplay $display, bool $expandEntities): bool
    {
        if ($this->accounts[$accountId]->entity_type !== null) {
            return $expandEntities && $this->moved($accountId) && ($display !== StatementDisplay::HideZero || $this->stands($accountId));
        }

        return match ($display) {
            StatementDisplay::All          => true,
            StatementDisplay::WithActivity => $this->moved($accountId),
            StatementDisplay::HideZero     => $this->stands($accountId),
        };
    }

    /**
     * The accounts of the type that have a line: those that qualify down to the level, and the
     * accounts above them so that each is drawn under its parent.
     *
     * @return array<int, true>
     */
    private function listed(AccountType $type, int $level, StatementDisplay $display, bool $expandEntities): array
    {
        $listed = [];

        foreach (array_keys($this->accounts) as $accountId) {
            if ($this->levels[$accountId] > $level || ! $this->qualifies($accountId, $display, $expandEntities)) {
                continue;
            }

            $current = $accountId;
            $seen    = [];

            while ($current !== null && ! isset($seen[$current])) {
                $seen[$current] = true;

                if ($this->typeOf($current) === $type && ($this->accounts[$current]->entity_type === null || $expandEntities)) {
                    $listed[$current] = true;
                }

                $current = self::parentOf($this->accounts, $current);
            }
        }

        return $listed;
    }

    /**
     * The listed accounts, each followed by the accounts under it, those of one parent in the order
     * of their codes.
     *
     * @param array<int, true> $listed
     *
     * @return list<int>
     */
    private function inTreeOrder(AccountType $type, array $listed): array
    {
        $children = [];

        foreach (array_keys($this->accounts) as $accountId) {
            if ($this->typeOf($accountId) === $type) {
                $children[$this->hasParentOfType($accountId, $type) ? self::parentOf($this->accounts, $accountId) : 0][] = $accountId;
            }
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

            if (isset($listed[$accountId])) {
                $ordered[] = $accountId;
            }

            foreach (array_reverse($children[$accountId] ?? []) as $childId) {
                $stack[] = $childId;
            }
        }

        foreach (array_keys($listed) as $accountId) {
            if (! isset($seen[$accountId])) {
                $ordered[] = $accountId;
            }
        }

        return $ordered;
    }
}
