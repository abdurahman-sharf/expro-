<?php

namespace App\Libraries\Accounting;

/**
 * What a screen of the books answers when a request runs out of memory in spite of the counts that refuse a
 * report too large: the reader gets a page that says why and what to do, with status 422, instead of the
 * empty page of a fatal error. It cannot save what was being built; it only keeps the failure from being blank.
 */
final class MemoryExhaustedNotice
{
    private static ?string $reserve = null;
    private static bool $armed      = false;

    public static function arm(): void
    {
        if (self::$armed) {
            return;
        }

        self::$armed   = true;
        self::$reserve = str_repeat('x', 512 * 1024);

        register_shutdown_function(static fn (): bool => self::answer(error_get_last()));
    }

    /**
     * @param array{type: int, message: string}|null $error What error_get_last() gave.
     * @param callable(int, string): void|null       $send  What puts the status and the page on the way out; the default is the response of the request.
     *
     * @return bool Whether the answer was sent.
     */
    public static function answer(?array $error, ?callable $send = null): bool
    {
        if ($error === null || $error['type'] !== E_ERROR || ! str_contains($error['message'], 'Allowed memory size')) {
            return false;
        }

        self::$reserve = null;

        if ($send === null && headers_sent()) {
            return false;
        }

        $message = esc(lang('Accounting.error_report_memory_exhausted'));
        $page    = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . $message . '</title></head><body><p id="report_error" role="alert">' . $message . '</p></body></html>';

        ($send ?? self::respond(...))(422, $page);

        return true;
    }

    private static function respond(int $status, string $page): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('HTTP/1.1 ' . $status . ' Unprocessable Entity', true, $status);
        header('Content-Type: text/html; charset=UTF-8');

        echo $page;
    }
}
