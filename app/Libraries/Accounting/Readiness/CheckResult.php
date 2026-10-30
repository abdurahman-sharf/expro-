<?php

namespace App\Libraries\Accounting\Readiness;

use Throwable;

/**
 * What one check found. A check that works leaves its name, reference and title to the group that
 * runs it, which adds them with {@see named()}.
 */
final readonly class CheckResult
{
    /**
     * @param list<string>                                             $findings
     * @param array<string, mixed>                                     $data
     * @param array{command: string, params: array<string, null>}|null $apply    The existing command, with its own apply flag, that clears what the check found.
     */
    public function __construct(
        public CheckStatus $status,
        public string $summary,
        public array $findings = [],
        public array $data = [],
        public ?array $apply = null,
        public string $id = '',
        public string $ref = '',
        public string $title = '',
    ) {
    }

    /**
     * @param list<string>         $findings
     * @param array<string, mixed> $data
     */
    public static function pass(string $summary, array $findings = [], array $data = []): self
    {
        return new self(CheckStatus::Pass, $summary, $findings, $data);
    }

    /**
     * @param list<string>                                             $findings
     * @param array<string, mixed>                                     $data
     * @param array{command: string, params: array<string, null>}|null $apply
     */
    public static function fail(string $summary, array $findings = [], array $data = [], ?array $apply = null): self
    {
        return new self(CheckStatus::Fail, $summary, $findings, $data, $apply);
    }

    /**
     * @param list<string>         $findings
     * @param array<string, mixed> $data
     */
    public static function review(string $summary, array $findings = [], array $data = []): self
    {
        return new self(CheckStatus::Review, $summary, $findings, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function skipped(string $summary, array $data = []): self
    {
        return new self(CheckStatus::Skipped, $summary, [], $data);
    }

    public static function error(Throwable $failure): self
    {
        return new self(CheckStatus::Error, 'The check could not run: ' . $failure::class . ': ' . $failure->getMessage());
    }

    public function named(string $id, string $ref, string $title): self
    {
        return new self($this->status, $this->summary, $this->findings, $this->data, $this->apply, $id, $ref, $title);
    }

    public function applyCommand(): ?string
    {
        if ($this->apply === null) {
            return null;
        }

        return trim($this->apply['command'] . ' ' . implode(' ', array_map(static fn (string $flag): string => '--' . $flag, array_keys($this->apply['params']))));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'ref'      => $this->ref,
            'title'    => $this->title,
            'status'   => $this->status->value,
            'summary'  => $this->summary,
            'findings' => $this->findings,
            'data'     => $this->data,
            'apply'    => $this->applyCommand(),
        ];
    }
}
