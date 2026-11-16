<?php

namespace App\Libraries\Setup;

use DateTimeZone;
use Locale;

/**
 * The countries the first setup offers, with what each of them sets by default so that nobody has to look up the
 * currency, its symbol, how many digits it has and the time zone. Everything it fills stays editable.
 */
final class Regions
{
    /**
     * @var array<string, array{currency: string, symbol: string, timezone: string, decimals: int}>
     */
    private const TABLE = [
        'EG' => ['currency' => 'EGP', 'symbol' => 'ج.م', 'timezone' => 'Africa/Cairo', 'decimals' => 2],
        'SA' => ['currency' => 'SAR', 'symbol' => 'ر.س', 'timezone' => 'Asia/Riyadh', 'decimals' => 2],
        'AE' => ['currency' => 'AED', 'symbol' => 'د.إ', 'timezone' => 'Asia/Dubai', 'decimals' => 2],
        'KW' => ['currency' => 'KWD', 'symbol' => 'د.ك', 'timezone' => 'Asia/Kuwait', 'decimals' => 3],
        'QA' => ['currency' => 'QAR', 'symbol' => 'ر.ق', 'timezone' => 'Asia/Qatar', 'decimals' => 2],
        'BH' => ['currency' => 'BHD', 'symbol' => 'د.ب', 'timezone' => 'Asia/Bahrain', 'decimals' => 3],
        'OM' => ['currency' => 'OMR', 'symbol' => 'ر.ع', 'timezone' => 'Asia/Muscat', 'decimals' => 3],
        'JO' => ['currency' => 'JOD', 'symbol' => 'د.أ', 'timezone' => 'Asia/Amman', 'decimals' => 3],
        'LB' => ['currency' => 'LBP', 'symbol' => 'ل.ل', 'timezone' => 'Asia/Beirut', 'decimals' => 2],
        'IQ' => ['currency' => 'IQD', 'symbol' => 'د.ع', 'timezone' => 'Asia/Baghdad', 'decimals' => 0],
        'SY' => ['currency' => 'SYP', 'symbol' => 'ل.س', 'timezone' => 'Asia/Damascus', 'decimals' => 2],
        'YE' => ['currency' => 'YER', 'symbol' => 'ر.ي', 'timezone' => 'Asia/Aden', 'decimals' => 2],
        'LY' => ['currency' => 'LYD', 'symbol' => 'د.ل', 'timezone' => 'Africa/Tripoli', 'decimals' => 3],
        'TN' => ['currency' => 'TND', 'symbol' => 'د.ت', 'timezone' => 'Africa/Tunis', 'decimals' => 3],
        'DZ' => ['currency' => 'DZD', 'symbol' => 'د.ج', 'timezone' => 'Africa/Algiers', 'decimals' => 2],
        'MA' => ['currency' => 'MAD', 'symbol' => 'د.م', 'timezone' => 'Africa/Casablanca', 'decimals' => 2],
        'SD' => ['currency' => 'SDG', 'symbol' => 'ج.س', 'timezone' => 'Africa/Khartoum', 'decimals' => 2],
        'PS' => ['currency' => 'ILS', 'symbol' => '₪', 'timezone' => 'Asia/Hebron', 'decimals' => 2],
        'TR' => ['currency' => 'TRY', 'symbol' => '₺', 'timezone' => 'Europe/Istanbul', 'decimals' => 2],
        'US' => ['currency' => 'USD', 'symbol' => '$', 'timezone' => 'America/New_York', 'decimals' => 2],
        'GB' => ['currency' => 'GBP', 'symbol' => '£', 'timezone' => 'Europe/London', 'decimals' => 2],
        'DE' => ['currency' => 'EUR', 'symbol' => '€', 'timezone' => 'Europe/Berlin', 'decimals' => 2],
        'FR' => ['currency' => 'EUR', 'symbol' => '€', 'timezone' => 'Europe/Paris', 'decimals' => 2],
        'ES' => ['currency' => 'EUR', 'symbol' => '€', 'timezone' => 'Europe/Madrid', 'decimals' => 2],
        'IT' => ['currency' => 'EUR', 'symbol' => '€', 'timezone' => 'Europe/Rome', 'decimals' => 2],
        'NL' => ['currency' => 'EUR', 'symbol' => '€', 'timezone' => 'Europe/Amsterdam', 'decimals' => 2],
        'IN' => ['currency' => 'INR', 'symbol' => '₹', 'timezone' => 'Asia/Kolkata', 'decimals' => 2],
        'PK' => ['currency' => 'PKR', 'symbol' => '₨', 'timezone' => 'Asia/Karachi', 'decimals' => 2],
        'ID' => ['currency' => 'IDR', 'symbol' => 'Rp', 'timezone' => 'Asia/Jakarta', 'decimals' => 2],
        'KE' => ['currency' => 'KES', 'symbol' => 'KSh', 'timezone' => 'Africa/Nairobi', 'decimals' => 2],
        'NG' => ['currency' => 'NGN', 'symbol' => '₦', 'timezone' => 'Africa/Lagos', 'decimals' => 2],
    ];

    public const US_DATE_COUNTRIES = ['US'];

    /**
     * @return array{currency: string, symbol: string, timezone: string, decimals: int}|null
     */
    public static function find(string $code): ?array
    {
        return self::TABLE[strtoupper($code)] ?? null;
    }

    /**
     * @return array<string, array{name: string, currency: string, symbol: string, timezone: string, decimals: int}>
     */
    public static function all(string $languageCode): array
    {
        $regions = [];

        foreach (self::TABLE as $code => $defaults) {
            $name           = Locale::getDisplayRegion('-' . $code, $languageCode);
            $regions[$code] = ['name' => $name !== '' ? $name : $code] + $defaults;
        }

        uasort($regions, static fn (array $first, array $second): int => strcoll($first['name'], $second['name']));

        return $regions;
    }

    /**
     * @return list<string>
     */
    public static function timezones(): array
    {
        return DateTimeZone::listIdentifiers();
    }
}
