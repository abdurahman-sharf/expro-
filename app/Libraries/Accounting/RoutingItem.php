<?php

namespace App\Libraries\Accounting;

final readonly class RoutingItem
{
    public const COMPLETE = 'complete';
    public const MISSING  = 'missing';
    public const INVALID  = 'invalid';
    public const OPTIONAL = 'optional';

    /**
     * @param 'payments'|'sales' $group
     * @param self::*            $status
     * @param list<string>       $allowedTypes The account types the row accepts, as stored.
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
    ) {
    }
}
