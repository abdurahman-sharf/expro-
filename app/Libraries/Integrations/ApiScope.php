<?php

namespace App\Libraries\Integrations;

enum ApiScope: string
{
    case ReadCustomers = 'read:customers';
    case ReadDocuments = 'read:documents';

    public function languageKey(): string
    {
        return 'Integrations.scope_' . str_replace(':', '_', $this->value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $scope): string => $scope->value, self::cases());
    }
}
