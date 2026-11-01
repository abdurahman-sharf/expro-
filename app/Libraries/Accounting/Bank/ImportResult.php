<?php

namespace App\Libraries\Accounting\Bank;

final class ImportResult
{
    public const IMPORTED        = 'imported';
    public const DUPLICATE_FILE  = 'duplicate_file';
    public const DUPLICATE_LINES = 'duplicate_lines';

    public function __construct(
        public readonly string $status,
        public readonly ?int $statementId,
        public readonly int $rowCount,
        public readonly int $imported,
        public readonly int $duplicates,
    ) {
    }

    public function created(): bool
    {
        return $this->status === self::IMPORTED;
    }
}
