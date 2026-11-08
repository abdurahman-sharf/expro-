<?php

namespace App\Libraries\Documents;

use DateTimeImmutable;

/**
 * The kinds of extra field a product or an invoice can carry. The reference system offers twenty; these
 * are the nine that hold one plain value. A value is kept as text and checked against its kind when it
 * is entered.
 */
enum CustomFieldType: string
{
    case Text     = 'text';
    case Textarea = 'textarea';
    case Number   = 'number';
    case Date     = 'date';
    case Select   = 'select';
    case Checkbox = 'checkbox';
    case Url      = 'url';
    case Email    = 'email';
    case Phone    = 'phone';

    public const MAX_TEXT     = 255;
    public const MAX_TEXTAREA = 2000;
    public const MAX_URL      = 500;
    public const MAX_NUMBER   = 15;

    public function languageKey(): string
    {
        return 'Custom_fields.type_' . $this->value;
    }

    public function hasOptions(): bool
    {
        return $this === self::Select;
    }

    /**
     * The value as it is stored, or null when it does not fit the kind. An empty value is not checked here.
     *
     * @param list<string> $options
     */
    public function normalize(string $value, array $options = []): ?string
    {
        $value = trim($value);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return null;
        }

        return match ($this) {
            self::Text     => $this->limited($value, self::MAX_TEXT, false),
            self::Textarea => $this->limited(str_replace("\r\n", "\n", $value), self::MAX_TEXTAREA, true),
            self::Number   => preg_match('/^-?\d{1,' . self::MAX_NUMBER . '}(\.\d{1,6})?$/', $value) === 1 ? $value : null,
            self::Date     => $this->date($value),
            self::Select   => in_array($value, $options, true) ? $value : null,
            self::Checkbox => in_array($value, ['1', 'on', 'true'], true) ? '1' : null,
            self::Url      => $this->url($value),
            self::Email    => mb_strlen($value) <= self::MAX_TEXT && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null,
            self::Phone    => preg_match('/^\+?[0-9(][0-9 ()\-]{3,18}[0-9]$/', $value) === 1 ? $value : null,
        };
    }

    private function limited(string $value, int $limit, bool $newLines): ?string
    {
        if (! $newLines && str_contains($value, "\n")) {
            return null;
        }

        return mb_strlen($value) <= $limit ? $value : null;
    }

    private function date(string $value): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }

    private function url(string $value): ?string
    {
        if (mb_strlen($value) > self::MAX_URL || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true) ? $value : null;
    }
}
