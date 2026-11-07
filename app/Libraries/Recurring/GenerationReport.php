<?php

namespace App\Libraries\Recurring;

final class GenerationReport
{
    /**
     * @var list<RunResult>
     */
    public array $results = [];

    /**
     * @var list<int>
     */
    public array $capped = [];

    public function __construct(public readonly string $today)
    {
    }

    public function add(RunResult $result): void
    {
        $this->results[] = $result;
    }

    public function count(string $outcome): int
    {
        return count(array_filter($this->results, static fn (RunResult $result): bool => $result->outcome === $outcome));
    }

    public function generated(): int
    {
        return $this->count(RunResult::GENERATED);
    }

    public function refused(): int
    {
        return $this->count(RunResult::REFUSED);
    }

    public function failed(): int
    {
        return $this->count(RunResult::FAILED);
    }
}
