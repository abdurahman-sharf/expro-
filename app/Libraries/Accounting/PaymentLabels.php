<?php

namespace App\Libraries\Accounting;

/**
 * A sale stores the name of its payment type in the language of the employee at the time (Cash and
 * نقدى are the same way of payment). This reads the name of every type in every language file of the
 * application, so a stored name is recognised whatever language is in use now.
 */
final class PaymentLabels
{
    private const LANGUAGE_FILE = 'Sales.php';

    /**
     * @var array<string, PaymentType>|null
     */
    private static ?array $types = null;

    /**
     * @var array<string, list<string>>|null
     */
    private static ?array $names = null;

    /**
     * @var array<string, true>|null
     */
    private static ?array $adjustments = null;

    public static function typeOf(string $name): ?PaymentType
    {
        self::load();

        $key = self::fold($name);

        if (isset(self::$types[$key])) {
            return self::$types[$key];
        }

        foreach (PaymentType::cases() as $type) {
            if (self::fold(lang($type->languageKey())) === $key) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Whether a stored name is the one of the cash adjustment that rounds a cash payment, in any language.
     */
    public static function isCashAdjustment(string $name): bool
    {
        self::load();

        return isset(self::$adjustments[self::fold($name)]) || self::fold(lang('Sales.cash_adjustment')) === self::fold($name);
    }

    /**
     * The names a type is stored under, the one of the language in use first.
     *
     * @return list<string>
     */
    public static function namesOf(PaymentType $type): array
    {
        self::load();

        $names = [];

        foreach ([lang($type->languageKey()), ...self::$names[$type->value]] as $name) {
            if ($name !== '' && $name !== $type->languageKey()) {
                $names[$name] = $name;
            }
        }

        return array_values($names);
    }

    private static function load(): void
    {
        if (self::$types !== null) {
            return;
        }

        $files = glob(APPPATH . 'Language/*/' . self::LANGUAGE_FILE) ?: [];
        sort($files);

        self::$types       = [];
        self::$adjustments = [];
        self::$names       = array_fill_keys(array_map(static fn (PaymentType $type): string => $type->value, PaymentType::cases()), []);

        foreach ($files as $file) {
            $lines = require $file;

            if (is_string($lines['cash_adjustment'] ?? null) && trim($lines['cash_adjustment']) !== '') {
                self::$adjustments[self::fold($lines['cash_adjustment'])] = true;
            }

            foreach (PaymentType::cases() as $type) {
                $name = $lines[$type->value] ?? '';

                if (! is_string($name) || trim($name) === '') {
                    continue;
                }

                self::$types[self::fold($name)] ??= $type;
                self::$names[$type->value][trim($name)] = trim($name);
            }
        }

        self::$names = array_map('array_values', self::$names);
    }

    private static function fold(string $name): string
    {
        return mb_strtolower(trim($name));
    }
}
