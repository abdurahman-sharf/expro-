<?php

namespace App\Libraries\Accounting\Engine;

/**
 * What a document gives the posting engine, and nothing about accounts or sides: its figures (signed
 * amounts the expressions of the rules read), its fields (the ids and labels the rules pick accounts
 * from), its flags (what the conditions of a rule test), and its lists (the entries a line repeats over,
 * each with figures, fields and flags of its own).
 */
final readonly class PostingFacts
{
    /**
     * @param array<string, string>                                                                                                             $figures
     * @param array<string, int|string|null>                                                                                                    $fields
     * @param array<string, bool>                                                                                                               $flags
     * @param array<string, list<array{figures?: array<string, string>, fields?: array<string, int|string|null>, flags?: array<string, bool>}>> $lists
     */
    public function __construct(
        public string $operation,
        public int $sourceId,
        public string $date,
        public int $branchId,
        public int $revision = 1,
        public ?int $employeeId = null,
        public array $figures = [],
        public array $fields = [],
        public array $flags = [],
        public array $lists = [],
        public ?string $currencyCode = null,
    ) {
    }
}
