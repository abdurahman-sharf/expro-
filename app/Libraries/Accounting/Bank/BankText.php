<?php

namespace App\Libraries\Accounting\Bank;

final class BankText
{
    /**
     * Lower case, without the marks of Arabic and with the forms of its letters that people mix made
     * one, and with one space between words, so that a search finds a name however it was typed and
     * two descriptions that differ only so are the same description.
     */
    public static function normalised(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ة' => 'ه', 'ى' => 'ي']);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }
}
