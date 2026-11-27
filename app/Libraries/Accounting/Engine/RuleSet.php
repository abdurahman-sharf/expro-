<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\JournalEntryType;

/**
 * The rules of one operation as they stand from a day on: the journal they make and its lines.
 */
final readonly class RuleSet
{
    /**
     * @param list<RuleLine> $lines In the order of their numbers.
     */
    public function __construct(
        public int $ruleSetId,
        public string $operation,
        public int $version,
        public string $effectiveFrom,
        public string $sourceType,
        public JournalEntryType $entryType,
        public string $description,
        public bool $crossBranch,
        public array $lines,
    ) {
    }
}
