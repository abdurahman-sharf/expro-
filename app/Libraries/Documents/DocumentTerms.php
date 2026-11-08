<?php

namespace App\Libraries\Documents;

/**
 * The terms and conditions the shop prints at the foot of a document, one text for each family of
 * documents, kept as settings. A family with no text prints no terms, and a document that is not of
 * any family listed here (a voucher, a receipt, a slip) never does.
 */
final class DocumentTerms
{
    public const MAX_LENGTH = 4000;
    private const KEYS      = [
        'invoice'          => 'document_terms_invoice',
        'tax_invoice'      => 'document_terms_invoice',
        'credit_note'      => 'document_terms_credit_note',
        'quote'            => 'document_terms_quote',
        'work_order'       => 'document_terms_work_order',
        'purchase_invoice' => 'document_terms_purchase_invoice',
    ];

    /**
     * @return list<string> The setting of each family, once.
     */
    public static function settingKeys(): array
    {
        return array_values(array_unique(array_values(self::KEYS)));
    }

    public static function keyFor(string $type): ?string
    {
        return self::KEYS[$type] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function forType(string $type, array $config): string
    {
        $key = self::keyFor($type);

        return $key === null ? '' : trim((string) ($config[$key] ?? ''));
    }

    /**
     * What a settings form may save: the text trimmed and cut to the length a setting holds.
     */
    public static function clean(mixed $text): string
    {
        return is_string($text) ? mb_substr(trim(str_replace("\r\n", "\n", $text)), 0, self::MAX_LENGTH) : '';
    }
}
