<?php

namespace App\Libraries\Tax;

/**
 * Whether a business is registered for VAT, as its owner said it; Unknown until they say.
 */
enum VatRegistration: string
{
    case Registered    = '1';
    case NotRegistered = '0';
    case Unknown       = '';

    public static function fromStored(mixed $value): self
    {
        return $value === null ? self::Unknown : ((int) $value === 1 ? self::Registered : self::NotRegistered);
    }

    public function stored(): ?int
    {
        return match ($this) {
            self::Registered    => 1,
            self::NotRegistered => 0,
            self::Unknown       => null,
        };
    }

    public function languageKey(): string
    {
        return 'Tax_profile.registration_' . match ($this) {
            self::Registered    => 'registered',
            self::NotRegistered => 'not_registered',
            self::Unknown       => 'unknown',
        };
    }
}
