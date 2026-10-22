<?php

namespace App\Libraries\Accounting;

/**
 * The payment types a sale can carry. A sale stores the translated name of its type, so a
 * type is recognised by comparing that name with the translations of the type.
 */
enum PaymentType: string
{
    case Cash         = 'cash';
    case Debit        = 'debit';
    case Credit       = 'credit';
    case Check        = 'check';
    case BankTransfer = 'bank_transfer';
    case Upi          = 'upi';
    case Wallet       = 'wallet';
    case Due          = 'due';
    case Giftcard     = 'giftcard';
    case Rewards      = 'rewards';

    private const ROUTING_PREFIX   = 'payment.';
    private const FALLBACK_LOCALES = ['en'];

    public function routingKey(): string
    {
        return self::ROUTING_PREFIX . $this->value;
    }

    public static function fromRoutingKey(string $routingKey): ?self
    {
        return str_starts_with($routingKey, self::ROUTING_PREFIX) ? self::tryFrom(substr($routingKey, strlen(self::ROUTING_PREFIX))) : null;
    }

    public function languageKey(): string
    {
        return 'Sales.' . $this->value;
    }

    /**
     * Credit sales and gift cards are not routed by choice: the first always settles through the
     * customers control account and the second through the gift card liability.
     */
    public function lockedRoutingKey(): ?RoutingKey
    {
        return match ($this) {
            self::Due      => RoutingKey::Customers,
            self::Giftcard => RoutingKey::GiftcardLiability,
            default        => null,
        };
    }

    public function isLocked(): bool
    {
        return $this->lockedRoutingKey() !== null;
    }

    /**
     * The system account a new routing row starts with. Everything that is not cash is treated
     * as cash until the treasuries and bank accounts exist, and reward points are a discount.
     */
    public function defaultSystemKey(): ?string
    {
        return match ($this) {
            self::Due, self::Giftcard => null,
            self::Rewards => RoutingKey::SalesDiscount->defaultSystemKey(),
            default       => RoutingKey::CashDefault->defaultSystemKey(),
        };
    }

    /**
     * @return list<AccountType>
     */
    public function allowedAccountTypes(): array
    {
        return match ($this) {
            self::Due, self::Giftcard => [],
            self::Rewards => [AccountType::Revenue, AccountType::Expense, AccountType::Liability],
            default       => [AccountType::Asset, AccountType::Liability],
        };
    }

    /**
     * @return list<string> The system keys of the accounts this type may point at.
     */
    public function acceptedSystemKeys(): array
    {
        return match ($this) {
            self::Due, self::Giftcard => [],
            self::Rewards => [RoutingKey::SalesDiscount->defaultSystemKey(), RoutingKey::CashDifference->defaultSystemKey()],
            default       => [RoutingKey::CashDefault->defaultSystemKey()],
        };
    }

    /**
     * The type a stored payment name stands for, or null when it matches none. A gift card
     * payment is stored with the card number after a colon.
     */
    public static function fromLabel(string $label): ?self
    {
        $name = trim(explode(':', $label, 2)[0]);

        if ($name === '') {
            return null;
        }

        foreach (self::cases() as $type) {
            foreach ($type->labels() as $candidate) {
                if (strcasecmp($candidate, $name) === 0) {
                    return $type;
                }
            }
        }

        return null;
    }

    /**
     * The name of this type in the language in use and in the fallback languages.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        $labels = [];

        foreach ([null, ...self::FALLBACK_LOCALES] as $locale) {
            $line = $locale === null ? lang($this->languageKey()) : lang($this->languageKey(), [], $locale);

            if ($line !== '' && $line !== $this->languageKey()) {
                $labels[$line] = $line;
            }
        }

        return array_values($labels);
    }
}
