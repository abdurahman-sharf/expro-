<?php

namespace App\Libraries\Accounting\Readiness;

/**
 * The outcome of every check. The database is ready when no check failed and none broke; what is
 * only for the owner to review, and what was skipped because the schema is not there yet, does not
 * stop it.
 */
final readonly class ReadinessResult
{
    public const PRE_MIGRATION  = 'pre-migration';
    public const POST_MIGRATION = 'post-migration';

    /**
     * @param list<CheckResult>                                                       $checks
     * @param list<array{version: string, class: string, kind: string, note: string}> $pendingMigrations
     */
    public function __construct(
        public array $checks,
        public array $pendingMigrations,
        public bool $readOnly,
        public string $database,
        public string $generatedAt,
    ) {
    }

    public function ready(): bool
    {
        return $this->blocking() === [];
    }

    public function phase(): string
    {
        return $this->pendingMigrations === [] ? self::POST_MIGRATION : self::PRE_MIGRATION;
    }

    /**
     * @return list<CheckResult>
     */
    public function blocking(): array
    {
        return array_values(array_filter($this->checks, static fn (CheckResult $check): bool => $check->status->blocks()));
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        $counts = array_fill_keys(array_map(static fn (CheckStatus $status): string => $status->value, CheckStatus::cases()), 0);

        foreach ($this->checks as $check) {
            $counts[$check->status->value]++;
        }

        return $counts;
    }

    /**
     * What the existing commands could clear, from the checks that failed with something to apply.
     *
     * @return list<array{command: string, params: array<string, null>}>
     */
    public function applicable(): array
    {
        $commands = [];

        foreach ($this->checks as $check) {
            if ($check->status === CheckStatus::Fail && $check->apply !== null) {
                $commands[$check->apply['command']] = $check->apply;
            }
        }

        return array_values($commands);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready'        => $this->ready(),
            'exit_code'    => $this->ready() ? 0 : 1,
            'read_only'    => $this->readOnly,
            'phase'        => $this->phase(),
            'database'     => $this->database,
            'generated_at' => $this->generatedAt,
            'counts'       => $this->counts(),
            'checks'       => array_map(static fn (CheckResult $check): array => $check->toArray(), $this->checks),
        ];
    }
}
