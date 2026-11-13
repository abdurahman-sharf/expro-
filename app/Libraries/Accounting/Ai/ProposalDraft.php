<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\JournalLineDraft;

final readonly class ProposalDraft
{
    /**
     * @param list<JournalLineDraft>           $lines
     * @param list<array<string, scalar|null>> $evidence        The rows the proposal rests on, each with the id of the record it came from.
     * @param array<string, scalar>            $templateArgs    What the title and the rationale of the kind are filled with.
     * @param list<int>                        $subjectJournals Posted journals that must still stand, not reversed, when the proposal is approved.
     */
    public function __construct(
        public string $kind,
        public string $proposer,
        public string $dedupeKey,
        public string $date,
        public ?int $branchId,
        public string $description,
        public array $lines,
        public array $evidence,
        public array $templateArgs,
        public array $subjectJournals = [],
    ) {
    }
}
