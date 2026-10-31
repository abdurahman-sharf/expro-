<?php

namespace App\Libraries\Accounting\Bank;

final class StatementRowError
{
    public const COLUMNS          = 'columns';
    public const DATE_INVALID     = 'date_invalid';
    public const DATE_MISSING     = 'date_missing';
    public const AMOUNT_INVALID   = 'amount_invalid';
    public const AMOUNT_NEGATIVE  = 'amount_negative';
    public const AMOUNT_PRECISION = 'amount_precision';
    public const AMOUNT_TOO_LARGE = 'amount_too_large';
    public const AMOUNT_MISSING   = 'amount_missing';
    public const AMOUNT_BOTH      = 'amount_both';
    public const TEXT_TOO_LONG    = 'text_too_long';

    public function __construct(
        public readonly int $rowNumber,
        public readonly string $reason,
        public readonly string $value = '',
    ) {
    }
}
