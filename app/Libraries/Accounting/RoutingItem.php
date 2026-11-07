<?php

namespace App\Libraries\Accounting;

final readonly class RoutingItem
{
    public const COMPLETE    = 'complete';
    public const MISSING     = 'missing';
    public const INVALID     = 'invalid';
    public const OPTIONAL    = 'optional';
    public const UNSUPPORTED = 'unsupported';

    /**
     * @param self::*      $status
     * @param list<string> $allowedTypes The account types the row accepts, as stored.
     * @param list<string> $routingTypes The routing types the row offers, as stored.
     * @param int|null     $itemAccounts How many items name an account of their own for the row, or null for a row that takes none.
     */
    public function __construct(
        public string $key,
        public string $group,
        public string $label,
        public ?int $accountId,
        public ?string $accountCode,
        public ?string $accountName,
        public ?int $defaultAccountId,
        public ?string $defaultAccountCode,
        public ?string $defaultAccountName,
        public string $status,
        public bool $required,
        public bool $locked,
        public array $allowedTypes,
        public ?string $lockedTo = null,
        public string $type = 'auto',
        public array $routingTypes = [],
        public bool $isEntity = false,
        public bool $cancelMeansNone = false,
        public ?int $itemAccounts = null,
    ) {
    }
}
