<?php

namespace App\Libraries\Accounting\Backfill;

/**
 * The words for why a document was refused and for what its owner can do about it, in the language in
 * use. The refusal of the posting is many reasons under one name, and the three that a business that
 * comes from an older system meets most have their own words.
 */
final class BackfillRefusalText
{
    private const POSTING_KINDS = ['credit_needs_customer', 'sale_not_postable', 'payment_type_unknown'];

    public static function reason(string $reason): string
    {
        return self::line('Accounting_backfill.refusal_' . $reason, $reason);
    }

    public static function kind(string $reason, string $kind): string
    {
        if ($reason !== BackfillRefusal::PostingRefused->value || $kind === '') {
            return '';
        }

        return in_array($kind, self::POSTING_KINDS, true) ? self::line('Accounting_backfill.refusal_kind_' . $kind, $kind) : $kind;
    }

    public static function help(string $reason, string $kind): string
    {
        if ($reason === BackfillRefusal::PostingRefused->value && in_array($kind, self::POSTING_KINDS, true)) {
            return self::line('Accounting_backfill.refusal_help_posting_' . $kind, '');
        }

        return self::line('Accounting_backfill.refusal_help_' . $reason, '');
    }

    private static function line(string $key, string $fallback): string
    {
        $line = lang($key);

        return $line === $key ? $fallback : $line;
    }
}
