<?php

namespace App\Libraries\EInvoicing;

/**
 * The format rules of the specification for the fields this layer writes, each from the business rule that
 * states it (the electronic invoice XML implementation standard, version 1.2 of 2023-05-19). They are pure.
 */
final class EInvoiceRules
{
    public const STREET_MAX = 1000;
    public const TEXT_MAX   = 127;
    public const NAME_MAX   = 1000;
    public const NOTE_MAX   = 1000;

    /**
     * The five instances in which a credit note or a debit note is issued (KSA-10, Article 40 and Article 54 of the VAT regulations), as the data dictionary words them.
     */
    public const NOTE_REASONS = [
        'Cancellation or suspension of the supplies after its occurrence either wholly or partially',
        'In case of essential change or amendment in the supply, which leads to the change of the VAT due',
        'Amendment of the supply value which is pre-agreed upon between the supplier and consumer',
        'In case of goods or services refund.',
        'In case of change in Seller\'s or Buyer\'s information',
    ];

    /**
     * BR-KSA-40 and BR-KSA-44: fifteen digits, the first and the last of them a three.
     */
    public static function vatNumber(string $value): bool
    {
        return preg_match('/^3\d{13}3$/', $value) === 1;
    }

    /**
     * BR-KSA-66 and BR-KSA-67: five digits.
     */
    public static function postalCode(string $value): bool
    {
        return preg_match('/^\d{5}$/', $value) === 1;
    }

    /**
     * BR-KSA-37: four digits.
     */
    public static function buildingNumber(string $value): bool
    {
        return preg_match('/^\d{4}$/', $value) === 1;
    }

    /**
     * BR-KSA-08 and BR-KSA-14: letters and digits only.
     */
    public static function identificationNumber(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
    }

    public static function countryCode(string $value): bool
    {
        return preg_match('/^[A-Z]{2}$/', $value) === 1;
    }

    public static function within(string $value, int $limit): bool
    {
        return mb_strlen($value) <= $limit;
    }
}
