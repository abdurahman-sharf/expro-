<?php

namespace App\Libraries\Accounting\Ai;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final readonly class AnomalyContext
{
    /**
     * @param list<int>|null $branchIds The branches the scan may look at, null for every branch.
     */
    public function __construct(
        public BaseConnection $db,
        public AiSettings $settings,
        public DateTimeImmutable $today,
        public string $since,
        public ?array $branchIds,
    ) {
    }
}
