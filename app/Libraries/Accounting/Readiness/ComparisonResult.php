<?php

namespace App\Libraries\Accounting\Readiness;

final readonly class ComparisonResult
{
    /**
     * @param list<CheckDifference> $differences Every check of either report, in the order of the second report.
     * @param list<string>          $notes       What the reader should know about the two reports as a whole.
     * @param list<string>          $blockers    Why the two reports cannot show what the migrations changed, whatever their checks say.
     */
    public function __construct(
        public array $differences,
        public array $notes,
        public array $blockers = [],
    ) {
    }

    public function flagged(): bool
    {
        return $this->blockers !== [] || $this->flaggedChecks() !== [];
    }

    /**
     * @return list<CheckDifference>
     */
    public function flaggedChecks(): array
    {
        return array_values(array_filter($this->differences, static fn (CheckDifference $difference): bool => $difference->flagged()));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'flagged'        => $this->flagged(),
            'checks'         => count($this->differences),
            'flagged_checks' => count($this->flaggedChecks()),
            'notes'          => $this->notes,
            'blockers'       => $this->blockers,
            'differences'    => array_map(static fn (CheckDifference $difference): array => $difference->toArray(), array_values(array_filter($this->differences, static fn (CheckDifference $difference): bool => $difference->listed()))),
        ];
    }
}
