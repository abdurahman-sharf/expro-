<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\StockMovement;

/**
 * What a document would put in the books, as the live posting would, or why it would put nothing. The
 * digest is what the preview and the approval compare: it covers the document as the builders read
 * it, the journals and stock movements they made of it and whatever is written on the document
 * itself, and nothing that differs between two runs, such as a journal number.
 */
final readonly class BackfillPlan
{
    private const SCALE = 4;

    /**
     * @param list<BackfillStep>   $steps
     * @param array<string, mixed> $stamp What is written on the document when it is approved, by column.
     */
    public function __construct(
        public array $steps,
        public string $source = '',
        public ?BackfillRefusal $refusal = null,
        public string $detail = '',
        public ?string $partyType = null,
        public ?int $partyId = null,
        public array $stamp = [],
    ) {
    }

    public static function refused(BackfillRefusal $refusal, string $source = '', string $detail = ''): self
    {
        return new self([], $source, $refusal, $detail);
    }

    public function isReady(): bool
    {
        return $this->refusal === null;
    }

    public function journalCount(): int
    {
        return count(array_filter($this->steps, static fn (BackfillStep $step): bool => $step->draft !== null));
    }

    /**
     * @return numeric-string
     */
    public function total(): string
    {
        $total = '0.0000';

        foreach ($this->steps as $step) {
            foreach ($step->draft?->lines ?? [] as $line) {
                $total = bcadd($total, $line->debit, self::SCALE);
            }
        }

        return $total;
    }

    public function digest(): string
    {
        return hash('sha256', (string) json_encode([
            'source'  => $this->source,
            'refusal' => $this->refusal?->value,
            'detail'  => $this->detail,
            'stamp'   => $this->stamp,
            'party'   => [$this->partyType, $this->partyId],
            'steps'   => array_map(fn (BackfillStep $step): array => [$this->draftOf($step->draft), $this->stockOf($step->stock)], $this->steps),
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @return list<mixed>|null
     */
    private function draftOf(?JournalDraft $draft): ?array
    {
        if ($draft === null) {
            return null;
        }

        return [
            $draft->sourceType,
            $draft->sourceId,
            $draft->entryType->value,
            $draft->revision,
            $draft->date,
            $draft->branchId,
            $draft->description,
            array_map(static fn (JournalLineDraft $line): array => [$line->accountId, bcadd($line->debit, '0', self::SCALE), bcadd($line->credit, '0', self::SCALE), $line->partyType, $line->partyId], $draft->lines),
        ];
    }

    /**
     * @return list<mixed>|null
     */
    private function stockOf(?BackfillStock $stock): ?array
    {
        if ($stock === null) {
            return null;
        }

        return [
            $stock->source,
            $stock->sourceId,
            $stock->entryType->value,
            $stock->revision,
            $stock->date,
            $stock->branchId,
            array_map(static fn (StockMovement $movement): array => [$movement->itemId, $movement->locationId, bcadd($movement->quantity, '0', 3), bcadd($movement->value, '0', self::SCALE)], $stock->movements),
        ];
    }
}
