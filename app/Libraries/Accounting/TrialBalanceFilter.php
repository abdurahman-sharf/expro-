<?php

namespace App\Libraries\Accounting;

/**
 * What narrows a trial balance: how deep the chart is drawn, which accounts are listed by what they
 * hold, and a main account with the accounts under it or a list of accounts. Depth and the choice of
 * accounts change what is listed and never the total of the chart, but a run limited to some
 * accounts adds up only those, which is why it cannot be expected to balance.
 */
final readonly class TrialBalanceFilter
{
    public const MAX_ACCOUNTS = 200;

    /**
     * @param list<int> $accountIds
     */
    public function __construct(
        public ?int $level = null,
        public StatementDisplay $display = StatementDisplay::All,
        public ?int $accountId = null,
        public array $accountIds = [],
    ) {
    }

    public function isLimitedToAccounts(): bool
    {
        return $this->accountId !== null || $this->accountIds !== [];
    }

    public function isNarrowed(): bool
    {
        return $this->level !== null || $this->display !== StatementDisplay::All || $this->isLimitedToAccounts();
    }

    /**
     * @param mixed $level      What the request holds: a whole number from one up, else every level.
     * @param mixed $accountIds What the request holds: a list of numbers, anything else is none.
     */
    public static function fromRequest(mixed $level, mixed $display, mixed $accountId, mixed $accountIds): self
    {
        $levelNumber   = is_string($level) && ctype_digit($level) && (int) $level >= 1 ? min(StatementFigures::MAX_LEVEL, (int) $level) : null;
        $accountNumber = is_string($accountId) && ctype_digit($accountId) && (int) $accountId > 0 ? (int) $accountId : null;
        $ids           = [];

        foreach (is_array($accountIds) ? $accountIds : [] as $candidate) {
            if (is_string($candidate) && ctype_digit($candidate) && (int) $candidate > 0) {
                $ids[(int) $candidate] = (int) $candidate;
            }
        }

        return new self(
            $levelNumber,
            StatementDisplay::fromRequest(is_string($display) ? $display : null),
            $accountNumber,
            array_slice(array_values($ids), 0, self::MAX_ACCOUNTS),
        );
    }
}
