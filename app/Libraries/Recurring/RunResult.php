<?php

namespace App\Libraries\Recurring;

final readonly class RunResult
{
    public const GENERATED = 'generated';
    public const REFUSED   = 'refused';
    public const ENDED     = 'ended';
    public const EXISTING  = 'existing';
    public const BUSY      = 'busy';
    public const FAILED    = 'failed';

    public function __construct(
        public int $scheduleId,
        public string $outcome,
        public ?string $dueDate = null,
        public ?int $saleId = null,
        public ?int $expenseId = null,
        public ?RunRefusal $refusal = null,
        public ?string $failure = null,
    ) {
    }

    public function stops(): bool
    {
        return $this->outcome === self::BUSY || $this->outcome === self::ENDED;
    }
}
