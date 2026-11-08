<?php

namespace App\Libraries\Accounting\Ai;

final readonly class Finding
{
    public const LOW    = 'low';
    public const MEDIUM = 'medium';
    public const HIGH   = 'high';

    /**
     * @param string                           $key          What makes the finding the same one the next time: the rule and the records it is about.
     * @param array<string, scalar>            $templateArgs What the text of the rule is filled with.
     * @param list<array<string, scalar|null>> $evidence     The rows of the books it rests on.
     */
    public function __construct(
        public string $rule,
        public string $severity,
        public string $key,
        public ?int $branchId,
        public string $subjectType,
        public ?int $subjectId,
        public ?string $occurredOn,
        public array $templateArgs,
        public array $evidence,
    ) {
    }

    public function digest(): string
    {
        return hash('sha256', $this->rule . '|' . $this->key);
    }
}
