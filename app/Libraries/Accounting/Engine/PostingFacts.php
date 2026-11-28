<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\JournalOrigin;

/**
 * What a document gives the posting engine, and nothing about accounts or sides: its figures (signed
 * amounts the expressions of the rules read), its fields (the ids and labels the rules pick accounts
 * from), its flags (what the conditions of a rule test), and its lists (the entries a line repeats over,
 * each with figures, fields and flags of its own). A document typed by somebody, such as a manual
 * journal, brings its own description and origin, and may have no source id. A document in a foreign
 * currency names the currency, and, when its rules convert the journal to the base, the rate of the
 * document and the decimals of the base.
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
        public ?int $sourceId,
        public string $date,
        public int $branchId,
        public int $revision = 1,
        public ?int $employeeId = null,
        public array $figures = [],
        public array $fields = [],
        public array $flags = [],
        public array $lists = [],
        public ?string $currencyCode = null,
        public ?string $description = null,
        public JournalOrigin $origin = JournalOrigin::System,
        public ?string $currencyRate = null,
        public int $baseDecimals = 2,
    ) {
    }
}
