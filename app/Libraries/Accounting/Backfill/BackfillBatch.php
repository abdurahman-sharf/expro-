<?php

namespace App\Libraries\Accounting\Backfill;

final readonly class BackfillBatch
{
    /**
     * @param list<BackfillFamily> $families
     */
    public function __construct(
        public int $id,
        public string $name,
        public BackfillStatus $status,
        public string $from,
        public string $to,
        public ?int $branchId,
        public array $families,
        public int $chunkSize,
        public ?BackfillFamily $cursorFamily,
        public int $cursorId,
        public ?string $fingerprint,
        public int $documentCount,
        public int $readyCount,
        public int $refusedCount,
        public int $journalCount,
        public string $totalDebit,
        public int $approvedCount,
        public ?int $createdBy,
        public string $createdAt,
        public ?int $decidedBy,
        public ?string $decidedAt,
        public ?string $branchCode = null,
    ) {
    }

    public static function fromRow(object $row): self
    {
        $families = [];

        foreach (explode(',', (string) $row->families) as $value) {
            $family = BackfillFamily::tryFrom($value);

            if ($family !== null) {
                $families[] = $family;
            }
        }

        return new self(
            id: (int) $row->batch_id,
            name: (string) $row->name,
            status: BackfillStatus::from((string) $row->status),
            from: (string) $row->start_date,
            to: (string) $row->end_date,
            branchId: $row->branch_id === null ? null : (int) $row->branch_id,
            families: $families,
            chunkSize: (int) $row->chunk_size,
            cursorFamily: $row->cursor_family === null ? null : BackfillFamily::tryFrom((string) $row->cursor_family),
            cursorId: (int) $row->cursor_id,
            fingerprint: $row->fingerprint === null ? null : (string) $row->fingerprint,
            documentCount: (int) $row->document_count,
            readyCount: (int) $row->ready_count,
            refusedCount: (int) $row->refused_count,
            journalCount: (int) $row->journal_count,
            totalDebit: bcadd((string) $row->total_debit, '0', 4),
            approvedCount: (int) $row->approved_count,
            createdBy: $row->created_by === null ? null : (int) $row->created_by,
            createdAt: (string) $row->created_at,
            decidedBy: $row->decided_by === null ? null : (int) $row->decided_by,
            decidedAt: $row->decided_at === null ? null : (string) $row->decided_at,
            branchCode: isset($row->branch_code) ? (string) $row->branch_code : null,
        );
    }

    public function scope(): BackfillScope
    {
        return new BackfillScope($this->from, $this->to, $this->branchId, $this->families);
    }

    public function isCollected(): bool
    {
        return $this->status !== BackfillStatus::Collecting;
    }
}
