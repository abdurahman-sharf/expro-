<?php

namespace App\Libraries\Accounting;

/**
 * The stock the books hold, location by location, for the branches of a scope and a day. Besides
 * the locations it carries what the stock accounts hold as a whole, so that value posted to the
 * main stock account and not to a location is seen too.
 */
final readonly class InventoryValuationReport
{
    /**
     * @param list<InventoryValuationLocation> $locations
     * @param numeric-string|null              $stockAccountBalance What the main stock account holds with everything under it, or null when the chart has none.
     */
    public function __construct(
        public array $locations,
        public ReportScope $scope,
        public ?string $asOf,
        public bool $current,
        public ?string $stockAccountCode,
        public ?string $stockAccountBalance,
        public string $movementsValue,
    ) {
    }

    /**
     * @return numeric-string What the movements of every location add up to.
     */
    public function value(): string
    {
        return $this->movementsValue;
    }

    public function stockAccountDifference(): ?string
    {
        return $this->stockAccountBalance === null ? null : bcsub($this->stockAccountBalance, $this->movementsValue, 4);
    }

    public function unpostedLines(): int
    {
        return array_sum(array_map(static fn (InventoryValuationLocation $location): int => $location->unpostedLines(), $this->locations));
    }

    public function lineCount(): int
    {
        return array_sum(array_map(static fn (InventoryValuationLocation $location): int => count($location->lines), $this->locations));
    }

    public function ties(): bool
    {
        foreach ($this->locations as $location) {
            if (! $location->ties()) {
                return false;
            }
        }

        $difference = $this->stockAccountDifference();

        return $difference === null || bccomp($difference, '0', 4) === 0;
    }
}
