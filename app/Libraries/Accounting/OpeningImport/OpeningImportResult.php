<?php

namespace App\Libraries\Accounting\OpeningImport;

/**
 * What one run of the approval of a file did: how many rows it posted, how many stand posted now, how many are
 * still to be posted, and the row it stopped at when a row could not be posted.
 */
final readonly class OpeningImportResult
{
    /**
     * @param array{line: int, party: string, message: string}|null $failed
     */
    public function __construct(
        public int $posted,
        public int $alreadyPosted,
        public int $remaining,
        public int $total,
        public string $fingerprint,
        public ?array $failed = null,
    ) {
    }

    public function isComplete(): bool
    {
        return $this->failed === null && $this->remaining === 0;
    }
}
