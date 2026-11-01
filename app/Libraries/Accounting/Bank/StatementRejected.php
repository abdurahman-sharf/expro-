<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;

/**
 * A file with rows that are not valid, with the first of them named by row, reason and text.
 */
final class StatementRejected extends AccountingException
{
    /**
     * @param list<StatementRowError> $errors
     */
    public function __construct(public readonly array $errors, public readonly int $errorCount)
    {
        parent::__construct(AccountingError::BankStatementRowsInvalid, "{$errorCount} row(s) of the file are not valid.", ['count' => $errorCount]);
    }
}
