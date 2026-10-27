<?php

namespace App\Libraries\Accounting;

/**
 * What the books say the stock of one location is worth, item by item, beside what the account
 * of the location holds. The two agree when every movement that reached the account was written
 * with its movement, which is what a manual journal to the account, or a movement without its
 * journal, breaks.
 */
final readonly class InventoryValuationLocation
{
    /**
     * @param list<InventoryValuationLine> $lines
     * @param numeric-string               $value          What the movements of the location add up to, shown or not.
     * @param numeric-string               $accountBalance What the account of the location holds.
     */
    public function __construct(
        public int $locationId,
        public string $name,
        public ?int $branchId,
        public ?string $branchCode,
        public ?int $accountId,
        public ?string $accountCode,
        public array $lines,
        public string $value,
        public string $accountBalance,
    ) {
    }

    /**
     * What the account holds beyond what the movements say, which is negative when the movements
     * say more.
     */
    public function difference(): string
    {
        return bcsub($this->accountBalance, $this->value, 4);
    }

    public function ties(): bool
    {
        return bccomp($this->difference(), '0', 4) === 0;
    }

    public function unpostedLines(): int
    {
        return count(array_filter($this->lines, static fn (InventoryValuationLine $line): bool => $line->hasUnpostedUnits()));
    }
}
