<?php

namespace App\Libraries\EInvoicing;

use Closure;

/**
 * A ZATCA that lives in memory, for the tests of the client: it answers each request with what a closure decides or
 * with the next reply it was given (the last one again once they run out), a reply being an answer or a failure to
 * throw, and keeps every request. It sends nothing anywhere.
 */
final class FakeZatcaHttp implements ZatcaHttp
{
    /**
     * @var list<ZatcaHttpRequest>
     */
    public array $requests = [];

    /**
     * @var list<ZatcaHttpFailure|ZatcaHttpReply>
     */
    private array $replies;

    /**
     * @param (Closure(ZatcaHttpRequest): (ZatcaHttpFailure|ZatcaHttpReply))|list<ZatcaHttpFailure|ZatcaHttpReply> $script
     */
    public function __construct(private readonly array|Closure $script)
    {
        $this->replies = is_array($script) ? $script : [];
    }

    public function send(ZatcaHttpRequest $request): ZatcaHttpReply
    {
        $this->requests[] = $request;

        $reply = $this->script instanceof Closure
            ? ($this->script)($request)
            : (count($this->replies) > 1 ? array_shift($this->replies) : ($this->replies[0] ?? new ZatcaHttpReply(200, '{}')));

        if ($reply instanceof ZatcaHttpFailure) {
            throw $reply;
        }

        return $reply;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function json(int $status, array $body): ZatcaHttpReply
    {
        return new ZatcaHttpReply($status, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
