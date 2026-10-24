<?php

namespace App\Libraries\Accounting;

/**
 * What posting the opening stock of a stock location would do, and why it would not when it
 * would not.
 */
final readonly class OpeningStockPlan
{
    /**
     * @param list<OpeningStockLine>    $lines    What posting would add now.
     * @param list<OpeningStockFinding> $findings
     * @param list<OpeningStockLine>    $standing What the opening stock that stands has posted.
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
    ) {
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
        return new self($this->locationId, $this->locationName, $this->branchId, $status, $this->lines, $this->findings, $this->activeRevision, $this->standing);
    }
}
