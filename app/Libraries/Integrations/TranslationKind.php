<?php

namespace App\Libraries\Integrations;

enum TranslationKind: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Item     = 'item';
    case Category = 'category';

    public function languageKey(): string
    {
        return 'Integrations.kind_' . $this->value;
    }

    public function takesAddressNote(): bool
    {
        return $this === self::Customer || $this === self::Supplier;
    }
}
