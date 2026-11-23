<?php

namespace App\Libraries\Database;

use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

/**
 * A request that stops on an exception rolls back the transaction it left open before the exception is
 * answered, so that what is written after the answer, the session first of all, is not lost with it.
 * It looks only at the connections the request made, so that the answer to a request that never reached
 * the database does not open one. It changes nothing of the answer: the handler it wraps gives it.
 */
final class OpenTransactionHandler implements ExceptionHandlerInterface
{
    public function __construct(private readonly ExceptionHandlerInterface $next)
    {
    }

    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        try {
            foreach (Database::getConnections() as $connection) {
                AbandonedTransaction::close($connection, 0);
            }
        } catch (Throwable) {
            // The answer to the exception must not fail on what could not be ended; the connection closing ends it.
        }

        $this->next->handle($exception, $request, $response, $statusCode, $exitCode);
    }
}
