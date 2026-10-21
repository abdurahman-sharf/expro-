<?php

namespace App\Libraries\Accounting;

use RuntimeException;

class AccountingNotReadyException extends RuntimeException
{
    /**
     * @param non-empty-list<ReadinessIssue> $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct('Accounting cannot be enabled until its settings are complete.');
    }
}
