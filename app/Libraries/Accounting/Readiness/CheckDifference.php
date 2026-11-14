<?php

namespace App\Libraries\Accounting\Readiness;

/**
 * What differs for one check between the report taken before the migrations and the one taken after.
 * A changed value or a worse state is flagged; a value that a migration is expected to change, and a
 * check that could not be compared, is listed without being flagged.
 */
final readonly class CheckDifference
{
    /**
     * @param list<array{path: string, before: mixed, after: mixed}>              $changes  The values of data that changed and that nothing explains.
     * @param list<array{path: string, before: mixed, after: mixed, why: string}> $expected The values that changed as a migration is expected to change them.
     * @param list<string>                                                        $problems What else is wrong: a worse state, or a count the runbook says must be equal.
     */
    public function __construct(
        public string $id,
        public string $ref,
        public string $title,
        public ?string $statusBefore,
        public ?string $statusAfter,
        public array $changes = [],
        public array $expected = [],
        public array $problems = [],
        public ?string $notComparable = null,
    ) {
    }

    public function flagged(): bool
    {
        return $this->changes !== [] || $this->problems !== [];
    }

    public function listed(): bool
    {
        return $this->flagged() || $this->expected !== [] || $this->notComparable !== null || $this->statusBefore !== $this->statusAfter;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'ref'            => $this->ref,
            'title'          => $this->title,
            'flagged'        => $this->flagged(),
            'status_before'  => $this->statusBefore,
            'status_after'   => $this->statusAfter,
            'changes'        => $this->changes,
            'expected'       => $this->expected,
            'problems'       => $this->problems,
            'not_comparable' => $this->notComparable,
        ];
    }
}
