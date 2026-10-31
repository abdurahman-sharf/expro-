<?php

namespace App\Libraries\Accounting;

/**
 * The text an automatic journal of a sale or a purchase is described with, when the company chose one:
 * plain words with the placeholders {number}, {party}, {type} and {date}, which are replaced by the number
 * of the document, the name of the customer or the supplier, the kind of document and its date. An empty
 * template means the descriptions the application writes itself. A template is checked when it is saved
 * and the values put into it are cleaned of markup and control characters when it is used, so that what
 * ends in a journal is plain text whatever a name was typed as.
 */
final class JournalDescriptionTemplate
{
    public const MAX          = 200;
    public const RESULT_MAX   = 255;
    public const PLACEHOLDERS = ['number', 'party', 'type', 'date'];
    public const SALES        = 'sales';
    public const PURCHASES    = 'purchases';

    public static function isValid(string $template): bool
    {
        $template = trim($template);

        if ($template === '') {
            return true;
        }

        if (mb_strlen($template) > self::MAX || preg_match('/[\x00-\x1F\x7F<>]/u', $template) === 1) {
            return false;
        }

        $rest = preg_replace('/\{(?:' . implode('|', self::PLACEHOLDERS) . ')\}/', '', $template);

        return $rest !== null && ! str_contains($rest, '{') && ! str_contains($rest, '}');
    }

    /**
     * @param array{number?: string, party?: string, type?: string, date?: string} $values
     *
     * @return string The description, or an empty text when the template is empty or has nothing left of it.
     */
    public static function render(string $template, array $values): string
    {
        $template = trim($template);

        if ($template === '' || ! self::isValid($template)) {
            return '';
        }

        $replacements = [];

        foreach (self::PLACEHOLDERS as $name) {
            $replacements['{' . $name . '}'] = self::clean($values[$name] ?? '');
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', strtr($template, $replacements)));

        return mb_substr($text, 0, self::RESULT_MAX);
    }

    private static function clean(string $value): string
    {
        $value = strip_tags($value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
