<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Exceptions;
use ErrorException;
use Throwable;

/**
 * The handler of the exceptions of the application: a request that ran out of memory is answered by the
 * notice of MemoryExhaustedNotice, and every other failure by the handler of the framework.
 */
final class MemoryExhaustedHandler implements ExceptionHandlerInterface
{
    public function __construct(private readonly Exceptions $config)
    {
    }

    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        if ($exception instanceof ErrorException && MemoryExhaustedNotice::answer(['type' => $exception->getSeverity(), 'message' => $exception->getMessage()])) {
            return;
        }

        (new ExceptionHandler($this->config))->handle($exception, $request, $response, $statusCode, $exitCode);
    }
}
