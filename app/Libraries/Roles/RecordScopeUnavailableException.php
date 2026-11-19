<?php

namespace App\Libraries\Roles;

use RuntimeException;
use Throwable;

/**
 * The scope of an employee could not be read. It is not one of the exceptions the screens turn into
 * a refusal message: ScopeUnavailableHandler answers the request with a notice, and the framework
 * logs the failure with its causes.
 */
final class RecordScopeUnavailableException extends RuntimeException
{
    public function __construct(public readonly ?int $employeeId, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
