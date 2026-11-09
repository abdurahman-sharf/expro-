<?php

namespace App\Libraries\Integrations;

final readonly class ApiKey
{
    /**
     * @param list<ApiScope> $scopes
     */
    public function __construct(
        public int $id,
        public string $prefix,
        public string $name,
        public array $scopes,
        public ?int $branchId,
        public int $rateLimit,
        public ?string $expiresAt,
        public bool $revoked,
        public ?string $revokedAt,
        public ?string $lastUsedAt,
        public ?int $createdBy,
        public string $createdAt,
    ) {
    }

    public function allows(ApiScope $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function isExpired(string $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt < $now;
    }
}
