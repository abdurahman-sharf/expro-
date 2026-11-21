<?php

namespace App\Libraries\EInvoicing;

use Closure;

/**
 * A ZATCA that lives in memory, for the tests: it answers each request with the next answer it was given (the last
 * one again once they run out), or with what a closure decides, and keeps every request it was sent. It sends
 * nothing anywhere.
 */
final class FakeZatcaTransport implements ZatcaClient
{
    /**
     * @var list<ZatcaRequest>
     */
    public array $requests = [];

    /**
     * @var list<ZatcaResponse>
     */
    private array $answers;

    /**
     * @param (Closure(ZatcaRequest): ZatcaResponse)|list<ZatcaResponse> $script
     */
    public function __construct(private readonly array|Closure $script)
    {
        $this->answers = is_array($script) ? $script : [];
    }

    public function name(): string
    {
        return 'fake';
    }

    public function submit(ZatcaRequest $request): ZatcaResponse
    {
        $this->requests[] = $request;

        if ($this->script instanceof Closure) {
            return ($this->script)($request);
        }

        return count($this->answers) > 1 ? array_shift($this->answers) : ($this->answers[0] ?? new ZatcaResponse(ZatcaOutcome::Accepted, 200));
    }
}
