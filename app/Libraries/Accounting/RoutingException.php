<?php

namespace App\Libraries\Accounting;

/**
 * The routing save found a problem in at least one row. Nothing was written.
 */
final class RoutingException extends AccountingException
{
    /**
     * @param array<string, AccountingError> $errors The problem of each row, by routing key.
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(AccountingError::RoutingInvalid, 'Some routing rows are not valid.', ['count' => count($errors)]);
    }
}
