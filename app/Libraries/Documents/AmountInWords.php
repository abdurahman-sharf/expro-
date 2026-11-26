<?php

namespace App\Libraries\Documents;

/**
 * An amount of money spelled out, in Arabic or in English, for the documents that print it as words
 * (the vouchers and the receipts). The amount is a string so no float rounds it, it is rounded to the
 * decimals of the currency, and an amount of a trillion or more, a negative one, or one that is not a
 * number is refused by name. The Arabic wording follows the grammar of the counted noun.
 */
final class AmountInWords
{
    public const MAX_WHOLE = '999999999999';

    public static function supports(string $currencyCode): bool
    {
        return WordsCurrencies::find($currencyCode) !== null;
    }

    public function spell(string $amount, string $currencyCode, string $language, ?int $decimals = null): string
    {
        return $language === 'ar' ? $this->arabic($amount, $currencyCode, $decimals) : $this->english($amount, $currencyCode, $decimals);
    }

    public function arabic(string $amount, string $currencyCode, ?int $decimals = null): string
    {
        [$currency, $whole, $minor] = $this->parts($amount, $currencyCode, $decimals);

        if ($whole === 0 && $minor === 0) {
            return 'صفر ' . $currency->major->arabic[0];
        }

        $phrases = [];

        if ($whole > 0) {
            $phrases[] = $this->arabicPhrase($whole, $currency->major);
        }

        if ($minor > 0) {
            $phrases[] = $this->arabicPhrase($minor, $currency->minor);
        }

        return implode(' و', $phrases);
    }

    public function english(string $amount, string $currencyCode, ?int $decimals = null): string
    {
        [$currency, $whole, $minor] = $this->parts($amount, $currencyCode, $decimals);

        if ($whole === 0 && $minor === 0) {
            return 'Zero ' . $currency->major->english[1];
        }

        $phrases = [];

        if ($whole > 0) {
            $phrases[] = EnglishNumberWords::words($whole) . ' ' . $currency->major->english[$whole === 1 ? 0 : 1];
        }

        if ($minor > 0) {
            $phrases[] = EnglishNumberWords::words($minor) . ' ' . $currency->minor->english[$minor === 1 ? 0 : 1];
        }

        return ucfirst(implode(' and ', $phrases));
    }

    /**
     * @return array{0: WordsCurrency, 1: int, 2: int}
     */
    private function parts(string $amount, string $currencyCode, ?int $decimals): array
    {
        $currency = WordsCurrencies::find($currencyCode) ?? throw new DocumentException(DocumentError::CurrencyUnsupported, context: ['currency' => $currencyCode]);
        $amount   = trim($amount);

        if (preg_match('/^-\d+(\.\d+)?$/', $amount) === 1 && bccomp($amount, '0', 12) !== 0) {
            throw new DocumentException(DocumentError::AmountNegative);
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
            throw new DocumentException(DocumentError::AmountMalformed, context: ['value' => $amount]);
        }

        $places  = max(0, min($decimals ?? $currency->decimals, $currency->decimals));
        $rounded = bcadd(ltrim($amount, '-'), '0.' . str_repeat('0', $places) . '5', $places);
        $split   = explode('.', $rounded);

        if (bccomp($split[0], self::MAX_WHOLE, 0) > 0) {
            throw new DocumentException(DocumentError::AmountTooLarge, context: ['maximum' => self::MAX_WHOLE]);
        }

        return [$currency, (int) $split[0], $places === 0 ? 0 : (int) str_pad($split[1], $currency->decimals, '0')];
    }

    private function arabicPhrase(int $count, WordsUnit $unit): string
    {
        [$singular, $dual, $plural, $accusative] = $unit->arabic;

        if ($count === 1) {
            return $singular . ' ' . ($unit->feminine ? 'واحدة' : 'واحد');
        }

        if ($count === 2) {
            return $dual;
        }

        $words = ArabicNumberWords::words($count, $unit->feminine);
        $rest  = $count % 100;

        if ($rest >= 3 && $rest <= 10) {
            return $words . ' ' . $plural;
        }

        if ($rest >= 11) {
            return $words . ' ' . $accusative;
        }

        return $this->constructState($words, $count) . ' ' . $singular;
    }

    private function constructState(string $words, int $count): string
    {
        if ($count % 1000 !== 200 && $count % 1000 !== 0) {
            return $words;
        }

        $construct = ['مائتان' => 'مائتا', 'ألفان' => 'ألفا', 'مليونان' => 'مليونا', 'ملياران' => 'مليارا'];

        foreach ($construct as $dual => $form) {
            if ($words === $dual || str_ends_with($words, ' و' . $dual) || str_ends_with($words, ' ' . $dual)) {
                return substr($words, 0, -strlen($dual)) . $form;
            }
        }

        return $words;
    }
}
