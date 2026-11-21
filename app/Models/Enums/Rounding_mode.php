<?php

namespace App\Models\Enums;

use ReflectionClass;

class Rounding_mode
{
    public const HALF_UP    = PHP_ROUND_HALF_UP;  // TODO: These constants need to be moved to constants.php
    public const HALF_DOWN  = PHP_ROUND_HALF_DOWN;
    public const HALF_EVEN  = PHP_ROUND_HALF_EVEN;
    public const HALF_ODD   = PHP_ROUND_HALF_ODD;
    public const ROUND_UP   = 5;
    public const ROUND_DOWN = 6;
    public const HALF_FIVE  = 7;

    public function __construct()
    {
        helper('language');
    }

    public static function get_rounding_options(): array
    {
        $class  = new ReflectionClass(__CLASS__);
        $result = [];

        foreach ($class->getConstants() as $key => $value) {
            $result[$value] = lang('Enum.' . strtolower($key));
        }

        return $result;
    }

    public static function get_rounding_code_name(int $code): string
    {
        if (empty($code)) {
            return lang('Common.unknown');
        }

        return Rounding_mode::get_rounding_options()[$code];
    }

    public static function get_html_rounding_options(): string
    {
        $x = '';

        foreach (Rounding_mode::get_rounding_options() as $option => $label) {
            $x .= '<option value="' . $option . '">' . $label . '</option>';
        }

        return $x;
    }

    public static function round_number(int $rounding_mode, float $amount, int $decimals): string
    {
        if ($rounding_mode == Rounding_mode::ROUND_UP || $rounding_mode == Rounding_mode::ROUND_DOWN) {
            $roundedTotal = self::directed($amount, $decimals, $rounding_mode == Rounding_mode::ROUND_UP, false);
        } elseif ($rounding_mode == Rounding_mode::HALF_FIVE) {
            $roundedTotal = round($amount / 5, $decimals, Rounding_mode::HALF_EVEN) * 5;
        } else {
            $roundedTotal = round($amount, $decimals, $rounding_mode);
        }

        return (string) $roundedTotal;
    }

    /**
     * Rounds up or down to a number of decimals: by the size of the amount, so that a return is rounded as the
     * sale it gives back was, or along the number line as the rounding of cash always went. The product is cut
     * to six decimals before it is rounded, for 1.1 times 100 is a little more than 110 in floating point and
     * would otherwise go up to 111.
     */
    public static function directed(float $amount, int $decimals, bool $up, bool $bySize = true): float
    {
        $factor = 10 ** $decimals;

        if (! $bySize) {
            $scaled = round($amount * $factor, 6);

            return ($up ? ceil($scaled) : floor($scaled)) / $factor;
        }

        $scaled = round(abs($amount) * $factor, 6);
        $whole  = $up ? ceil($scaled) : floor($scaled);

        return ($amount < 0 ? -$whole : $whole) / $factor;
    }
}
