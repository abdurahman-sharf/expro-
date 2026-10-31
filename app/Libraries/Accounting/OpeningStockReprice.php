<?php

namespace App\Libraries\Accounting;

/**
 * What posting the opening stock of a location again at the cost its items carry now would do:
 * the units that stand, each at the cost it was posted at and at the cost it would have, and
 * what stops it. It can go ahead only when nothing stops it, and the fingerprint tells whoever
 * confirms it whether what they saw is still what would be posted.
 */
final readonly class OpeningStockReprice
{
    /**
     * @param list<OpeningStockRepriceLine>  $lines
     * @param list<OpeningStockRepriceBlock> $blocks
     */
    public function __construct(
        public int $locationId,
        public string $locationName,
        public int $branchId,
        public ?int $revision,
        public ?string $date,
        public array $lines,
        public array $blocks,
        public int $laterMovements = 0,
    ) {
    }

    public function allowed(): bool
    {
        return $this->blocks === [];
    }

    public function blockedBy(OpeningStockRepriceBlock $block): bool
    {
        return in_array($block, $this->blocks, true);
    }

    /**
     * @return numeric-string
     */
    public function before(): string
    {
        return $this->sum(static fn (OpeningStockRepriceLine $line): string => $line->valueBefore);
    }

    /**
     * @return numeric-string
     */
    public function after(): string
    {
        return $this->sum(static fn (OpeningStockRepriceLine $line): string => $line->valueAfter);
    }

    /**
     * @return numeric-string
     */
    public function change(): string
    {
        return bcsub($this->after(), $this->before(), StockLedger::VALUE_SCALE);
    }

    public function fingerprint(): string
    {
        return hash('sha256', (string) json_encode([
            $this->locationId,
            $this->revision,
            $this->date,
            array_map(static fn (OpeningStockRepriceLine $line): array => [$line->itemId, $line->units, $line->costBefore, $line->costAfter, $line->valueAfter], $this->lines),
        ]));
    }

    /**
     * @param callable(OpeningStockRepriceLine): string $pick
     *
     * @return numeric-string
     */
    private function sum(callable $pick): string
    {
        $total = '0';

        foreach ($this->lines as $line) {
            $total = bcadd($total, $pick($line), StockLedger::VALUE_SCALE);
        }

        return bcadd($total, '0', StockLedger::VALUE_SCALE);
    }
}
