<?php

namespace App\Libraries\Accounting\Bank;

final class ParsedStatement
{
    /**
     * @param list<StatementRow>      $rows
     * @param list<StatementRowError> $errors     The first errors only, up to StatementParser::MAX_REPORTED_ERRORS.
     * @param int                     $errorCount Every row that is not valid.
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $errors,
        public readonly int $errorCount,
        public readonly int $rowCount,
        public readonly string $checksum,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errorCount === 0;
    }
}
