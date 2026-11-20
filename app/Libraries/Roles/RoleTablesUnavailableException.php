<?php

namespace App\Libraries\Roles;

use RuntimeException;
use Throwable;

/**
 * Whether the tables of roles are there, or what they hold for an employee (the permissions their
 * roles give, their default location), could not be read. It is not one of the exceptions the
 * screens turn into a refusal message: ScopeUnavailableHandler answers the request with a notice,
 * and the framework logs the failure with its causes.
 */
final class RoleTablesUnavailableException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
