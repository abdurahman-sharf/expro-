<?php

namespace App\Libraries\Accounting;

/**
 * What posting the opening stock of a stock location would do, and why it would not when it
 * would not. The lines are the units that stand on the shelves and that no movement accounts
 * for. The differences are the items whose movements account for more than the shelves hold, or
 * whose shelves are negative: they are left out, and a person may choose to post them too, as
 * negative lines, so that the books say what the shelves say.
 */
final readonly class OpeningStockPlan
{
    /**
     * @param list<OpeningStockLine>    $lines       What posting would add now.
     * @param list<OpeningStockFinding> $findings
     * @param list<OpeningStockLine>    $standing    What the opening stock that stands has posted.
     * @param list<OpeningStockLine>    $differences What would be posted for the items left out, with the sign of their units.
     */
    public function __construct(
        public int $locationId,
        public string $locationName,
        public int $branchId,
        public OpeningStockStatus $status,
        public array $lines,
        public array $findings,
        public ?int $activeRevision,
        public array $standing = [],
        public array $differences = [],
    ) {
    }

    public function hasDifferences(): bool
    {
        return $this->differences !== [];
    }

    public function canSettleDifferences(): bool
    {
        if ($this->differences === [] || $this->activeRevision !== null) {
            return false;
        }

        foreach ($this->findings as $finding) {
            if ($finding->problem->concernsTheLocation()) {
                return false;
            }
        }

        return true;
    }

    public function settled(): self
    {
        $lines = [...$this->lines, ...$this->differences];

        usort($lines, static fn (OpeningStockLine $a, OpeningStockLine $b): int => $a->itemId <=> $b->itemId);

        return new self($this->locationId, $this->locationName, $this->branchId, OpeningStockStatus::Postable, $lines, [], $this->activeRevision, $this->standing);
    }

    /**
     * @return numeric-string
     */
    public function differenceUnits(): string
    {
        $units = '0';

        foreach ($this->differences as $line) {
            $units = bcadd($units, $line->units, StockLedger::QUANTITY_SCALE);
        }

        return $units;
    }

    /**
     * @return numeric-string
     */
    public function differenceValue(): string
    {
        $value = '0';

        foreach ($this->differences as $line) {
            $value = bcadd($value, $line->value, StockLedger::VALUE_SCALE);
        }

        return $value;
    }

    /**
     * @return numeric-string
     */
    public function units(): string
    {
        $units = '0';

        foreach ($this->lines as $line) {
            $units = bcadd($units, $line->units, StockLedger::QUANTITY_SCALE);
        }

        return $units;
    }

    /**
     * @return numeric-string
     */
    public function value(): string
    {
        $value = '0';

        foreach ($this->lines as $line) {
            $value = bcadd($value, $line->value, StockLedger::VALUE_SCALE);
        }

        return $value;
    }

    /**
     * @return numeric-string
     */
    public function postedUnits(): string
    {
        $units = '0';

        foreach ($this->standing as $line) {
            $units = bcadd($units, $line->units, StockLedger::QUANTITY_SCALE);
        }

        return $units;
    }

    /**
     * @return numeric-string
     */
    public function postedValue(): string
    {
        $value = '0';

        foreach ($this->standing as $line) {
            $value = bcadd($value, $line->value, StockLedger::VALUE_SCALE);
        }

        return $value;
    }

    public function with(OpeningStockStatus $status): self
    {
        return new self($this->locationId, $this->locationName, $this->branchId, $status, $this->lines, $this->findings, $this->activeRevision, $this->standing, $this->differences);
    }
}
