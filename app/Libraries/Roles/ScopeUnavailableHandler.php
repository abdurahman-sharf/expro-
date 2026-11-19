<?php

namespace App\Libraries\Roles;

use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\OSPOS;
use Config\Services;
use Throwable;

/**
 * Answers a request that stopped because what an employee may see could not be read: with the notice
 * the reader needs and a status that says to try again, not with the page of an unexpected error.
 * Every other failure, and every command, goes on to the handler it wraps. What the failure was is
 * not in the answer; the framework has already written it, with its causes, in the log.
 */
final class ScopeUnavailableHandler implements ExceptionHandlerInterface
{
    public const STATUS = 503;

    public function __construct(private readonly ExceptionHandlerInterface $next)
    {
    }

    public static function concerns(Throwable $exception): bool
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RecordScopeUnavailableException || $cause instanceof RoleTablesUnavailableException) {
                return true;
            }
        }

        return false;
    }

    public function handle(Throwable $exception, RequestInterface $request, ResponseInterface $response, int $statusCode, int $exitCode): void
    {
        if (! $request instanceof IncomingRequest || ! self::concerns($exception)) {
            $this->next->handle($exception, $request, $response, $statusCode, $exitCode);

            return;
        }

        $language = $this->speak();
        $message  = lang('Roles.error_scope_unavailable');

        foreach (array_keys($response->headers()) as $name) {
            $response->removeHeader($name);
        }

        $response->setStatusCode(self::STATUS)->noCache();

        if (str_contains($request->getHeaderLine('accept'), 'text/html')) {
            $response->setContentType('text/html', 'UTF-8')->setBody($this->page($message, $language));
        } else {
            $response->setJSON(['success' => false, 'message' => $message]);
        }

        $response->send();

        if (ENVIRONMENT !== 'testing') {
            exit($exitCode);
        }
    }

    private function speak(): string
    {
        try {
            $language = current_language_code();
        } catch (Throwable) {
            // The language of the employee is read from the database, which may be what failed; the one of the system is in memory.
            $language = config(OSPOS::class)->settings['language_code'] ?? DEFAULT_LANGUAGE_CODE;
        }

        Services::language()->setLocale($language);

        return $language;
    }

    private function page(string $message, string $language): string
    {
        $text = esc($message);

        return '<!DOCTYPE html><html lang="' . esc($language, 'attr') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $text
            . '</title></head><body dir="auto" style="font-family: sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1rem;"><p id="scope_unavailable" role="alert">' . $text . '</p></body></html>';
    }
}
